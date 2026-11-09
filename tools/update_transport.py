"""Bounded HTTPS acquisition from one pinned GitHub publisher; metadata URLs are never executable inputs."""
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

from update_manifest import (APP_VERSION_RE, COMPONENTS, MANIFEST_NAME, MAX_ASSET, MAX_MANIFEST,
                             REPOSITORY_RE, SIGNATURE_NAME, UOS_VERSION_RE, UpdateError,
                             compatibility_error, newer, verify_manifest)

HOSTS = frozenset(('github.com', 'api.github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'))
CHUNK = 1024*1024


def trusted_url(url):
    try:
        parts = urllib.parse.urlsplit(url)
        return (parts.scheme == 'https' and parts.hostname in HOSTS and parts.port in (None, 443)
                and parts.username is None and parts.password is None and not parts.fragment)
    except (ValueError, TypeError):
        return False


class PinnedRedirect(urllib.request.HTTPRedirectHandler):
    max_redirections = 5
    max_repeats = 2

    def redirect_request(self, request, fp, code, message, headers, newurl):
        if not trusted_url(newurl): raise UpdateError('Update download attempted an untrusted redirect.')
        return super().redirect_request(request, fp, code, message, headers, newurl)


class ReleaseSource:
    def __init__(self, repository, key, opener=None, sleep=time.sleep):
        if type(repository) is not str or not re.fullmatch(REPOSITORY_RE, repository):
            raise UpdateError('Invalid trusted update repository.')
        self.repository, self.key, self.sleep = repository, Path(key), sleep
        self.opener = opener or urllib.request.build_opener(
            urllib.request.HTTPSHandler(context=ssl.create_default_context()), PinnedRedirect())

    def _open(self, url):
        if not trusted_url(url): raise UpdateError('Untrusted update URL.')
        request = urllib.request.Request(url, headers={'User-Agent': 'cmprovision-updater/1',
            'Accept': 'application/vnd.github+json' if url.startswith('https://api.github.com/') else 'application/octet-stream',
            'Accept-Encoding': 'identity'})
        return self.opener.open(request, timeout=20)

    def _retry(self, error, attempt):
        if isinstance(error, urllib.error.HTTPError):
            if error.code == 429 or error.code == 403 and error.headers.get('X-RateLimit-Remaining') == '0':
                raise UpdateError('GitHub rate limit reached; wait before checking again.') from error
            if error.code not in (408, 500, 502, 503, 504):
                raise UpdateError('The trusted update server rejected the request (HTTP '+str(error.code)+').') from error
        if attempt == 2:
            raise UpdateError('Could not finish the trusted release download; check the station connection and retry.') from error
        self.sleep(1+attempt)

    def _read(self, url, limit):
        for attempt in range(3):
            try:
                with self._open(url) as response:
                    raw = response.read(limit+1)
                if len(raw) > limit: raise UpdateError('Update metadata exceeds its size limit.')
                return raw
            except (urllib.error.URLError, TimeoutError, ConnectionError, OSError) as error:
                self._retry(error, attempt)
        raise UpdateError('Update metadata download did not complete.')

    def _json(self, url):
        try:
            return json.loads(self._read(url, 2*1024*1024))
        except (ValueError, RecursionError) as error:
            raise UpdateError('The release server returned invalid JSON.') from error

    def asset_url(self, tag, name):
        if not re.fullmatch(r'(?:v'+APP_VERSION_RE+r'|utility-os-'+UOS_VERSION_RE+r')', tag):
            raise UpdateError('Invalid release download tag.')
        if type(name) is not str or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._~+\-]{0,199}', name):
            raise UpdateError('Invalid release download filename.')
        return 'https://github.com/'+self.repository+'/releases/download/'+urllib.parse.quote(tag, safe='')+'/'+name

    @staticmethod
    def _version(tag, component):
        if type(tag) is not str or component not in COMPONENTS: return None
        pattern = r'v('+APP_VERSION_RE+r')' if component == 'application' else r'utility-os-('+UOS_VERSION_RE+r')'
        match = re.fullmatch(pattern, tag)
        return match[1] if match else None

    @staticmethod
    def _assets(release):
        items = release.get('assets')
        if type(items) is not list or len(items) > 100: raise UpdateError('Invalid release asset list.')
        result = {}
        for item in items:
            if type(item) is not dict or type(item.get('name')) is not str or item['name'] in result:
                raise UpdateError('Invalid or duplicate release asset.')
            result[item['name']] = item
        return result

    def _candidate(self, release, component, context):
        tag = release.get('tag_name')
        if (release.get('draft') is not False or release.get('prerelease') is not False
                or self._version(tag, component) is None):
            raise UpdateError('Only published stable component releases may be installed.')
        assets = self._assets(release)
        if MANIFEST_NAME not in assets or SIGNATURE_NAME not in assets:
            raise UpdateError('This release has no signed updater manifest.')
        for name, limit in ((MANIFEST_NAME, MAX_MANIFEST), (SIGNATURE_NAME, 8192)):
            info = assets[name]
            if info.get('state') != 'uploaded' or type(info.get('size')) is not int or not 0 < info['size'] <= limit:
                raise UpdateError('Signed release metadata is incomplete or too large.')
        raw = self._read(self.asset_url(tag, MANIFEST_NAME), MAX_MANIFEST)
        signature = self._read(self.asset_url(tag, SIGNATURE_NAME), 8192)
        if len(raw) != assets[MANIFEST_NAME]['size'] or len(signature) != assets[SIGNATURE_NAME]['size']:
            raise UpdateError('Signed release metadata size does not match.')
        value = verify_manifest(raw, signature, self.key, self.repository, tag)
        if value['component'] != component: raise UpdateError('Signed release component does not match.')
        expected = value['assets']+([value['sources']] if component == 'utility-os' else [])
        for asset in expected:
            info = assets.get(asset['name'], {})
            if (info.get('state') != 'uploaded' or type(info.get('size')) is not int or info['size'] != asset['size']
                    or info.get('digest') not in (None, 'sha256:'+asset['sha256'])):
                raise UpdateError('Release assets do not match the signed manifest.')
        return {'id': hashlib.sha256(raw).hexdigest(), 'manifest': value,
                'raw': base64.b64encode(raw).decode('ascii'), 'signature': base64.b64encode(signature).decode('ascii'),
                'incompatible': compatibility_error(value, context)}

    def release(self, tag, component, context):
        if self._version(tag, component) is None: raise UpdateError('Invalid component release tag.')
        release = self._json('https://api.github.com/repos/'+self.repository+'/releases/tags/'+urllib.parse.quote(tag, safe=''))
        if type(release) is not dict or release.get('tag_name') != tag:
            raise UpdateError('The selected release could not be resolved.')
        return self._candidate(release, component, context)

    def check(self, component, context):
        if component not in COMPONENTS: raise UpdateError('Unknown update component.')
        installed = context.get('cmprovision', '') if component == 'application' else context.get('utility_os_version', '0')
        eligible, tags = [], set()
        for page in (1, 2):
            releases = self._json('https://api.github.com/repos/'+self.repository+f'/releases?per_page=50&page={page}')
            if type(releases) is not list or len(releases) > 50: raise UpdateError('Invalid published release list.')
            for release in releases:
                if type(release) is not dict: raise UpdateError('Invalid published release entry.')
                version = self._version(release.get('tag_name'), component)
                if not version or release.get('draft') is not False or release.get('prerelease') is not False: continue
                if release['tag_name'] in tags: raise UpdateError('Duplicate component release tag.')
                tags.add(release['tag_name'])
                if newer(version, installed): eligible.append((version, release))
            if len(releases) < 50: break
        for version, release in sorted(eligible, key=lambda item: tuple(int(n) for n in item[0].split('.')), reverse=True):
            assets = self._assets(release)
            # Legacy published releases predate this contract. They remain viewable, never executable update inputs.
            if MANIFEST_NAME not in assets or SIGNATURE_NAME not in assets: continue
            return self._candidate(release, component, context)
        return None

    def fetch(self, tag, asset, destination, progress):
        if (type(asset) is not dict or type(asset.get('size')) is not int or not 0 < asset['size'] <= MAX_ASSET
                or type(asset.get('sha256')) is not str or not re.fullmatch(r'[0-9a-f]{64}', asset['sha256'])):
            raise UpdateError('Invalid selected download asset.')
        url = self.asset_url(tag, asset.get('name'))
        destination = Path(destination)
        if destination.exists() or destination.is_symlink() or destination.parent.is_symlink():
            raise UpdateError('The download destination is already occupied or unsafe.')
        for attempt in range(3):
            temporary = destination.parent/('.download-'+uuid.uuid4().hex+'.part')
            try:
                if progress(0, asset['size']) is False: raise UpdateError('Update download canceled.')
                with self._open(url) as response:
                    length = response.headers.get('Content-Length')
                    if length is not None and (not str(length).isdigit() or int(length) != asset['size']):
                        raise UpdateError('Download Content-Length does not match the signed asset.')
                    done, digest = 0, hashlib.sha256()
                    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
                    with os.fdopen(fd, 'wb') as stream:
                        while True:
                            chunk = response.read(min(CHUNK, asset['size']-done+1))
                            if not chunk: break
                            done += len(chunk)
                            if done > asset['size']: raise UpdateError('Download exceeds its signed size.')
                            digest.update(chunk)
                            stream.write(chunk)
                            if progress(done, asset['size']) is False: raise UpdateError('Update download canceled.')
                        stream.flush()
                        os.fsync(stream.fileno())
                if done != asset['size'] or digest.hexdigest() != asset['sha256']:
                    raise UpdateError('Downloaded file does not match its signed size or SHA256.')
                os.replace(temporary, destination)
                directory = os.open(destination.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
                try: os.fsync(directory)
                finally: os.close(directory)
                return destination
            except (urllib.error.URLError, TimeoutError, ConnectionError, OSError) as error:
                self._retry(error, attempt)
            finally:
                if temporary.exists(): temporary.unlink()
        raise UpdateError('Download did not complete.')
