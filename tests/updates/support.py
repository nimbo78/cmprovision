"""Signed release fixtures; generated private keys never leave temporary test directories."""
import json
from pathlib import Path
import subprocess
import sys

TOOLS = Path(__file__).resolve().parents[2]/'tools'
sys.path.insert(0, str(TOOLS))
REPOSITORY = 'nimbo78/cmprovision'
CONTEXT = {'debian': '13', 'architecture': 'arm64', 'php': '8.4.27',
           'cmprovision': '2.27.0~dev1', 'updater_protocol': 1, 'fpms_installed': True}


def manifest(component='application', version=None):
    version = version or ('2.28.0' if component == 'application' else '2026.11.1')
    result = {'schema': 1, 'component': component, 'version': version,
              'tag': ('v' if component == 'application' else 'utility-os-')+version,
              'repository': REPOSITORY, 'source_sha': 'f'*40, 'published_at': '2026-11-09T00:00:00Z',
              'notes': 'A checked release.',
              'compatibility': {'debian': ['13'], 'architectures': ['arm64', 'armhf', 'amd64'],
                                'php': {'8.3': '8.3.36', '8.4': '8.4.27', '8.5': '8.5.12'},
                                'min_cmprovision': '2.27.0~dev1', 'updater_protocol': 1}}
    if component == 'application':
        result['assets'] = [{'name': f'cmprovision4_{version}_all.deb', 'size': 1024, 'sha256': 'a'*64},
                            {'name': f'fpms-lite_{version}_all.deb', 'size': 32, 'sha256': 'b'*64}]
    else:
        result['assets'] = [{'name': f'utility-os-{version}.tar.gz', 'size': 1024, 'sha256': 'a'*64}]
        result['sources'] = {'name': f'utility-os-{version}-legal-info.tar.gz', 'size': 2048, 'sha256': 'b'*64}
        result['utility'] = {'architecture': 'aarch64', 'bits': 64, 'kernel': '6.18.50-cmprovision'}
    return result


def encode(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False)+'\n').encode()


def keypair(directory):
    directory = Path(directory)
    directory.mkdir(parents=True, exist_ok=True)
    subprocess.run(['minisign', '-G', '-W', '-p', str(directory/'key.pub'), '-s', str(directory/'key.sec')],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    return directory/'key.pub', directory/'key.sec'


def signed(directory, value):
    directory = Path(directory)
    public, private = keypair(directory)
    raw = encode(value)
    payload = directory/'manifest.json'
    payload.write_bytes(raw)
    subprocess.run(['minisign', '-S', '-W', '-s', str(private), '-m', str(payload)],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    return raw, (directory/'manifest.json.minisig').read_bytes(), public
