"""Versioned, signed update contract shared by the publisher and station executor."""
from datetime import datetime
import json
from pathlib import Path
import re
import stat
import subprocess
import tempfile

SCHEMA = 1
PROTOCOL = 1
MANIFEST_NAME = 'cmprovision-update.json'
SIGNATURE_NAME = MANIFEST_NAME+'.minisig'
MAX_MANIFEST = 64*1024
MAX_ASSET = 512*1024*1024
COMPONENTS = ('application', 'utility-os')
REPOSITORY_RE = r'[A-Za-z0-9][A-Za-z0-9_.-]{0,99}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}'
APP_VERSION_RE = r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)'
UOS_VERSION_RE = r'[0-9]{4}\.(?:0[1-9]|1[0-2])(?:\.(?:0|[1-9][0-9]*))?'
DEB_VERSION_RE = r'[0-9][0-9A-Za-z.+:~\-]{0,127}'
SHA_RE = r'[0-9a-f]{64}'


class UpdateError(ValueError):
    """A safe operator-facing rejection. Never include credentials or private file contents."""


def canonical_bytes(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False)+'\n').encode('utf-8')


def _require(condition, message):
    if not condition:
        raise UpdateError(message)


def _keys(value, keys):
    _require(type(value) is dict and set(value) == set(keys), 'Invalid update manifest fields.')


def _match(pattern, value):
    return type(value) is str and re.fullmatch(pattern, value) is not None


def _unique(pairs):
    result = {}
    for key, value in pairs:
        _require(key not in result, 'Duplicate update manifest field.')
        result[key] = value
    return result


def _invalid_constant(unused):
    raise UpdateError('Invalid JSON number.')


def _asset(value, maximum=MAX_ASSET):
    _keys(value, ('name', 'size', 'sha256'))
    _require(_match(r'[A-Za-z0-9][A-Za-z0-9._~+\-]{0,199}', value['name']), 'Invalid update asset filename.')
    _require(type(value['size']) is int and 0 < value['size'] <= maximum, 'Invalid update asset size.')
    _require(_match(SHA_RE, value['sha256']), 'Invalid update asset SHA256.')


def parse_manifest(raw, repository, tag=None):
    _require(type(raw) is bytes and 0 < len(raw) <= MAX_MANIFEST, 'Update manifest exceeds its size limit.')
    _require(_match(REPOSITORY_RE, repository), 'Invalid update publisher.')
    try:
        value = json.loads(raw.decode('utf-8'), object_pairs_hook=_unique, parse_constant=_invalid_constant)
    except (UnicodeError, ValueError, RecursionError) as error:
        raise UpdateError('Invalid update manifest JSON.') from error
    _require(type(value) is dict, 'Invalid update manifest.')
    component = value.get('component')
    _require(component in COMPONENTS, 'Unknown update component.')
    fields = {'schema', 'component', 'version', 'tag', 'repository', 'source_sha', 'published_at',
              'notes', 'compatibility', 'assets'}
    if component == 'utility-os': fields |= {'utility', 'sources'}
    _keys(value, fields)
    _require(type(value['schema']) is int and value['schema'] == SCHEMA, 'Unsupported update manifest schema.')
    _require(value['repository'] == repository, 'Update publisher does not match the trusted repository.')
    pattern = APP_VERSION_RE if component == 'application' else UOS_VERSION_RE
    _require(_match(pattern, value['version']), 'Invalid stable update version.')
    expected_tag = ('v' if component == 'application' else 'utility-os-')+value['version']
    _require(value['tag'] == expected_tag and (tag is None or tag == expected_tag), 'Update release tag does not match.')
    _require(_match(r'[0-9a-f]{40}', value['source_sha']), 'Invalid update source commit.')
    _require(_match(r'[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z', value['published_at']),
             'Invalid update publication date.')
    try:
        datetime.fromisoformat(value['published_at'].replace('Z', '+00:00'))
    except ValueError as error:
        raise UpdateError('Invalid update publication date.') from error
    _require(type(value['notes']) is str and len(value['notes']) <= 8192
             and not re.search(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]', value['notes']), 'Invalid release notes.')
    try:
        value['notes'].encode('utf-8')
    except UnicodeError as error:
        raise UpdateError('Invalid release notes encoding.') from error
    compatibility = value['compatibility']
    _keys(compatibility, ('debian', 'architectures', 'php', 'min_cmprovision', 'updater_protocol'))
    for key in ('debian', 'architectures'):
        items = compatibility[key]
        _require(type(items) is list and 0 < len(items) <= 8 and all(type(item) is str for item in items)
                 and len(set(items)) == len(items), 'Invalid update compatibility list.')
    _require(all(_match(r'[1-9][0-9]{0,2}', item) for item in compatibility['debian']), 'Invalid Debian compatibility.')
    _require(set(compatibility['architectures']) <= {'arm64', 'armhf', 'amd64'}, 'Invalid host architecture compatibility.')
    _require(type(compatibility['php']) is dict and 0 < len(compatibility['php']) <= 8, 'Invalid PHP compatibility.')
    for branch, version in compatibility['php'].items():
        _require(_match(r'[0-9]+\.[0-9]+', branch) and _match(r'[0-9]+\.[0-9]+\.[0-9]+', version)
                 and version.startswith(branch+'.'), 'Invalid PHP compatibility floor.')
    _require(_match(DEB_VERSION_RE, compatibility['min_cmprovision']), 'Invalid minimum cmprovision version.')
    _require(type(compatibility['updater_protocol']) is int and 1 <= compatibility['updater_protocol'] <= 1000,
             'Invalid updater compatibility protocol.')
    assets = value['assets']
    _require(type(assets) is list and len(assets) == (2 if component == 'application' else 1), 'Incomplete update assets.')
    for asset in assets: _asset(asset)
    expected = ({f'cmprovision4_{value["version"]}_all.deb', f'fpms-lite_{value["version"]}_all.deb'}
                if component == 'application' else {f'utility-os-{value["version"]}.tar.gz'})
    _require({asset['name'] for asset in assets} == expected and len(expected) == len(assets), 'Update asset set does not match.')
    if component == 'utility-os':
        _asset(value['sources'], maximum=32*1024**3)
        _require(value['sources']['name'] == f'utility-os-{value["version"]}-legal-info.tar.gz', 'Missing matching Utility OS sources.')
        _keys(value['utility'], ('architecture', 'bits', 'kernel'))
        _require(value['utility']['architecture'] == 'aarch64' and type(value['utility']['bits']) is int
                 and value['utility']['bits'] == 64 and _match(r'[A-Za-z0-9._+\-]{1,100}', value['utility']['kernel']),
                 'Unsupported Utility OS architecture or kernel.')
    return value


def verify_manifest(raw, signature, key, repository, tag=None):
    value = parse_manifest(raw, repository, tag)
    _require(type(signature) is bytes and 0 < len(signature) <= 8192, 'Invalid update signature size.')
    key = Path(key)
    try:
        _require(stat.S_ISREG(key.lstat().st_mode), 'Update trust key is not a regular file.')
        with tempfile.TemporaryDirectory(prefix='cmprovision-signature-') as folder:
            payload, sig = Path(folder)/'manifest.json', Path(folder)/'manifest.minisig'
            payload.write_bytes(raw)
            sig.write_bytes(signature)
            result = subprocess.run(['/usr/bin/minisign', '-V', '-q', '-p', str(key), '-m', str(payload), '-x', str(sig)],
                                    stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                    timeout=10, env={'PATH': '/usr/bin:/bin', 'LC_ALL': 'C.UTF-8'})
    except (OSError, subprocess.TimeoutExpired) as error:
        raise UpdateError('Update signature verifier or trust key is unavailable.') from error
    _require(result.returncode == 0, 'Update signature verification failed.')
    return value


def newer(version, installed):
    _require(_match(DEB_VERSION_RE, version) and _match(DEB_VERSION_RE, installed), 'Invalid Debian package version.')
    result = subprocess.run(['/usr/bin/dpkg', '--compare-versions', version, 'gt', installed],
                            stdin=subprocess.DEVNULL, capture_output=True, timeout=5)
    _require(result.returncode in (0, 1), 'Could not compare Debian package versions.')
    return result.returncode == 0


def compatibility_error(value, context):
    required = value['compatibility']
    if context.get('debian') not in required['debian']: return 'This release requires a different Debian version.'
    if context.get('architecture') not in required['architectures']: return 'This release does not support this host architecture.'
    php = context.get('php', '')
    if not _match(r'[0-9]+\.[0-9]+\.[0-9]+', php): return 'Could not determine the installed PHP version.'
    branch = '.'.join(php.split('.')[:2])
    floor = required['php'].get(branch)
    if floor is None or tuple(map(int, php.split('.'))) < tuple(map(int, floor.split('.'))):
        return 'This release requires a supported, patched PHP version.'
    try:
        if newer(required['min_cmprovision'], context.get('cmprovision', '')):
            return 'Update cmprovision before installing this release.'
    except UpdateError:
        return 'Could not determine the installed cmprovision package version.'
    if type(context.get('updater_protocol')) is not int or context['updater_protocol'] < required['updater_protocol']:
        return 'This release requires a newer updater protocol.'
    return None
