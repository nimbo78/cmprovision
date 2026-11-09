import hashlib
import io
import json
from pathlib import Path
import shutil
import tempfile
import unittest
import urllib.error
import urllib.request

from support import CONTEXT, REPOSITORY, encode, manifest, signed
from update_manifest import MANIFEST_NAME, SIGNATURE_NAME, UpdateError
from update_transport import PinnedRedirect, ReleaseSource


class Response(io.BytesIO):
    def __init__(self, content, headers=None):
        super().__init__(content)
        self.headers = headers or {}


class Opener:
    def __init__(self, responses):
        self.responses, self.requests = responses, []

    def open(self, request, timeout=0):
        url = request.full_url
        self.requests.append(url)
        value = self.responses.get(url)
        if isinstance(value, Exception): raise value
        if value is None: raise urllib.error.URLError('fixture URL missing')
        return Response(value)


class TransportTest(unittest.TestCase):
    def setUp(self):
        if not shutil.which('minisign'): self.skipTest('minisign required')
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.value = manifest()
        self.raw, self.signature, self.key = signed(self.base/'signing', self.value)
        self.list_url = 'https://api.github.com/repos/'+REPOSITORY+'/releases?per_page=50&page=1'
        self.asset_url = 'https://github.com/'+REPOSITORY+'/releases/download/'+self.value['tag']+'/'

    def release(self):
        assets = [{'name': MANIFEST_NAME, 'state': 'uploaded', 'size': len(self.raw)},
                  {'name': SIGNATURE_NAME, 'state': 'uploaded', 'size': len(self.signature)}]
        assets += [dict(asset, state='uploaded', digest='sha256:'+asset['sha256']) for asset in self.value['assets']]
        return {'tag_name': self.value['tag'], 'draft': False, 'prerelease': False, 'assets': assets}

    def source(self, releases=None):
        opener = Opener({self.list_url: encode(releases if releases is not None else [self.release()]),
                         self.asset_url+MANIFEST_NAME: self.raw, self.asset_url+SIGNATURE_NAME: self.signature})
        source = ReleaseSource(REPOSITORY, self.key, opener=opener, sleep=lambda delay: None)
        return source, opener

    def test_only_exact_trusted_release_paths_are_used_and_signature_is_verified(self):
        release = self.release()
        for asset in release['assets']: asset['browser_download_url'] = 'https://evil.invalid/payload'
        source, opener = self.source([release])
        candidate = source.check('application', CONTEXT)
        self.assertEqual(self.value, candidate['manifest'])
        self.assertEqual(hashlib.sha256(self.raw).hexdigest(), candidate['id'])
        self.assertIsNone(candidate['incompatible'])
        self.assertEqual([self.list_url, self.asset_url+MANIFEST_NAME, self.asset_url+SIGNATURE_NAME], opener.requests)

    def test_drafts_prereleases_unsigned_and_older_releases_are_not_candidates(self):
        draft = dict(self.release(), draft=True)
        preview = dict(self.release(), prerelease=True)
        unsigned = dict(self.release(), assets=[])
        source, opener = self.source([draft, preview, unsigned])
        self.assertIsNone(source.check('application', CONTEXT))
        self.assertEqual([self.list_url], opener.requests)
        source, _ = self.source()
        self.assertIsNone(source.check('application', dict(CONTEXT, cmprovision='2.29.0')))

    def test_bad_signature_or_mismatched_asset_metadata_is_visible_failure(self):
        source, opener = self.source()
        opener.responses[self.asset_url+SIGNATURE_NAME] = b'bad signature'
        with self.assertRaises(UpdateError): source.check('application', CONTEXT)
        release = self.release()
        release['assets'][-1]['size'] += 1
        source, _ = self.source([release])
        with self.assertRaises(UpdateError): source.check('application', CONTEXT)

    def test_incompatible_release_is_shown_without_becoming_installable(self):
        source, _ = self.source()
        candidate = source.check('application', dict(CONTEXT, php='8.4.26'))
        self.assertEqual(self.value, candidate['manifest'])
        self.assertTrue(candidate['incompatible'])

    def test_stream_reports_real_bytes_and_does_not_commit_corrupt_or_oversized_file(self):
        source, opener = self.source()
        data = b'package bytes'*1024
        asset = {'name': self.value['assets'][0]['name'], 'size': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
        opener.responses[self.asset_url+asset['name']] = data
        progress = []
        target = self.base/'download.deb'
        source.fetch(self.value['tag'], asset, target, lambda done, total: progress.append((done, total)))
        self.assertEqual(data, target.read_bytes())
        self.assertEqual((len(data), len(data)), progress[-1])
        for bad in [data+b'x', b'corrupt']:
            target.unlink(missing_ok=True)
            opener.responses[self.asset_url+asset['name']] = bad
            with self.assertRaises(UpdateError): source.fetch(self.value['tag'], asset, target, lambda *args: None)
            self.assertFalse(target.exists())

    def test_network_failures_are_bounded_and_cancellation_leaves_no_ready_file(self):
        source, opener = self.source()
        asset = self.value['assets'][0]
        opener.responses[self.asset_url+asset['name']] = urllib.error.URLError('offline')
        with self.assertRaises(UpdateError): source.fetch(self.value['tag'], asset, self.base/'offline', lambda *args: None)
        self.assertEqual(3, len(opener.requests))
        opener.responses[self.asset_url+asset['name']] = b'x'*asset['size']
        with self.assertRaises(UpdateError):
            source.fetch(self.value['tag'], asset, self.base/'canceled', lambda *args: False)
        self.assertFalse((self.base/'canceled').exists())

    def test_redirects_never_leave_https_github_asset_hosts(self):
        handler = PinnedRedirect()
        original = urllib.request.Request(self.asset_url+MANIFEST_NAME)
        for target in ['http://github.com/x', 'https://evil.invalid/file', 'https://github.com:444/x',
                       'https://user:password@github.com/x', 'file:///etc/passwd']:
            with self.subTest(target=target), self.assertRaises(UpdateError):
                handler.redirect_request(original, None, 302, 'redirect', {}, target)
        result = handler.redirect_request(original, None, 302, 'redirect', {},
                                          'https://release-assets.githubusercontent.com/file?opaque=signed')
        self.assertEqual('release-assets.githubusercontent.com', result.host)


if __name__ == '__main__': unittest.main()
