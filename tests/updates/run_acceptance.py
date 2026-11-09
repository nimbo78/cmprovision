#!/usr/bin/env python3
"""Run only INSIDE the dedicated container: real dpkg/snapshot/HTTP, injected health failure."""
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

sys.path.insert(0, '/usr/lib/cmprovision')
from update_manifest import UpdateError, canonical_bytes
from update_store import UpdateStore
from update_system import LinuxSystem
from update_worker import Worker


def command(*args): return subprocess.check_output(args, text=True).strip()
def enabled(name): return subprocess.run(['systemctl', 'is-enabled', name], capture_output=True, text=True).stdout.strip()


def run():
    if Path('/etc/hostname').read_text().strip() != 'cmprovision-update-bench' or not Path('/fixture/usbboot-shim.deb').exists():
        raise RuntimeError('Refusing to run outside the dedicated disposable container.')
    fixture = Path('/fixture')
    system = LinuxSystem(UpdateStore())
    original = system.package('cmprovision4')
    system.command(['/usr/bin/systemctl', 'start', 'nginx', 'php8.4-fpm', 'cmprovision-network-init'])
    system.http_health({})
    for name in ('cmprovision-dnsmasq', 'cmprovision-rpiboot'):
        assert enabled(name) == 'masked'
    package = next(fixture.glob('cmprovision4_*.deb'))
    target = fixture/'candidate'
    subprocess.run(['dpkg-deb', '-R', str(package), str(target)], check=True)
    control = target/'DEBIAN/control'
    text = control.read_text().replace('Version: '+original+'\n', 'Version: 2.28.0\n')
    assert text != control.read_text()
    control.write_text(text)
    (target/'var/lib/cmprovision/update-acceptance-version').write_text('2.28.0\n')
    candidate = fixture/'cmprovision4_2.28.0_all.deb'
    subprocess.run(['dpkg-deb', '--build', str(target), str(candidate)], check=True)
    key, public = fixture/'key.sec', fixture/'key.pub'
    subprocess.run(['minisign', '-G', '-W', '-s', str(key), '-p', str(public)], check=True, stdout=subprocess.DEVNULL)
    asset = {'name': candidate.name, 'size': candidate.stat().st_size,
             'sha256': hashlib.sha256(candidate.read_bytes()).hexdigest()}
    value = {'schema': 1, 'component': 'application', 'version': '2.28.0', 'tag': 'v2.28.0',
        'repository': 'nimbo78/cmprovision', 'source_sha': 'f'*40, 'published_at': '2026-11-09T00:00:00Z',
        'notes': 'Disposable package acceptance fixture, never published.',
        'compatibility': {'debian': ['13'], 'architectures': ['amd64'], 'php': {'8.4': '8.4.27'},
            'min_cmprovision': original, 'updater_protocol': 1},
        'assets': [asset, {'name': 'fpms-lite_2.28.0_all.deb', 'size': 1, 'sha256': 'a'*64}]}
    raw = canonical_bytes(value)
    metadata = fixture/'manifest.json'
    metadata.write_bytes(raw)
    subprocess.run(['minisign', '-S', '-W', '-s', str(key), '-m', str(metadata)], check=True, stdout=subprocess.DEVNULL)
    envelope = {'id': hashlib.sha256(raw).hexdigest(), 'manifest': value, 'raw': base64.b64encode(raw).decode(),
        'signature': base64.b64encode(metadata.with_name('manifest.json.minisig').read_bytes()).decode(), 'incompatible': None}
    class Source:
        repository = 'nimbo78/cmprovision'
        key = public
        def release(self, *args): return envelope
        def fetch(self, tag, asset, destination, progress):
            shutil.copyfile(candidate, destination)
            progress(asset['size'], asset['size'])
    number = 0
    store = system.store
    worker = Worker(store, Source(), system)
    def operation(action, **args):
        nonlocal number
        number += 1
        entry, _ = store.begin(dict(action=action, nonce=f'{number:064x}', **args))
        worker.run(entry['id'])
        return store.operation(entry['id'])
    def download():
        entry = operation('download', component='application', tag='v2.28.0')
        assert entry['state'] == 'ready', entry
        return store.snapshot()['ready'][-1]
    ready = download()
    health = system.health
    def reject_target(expected, services):
        health(expected, services)
        if expected.get('version') == '2.28.0': raise UpdateError('Injected post-install health failure for rollback acceptance.')
    system.health = reject_target
    entry = operation('apply', candidate=ready['id'], receipt=ready['receipt'])
    assert entry['state'] == 'rolled-back', entry
    assert system.package('cmprovision4') == original
    assert not store.snapshot()['pending']
    assert not Path('/var/lib/cmprovision/update-acceptance-version').exists()
    system.health = health
    ready = download()
    entry = operation('apply', candidate=ready['id'], receipt=ready['receipt'])
    assert entry['state'] == 'succeeded', entry
    assert system.package('cmprovision4') == '2.28.0'
    assert Path('/var/lib/cmprovision/update-acceptance-version').read_text().strip() == '2.28.0'
    worker.run(entry['id']) # Lost-reply replay cannot repeat dpkg.
    for name in ('cmprovision-dnsmasq', 'cmprovision-rpiboot'):
        assert enabled(name) == 'masked'
        assert subprocess.run(['systemctl', 'is-active', name], capture_output=True).returncode != 0
    print('PASS: real Debian package apply, injected-health automatic rollback, replay, HTTP health and observer masks.')


if __name__ == '__main__': run()
