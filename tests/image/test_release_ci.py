"""Release CI refuses unsafe inputs and incomplete or mismatched artifacts."""
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('image_release_ci', ROOT / 'image/ci/release.py')
ci = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ci)
VERSION = '2.26.0'
TAG = 'v' + VERSION
APP = 'a' * 64
FPMS = 'b' * 64


def release():
    return {'id': 42, 'tag_name': TAG, 'draft': True, 'assets': [
        {'name': 'cmprovision4_2.26.0_all.deb', 'state': 'uploaded', 'digest': 'sha256:' + APP},
        {'name': 'fpms-lite_2.26.0_all.deb', 'state': 'uploaded', 'digest': 'sha256:' + FPMS},
    ]}


def upload_fixture(directory):
    bundle = directory / 'assets'
    bundle.mkdir()
    rows = []
    for name in ci.asset_names(VERSION):
        body = name.encode()
        (bundle / name).write_bytes(body)
        rows.append(f'{hashlib.sha256(body).hexdigest()}  {name}\n')
    (bundle / 'SHA256SUMS').write_text(''.join(rows))
    checks = ci.verify_bundle(bundle, VERSION)
    args = SimpleNamespace(directory=directory, repository='test/repo', tag=TAG,
                           source_sha='c' * 40,
                           package_sha256=checks['cmprovision4_2.26.0_all.deb'],
                           fpms_sha256=checks['fpms-lite_2.26.0_all.deb'])
    state = vars(args).copy()
    del state['directory']
    state['release_id'] = 42
    (directory / 'state.json').write_text(json.dumps(state))
    value = {'id': 42, 'tag_name': TAG, 'draft': True, 'html_url': 'https://example.test/release',
             'assets': [{'name': name, 'state': 'uploaded', 'digest': 'sha256:' + checks[name],
                         'size': (bundle / name).stat().st_size}
                        for name in ('cmprovision4_2.26.0_all.deb', 'fpms-lite_2.26.0_all.deb')]}
    return args, checks, value


class ReleaseCiTest(unittest.TestCase):
    def test_valid_request(self):
        self.assertEqual(VERSION, ci.validate_request(TAG, APP, FPMS))

    def test_unsafe_or_nonrelease_tags_are_refused(self):
        for tag in ('main', '../v2.26.0', 'v2.26.0;id', 'v2.26.0\nextra', 'v2.26.0-rc1'):
            with self.subTest(tag=tag), self.assertRaises(ValueError):
                ci.validate_request(tag, APP, FPMS)

    def test_invalid_independent_hashes_are_refused(self):
        for digest in ('', 'x' * 64, 'a' * 63, 'a' * 64 + '\n'):
            with self.subTest(digest=digest), self.assertRaises(ValueError):
                ci.validate_request(TAG, digest, FPMS)

    def test_published_release_cannot_be_updated(self):
        value = release()
        value['draft'] = False
        with self.assertRaises(ValueError):
            ci.require_draft(value, TAG, APP, FPMS)

    def test_published_packages_can_be_verified_for_a_read_only_rebuild(self):
        value = release()
        value['draft'] = False
        self.assertEqual(2, len(ci.require_packages(value, TAG, APP, FPMS)))

    def test_wrong_release_cannot_be_updated(self):
        value = release()
        value['tag_name'] = 'v2.25.0'
        with self.assertRaises(ValueError):
            ci.require_draft(value, TAG, APP, FPMS)

    def test_package_mismatch_or_missing_server_digest_is_refused(self):
        for digest in ('sha256:' + 'c' * 64, None):
            value = release()
            value['assets'][0]['digest'] = digest
            with self.subTest(digest=digest), self.assertRaises(ValueError):
                ci.require_draft(value, TAG, APP, FPMS)

    def test_duplicate_or_incomplete_package_asset_is_refused(self):
        value = release()
        value['assets'].append(dict(value['assets'][0]))
        with self.assertRaises(ValueError):
            ci.require_draft(value, TAG, APP, FPMS)
        value = release()
        value['assets'][0]['state'] = 'starter'
        with self.assertRaises(ValueError):
            ci.require_draft(value, TAG, APP, FPMS)

    def test_canonical_manifest_is_accepted(self):
        checks = {name: APP for name in ci.asset_names(VERSION)}
        text = ''.join(f'{digest}  ./{name}\n' for name, digest in checks.items())
        self.assertEqual(checks, ci.parse_sums(text, VERSION))

    def test_manifest_traversal_duplicates_and_missing_files_are_refused(self):
        rows = [f'{APP}  {name}\n' for name in ci.asset_names(VERSION)]
        for text in (''.join(rows) + rows[0], ''.join(rows[1:]),
                     ''.join(rows) + f'{APP}  ../secret\n'):
            with self.subTest(text=text), self.assertRaises(ValueError):
                ci.parse_sums(text, VERSION)

    def test_bundle_bytes_are_checked_including_manifest(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            rows = []
            for name in ci.asset_names(VERSION):
                body = name.encode()
                (root / name).write_bytes(body)
                rows.append(f'{hashlib.sha256(body).hexdigest()}  {name}\n')
            (root / 'SHA256SUMS').write_text(''.join(rows))
            checks = ci.verify_bundle(root, VERSION)
            self.assertIn('SHA256SUMS', checks)
            (root / 'cmprovision4_2.26.0_all.deb').write_bytes(b'tampered')
            with self.assertRaises(ValueError):
                ci.verify_bundle(root, VERSION)

    def test_uploaded_server_set_and_digests_must_match(self):
        checks = {name: APP for name in ci.asset_names(VERSION) | {'SHA256SUMS'}}
        assets = [{'name': name, 'state': 'uploaded', 'digest': 'sha256:' + digest}
                  for name, digest in checks.items()]
        ci.verify_uploaded({'assets': assets}, checks)
        for broken in (assets[:-1], assets + [dict(assets[0])],
                       [dict(asset, digest=None) for asset in assets]):
            with self.subTest(assets=broken), self.assertRaises(ValueError):
                ci.verify_uploaded({'assets': broken}, checks)

    def test_attachment_never_clobbers_existing_assets(self):
        with tempfile.TemporaryDirectory() as tmp:
            args, checks, value = upload_fixture(Path(tmp))
            def uploaded(command, **kwargs):
                self.assertNotIn('--clobber', command, 'CLI overwrite can race with publication')
                name = Path(command[4]).name
                value['assets'].append({'name': name, 'state': 'uploaded',
                                        'digest': 'sha256:' + checks[name],
                                        'size': (args.directory / 'assets' / name).stat().st_size})
            with patch.object(ci, 'release_for_tag', return_value=value), \
                    patch.object(ci, 'tag_commit', return_value=args.source_sha), \
                    patch.object(ci.subprocess, 'run', side_effect=uploaded):
                ci.upload(args, VERSION)
            ci.verify_uploaded(value, checks)

    def test_conflicting_existing_file_stops_before_any_upload(self):
        with tempfile.TemporaryDirectory() as tmp:
            args, checks, value = upload_fixture(Path(tmp))
            value['assets'].append({'name': 'image-uncompressed.sha256', 'state': 'uploaded',
                                    'digest': 'sha256:' + 'd' * 64, 'size': 1})
            with patch.object(ci, 'release_for_tag', return_value=value), \
                    patch.object(ci, 'tag_commit', return_value=args.source_sha), \
                    patch.object(ci.subprocess, 'run') as write:
                with self.assertRaisesRegex(ValueError, 'Existing release asset differs'):
                    ci.upload(args, VERSION)
                write.assert_not_called()

    def test_published_release_stops_attachment_before_any_upload(self):
        with tempfile.TemporaryDirectory() as tmp:
            args, checks, value = upload_fixture(Path(tmp))
            value['draft'] = False
            with patch.object(ci, 'release_for_tag', return_value=value), \
                    patch.object(ci, 'tag_commit', return_value=args.source_sha), \
                    patch.object(ci.subprocess, 'run') as write:
                with self.assertRaises(ValueError):
                    ci.upload(args, VERSION)
                write.assert_not_called()


if __name__ == '__main__':
    unittest.main()
