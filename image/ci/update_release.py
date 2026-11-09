#!/usr/bin/env python3
"""Create and verify the signed, immutable component metadata consumed by stations."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import tarfile
import tempfile

sys.path.insert(0, str(Path(__file__).resolve().parents[2]/'tools'))
from update_manifest import canonical_bytes, parse_manifest, verify_manifest, MANIFEST_NAME, SIGNATURE_NAME
from update_utility import verify_tree


def descriptor(path):
    if path.is_symlink() or not path.is_file(): raise ValueError('Missing regular release asset: '+path.name)
    with path.open('rb') as stream: digest = hashlib.file_digest(stream, 'sha256').hexdigest()
    return {'name': path.name, 'size': path.stat().st_size, 'sha256': digest}


def base(component, version, repository, source, timestamp, notes):
    if timestamp.isdigit(): timestamp = datetime.fromtimestamp(int(timestamp), timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    return {'schema': 1, 'component': component, 'version': version,
        'tag': ('v' if component == 'application' else 'utility-os-')+version,
        'repository': repository, 'source_sha': source, 'published_at': timestamp, 'notes': notes[:8192],
        'compatibility': {'debian': ['13'], 'architectures': ['arm64', 'armhf', 'amd64'],
            'php': {'8.3': '8.3.36', '8.4': '8.4.27', '8.5': '8.5.12'},
            'min_cmprovision': '2.27.0~dev2', 'updater_protocol': 1}}


def sign(directory, value, private, public):
    raw = canonical_bytes(value)
    parse_manifest(raw, value['repository'], value['tag'])
    with tempfile.TemporaryDirectory(prefix='cmprovision-sign-') as temporary:
        payload = Path(temporary)/MANIFEST_NAME
        payload.write_bytes(raw)
        try:
            subprocess.run(['minisign', '-S', '-W', '-s', str(private), '-m', str(payload),
                '-c', 'cmprovision signed stable update', '-t', 'source:'+value['source_sha']],
                check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
            signature = payload.with_name(SIGNATURE_NAME).read_bytes()
            verify_manifest(raw, signature, public, value['repository'], value['tag'])
        except (OSError, subprocess.SubprocessError) as error: raise ValueError('A matching publisher signing key is required.') from error
        (directory/MANIFEST_NAME).write_bytes(raw)
        (directory/SIGNATURE_NAME).write_bytes(signature)


def application(directory, version, repository, source, timestamp, notes, private, public):
    value = base('application', version, repository, source, timestamp, notes)
    assets = []
    for package in ('cmprovision4', 'fpms-lite'):
        path = directory/f'{package}_{version}_all.deb'
        info = subprocess.check_output(['dpkg-deb', '-f', str(path), 'Package', 'Version', 'Architecture'], text=True)
        if info.splitlines() != [f'Package: {package}', f'Version: {version}', 'Architecture: all']:
            raise ValueError('Package contents do not match the signed release.')
        assets.append(descriptor(path))
    value['assets'] = assets
    sign(directory, value, private, public)


def verify(directory, repository, tag, source, public):
    try: value = verify_manifest((directory/MANIFEST_NAME).read_bytes(), (directory/SIGNATURE_NAME).read_bytes(), public, repository, tag)
    except OSError as error: raise ValueError('The signed update metadata is missing.') from error
    if value['source_sha'] != source: raise ValueError('Signed update source does not match the audited build.')
    for asset in [*value['assets'], *([value['sources']] if 'sources' in value else [])]:
        if descriptor(directory/asset['name']) != asset: raise ValueError('Signed update asset contents changed.')
    return value


def utility(directory, boot, legal, repository, source, timestamp, notes, private, public):
    inventory = verify_tree(boot, owner=boot.stat().st_uid)
    version = inventory['version']
    value = base('utility-os', version, repository, source, timestamp, notes)
    kernel = re.search(r'[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9._-]+)?', inventory.get('kernel', ''))
    if not kernel: raise ValueError('Utility OS kernel is missing.')
    value['utility'] = {'architecture': 'aarch64', 'bits': 64, 'kernel': kernel[0]}
    complete = json.loads((legal/'source-complete.json').read_bytes())
    if not complete.get('files') or not (legal/'cmprovision-build-recipe.tar.gz').is_file():
        raise ValueError('The matching complete legal-info sources are required.')
    for item in complete['files']:
        path = legal/item['file']
        if not path.resolve().is_relative_to(legal.resolve()) or descriptor(path)['sha256'] != item['sha256']:
            raise ValueError('Legal-info source completeness verification failed.')
    directory.mkdir(parents=True, exist_ok=True)
    archive = directory/f'utility-os-{version}.tar.gz'
    sources = directory/f'utility-os-{version}-legal-info.tar.gz'
    with tarfile.open(archive, 'w:gz') as output: output.add(boot, arcname='boot', recursive=True)
    with tarfile.open(sources, 'w:gz') as output: output.add(legal, arcname='legal-info', recursive=True)
    value.update(assets=[descriptor(archive)], sources=descriptor(sources))
    sign(directory, value, private, public)
    return value


def sums(directory):
    values = [descriptor(path) for path in sorted(directory.iterdir()) if path.name != 'SHA256SUMS']
    (directory/'SHA256SUMS').write_text(''.join(f'{value["sha256"]}  {value["name"]}\n' for value in values))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('component', choices=('application', 'utility-os', 'verify'))
    for name in ('directory', 'public-key'): parser.add_argument('--'+name, type=Path, required=True)
    for name in ('repository', 'source', 'timestamp'): parser.add_argument('--'+name, required=True)
    parser.add_argument('--private-key', type=Path)
    parser.add_argument('--version')
    parser.add_argument('--tag')
    parser.add_argument('--notes-file', type=Path)
    parser.add_argument('--boot', type=Path)
    parser.add_argument('--legal', type=Path)
    args = parser.parse_args()
    if args.component == 'verify': verify(args.directory, args.repository, args.tag, args.source, args.public_key); return
    notes = args.notes_file.read_text() if args.notes_file else 'Verified stable component release. See the release page for details.'
    if args.component == 'application':
        application(args.directory, args.version, args.repository, args.source, args.timestamp, notes, args.private_key, args.public_key)
    else: utility(args.directory, args.boot, args.legal, args.repository, args.source, args.timestamp, notes, args.private_key, args.public_key)
    sums(args.directory)


if __name__ == '__main__': main()
