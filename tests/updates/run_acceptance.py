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
import tarfile
import time
import uuid

from acceptance_version import candidate_version

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
    fixture = Path(tempfile.mkdtemp(prefix='run-', dir='/fixture'))
    system = LinuxSystem(UpdateStore())
    broker = subprocess.run(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/sudo', '-n',
        '/usr/lib/cmprovision/station-updates'], input='{"action":"status"}', text=True, capture_output=True, check=True)
    assert json.loads(broker.stdout)['result']['schema'] == 1
    refused = subprocess.run(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/sudo', '-n',
        '/usr/lib/cmprovision/station-updates', '--recover'], capture_output=True)
    assert refused.returncode != 0
    original = system.package('cmprovision4')
    version = candidate_version(original)
    system.command(['/usr/bin/systemctl', 'start', 'nginx', 'php8.4-fpm', 'cmprovision-network-init'])
    system.http_health({})
    for name in ('cmprovision-dnsmasq', 'cmprovision-rpiboot'):
        assert enabled(name) == 'masked'
    package = next(Path('/fixture').glob('cmprovision4_*.deb'))
    target = fixture/'candidate'
    subprocess.run(['dpkg-deb', '-R', str(package), str(target)], check=True)
    key, public = fixture/'key.sec', fixture/'key.pub'
    subprocess.run(['minisign', '-G', '-W', '-s', str(key), '-p', str(public)], check=True, stdout=subprocess.DEVNULL)
    # Keep one fixture issuer across installs and dpkg-repacked recovery packages.
    # Otherwise a later install may legitimately replace the repacked conffile default.
    shutil.copyfile(public, target/'etc/cmprovision-updates/publisher.pub')
    control = target/'DEBIAN/control'
    text = control.read_text().replace('Version: '+original+'\n', 'Version: '+version+'\n')
    assert text != control.read_text()
    control.write_text(text)
    (target/'var/lib/cmprovision/update-acceptance-version').write_text(version+'\n')
    candidate = fixture/f'cmprovision4_{version}_all.deb'
    subprocess.run(['dpkg-deb', '--build', str(target), str(candidate)], check=True)
    # Only this disposable station trusts the ephemeral fixture publisher.
    shutil.copyfile(public, '/etc/cmprovision-updates/publisher.pub')
    os.chmod('/etc/cmprovision-updates/publisher.pub', 0o644)
    asset = {'name': candidate.name, 'size': candidate.stat().st_size,
             'sha256': hashlib.sha256(candidate.read_bytes()).hexdigest()}
    value = {'schema': 1, 'component': 'application', 'version': version, 'tag': 'v'+version,
        'repository': 'nimbo78/cmprovision', 'source_sha': 'f'*40, 'published_at': '2026-11-09T00:00:00Z',
        'notes': 'Disposable package acceptance fixture, never published.',
        'compatibility': {'debian': ['13'], 'architectures': ['amd64'], 'php': {'8.4': '8.4.27'},
            'min_cmprovision': original, 'updater_protocol': 1},
        'assets': [asset, {'name': f'fpms-lite_{version}_all.deb', 'size': 1, 'sha256': 'a'*64}]}
    raw = canonical_bytes(value)
    metadata = fixture/'manifest.json'
    metadata.write_bytes(raw)
    subprocess.run(['minisign', '-S', '-W', '-s', str(key), '-m', str(metadata)], check=True, stdout=subprocess.DEVNULL)
    envelope = {'id': hashlib.sha256(raw).hexdigest(), 'manifest': value, 'raw': base64.b64encode(raw).decode(),
        'signature': base64.b64encode(metadata.with_name('manifest.json.minisig').read_bytes()).decode(), 'incompatible': None}
    class Source:
        repository = 'nimbo78/cmprovision'
        key = public
        selected = envelope
        files = {candidate.name: candidate}
        def release(self, *args): return self.selected
        def fetch(self, tag, asset, destination, progress):
            shutil.copyfile(self.files[asset['name']], destination)
            progress(asset['size'], asset['size'])
    number = 0
    store = system.store
    source = Source()
    worker = Worker(store, source, system)
    def operation(action, **args):
        nonlocal number
        number += 1
        entry, _ = store.begin(dict(action=action, nonce=uuid.uuid4().hex+uuid.uuid4().hex, **args))
        worker.run(entry['id'])
        return store.operation(entry['id'])
    def download():
        entry = operation('download', component='application', tag='v'+version)
        assert entry['state'] == 'ready', entry
        return store.snapshot()['ready'][-1]
    ready = download()
    health = system.health
    def reject_target(expected, services):
        health(expected, services)
        if expected.get('version') == version: raise UpdateError('Injected post-install health failure for rollback acceptance.')
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
    assert system.package('cmprovision4') == version
    assert Path('/etc/cmprovision-updates/publisher.pub').read_bytes() == public.read_bytes()
    assert Path('/var/lib/cmprovision/update-acceptance-version').read_text().strip() == version
    application_result = entry
    worker.run(entry['id']) # Lost-reply replay cannot repeat dpkg.
    for name in ('cmprovision-dnsmasq', 'cmprovision-rpiboot'):
        assert enabled(name) == 'masked'
        assert subprocess.run(['systemctl', 'is-active', name], capture_output=True).returncode != 0
    print('PASS: real Debian package apply, injected-health automatic rollback, replay, HTTP health and observer masks.')

    # Exercise the independent store with the package's real boot payload (fixture version;
    # no sensor is booted or written and this metadata is never published).
    boot = fixture/'boot'
    shutil.copytree('/var/lib/cmprovision/scriptexecute64', boot)
    inventory = boot/'MANIFEST'
    lines = inventory.read_text().splitlines()
    old_version = next(line for line in lines if line.startswith('version='))
    inventory.write_text('\n'.join('version=2026.11.1' if line == old_version else line for line in lines)+'\n')
    archive = fixture/'utility-os-2026.11.1.tar.gz'
    with tarfile.open(archive, 'w:gz') as output: output.add(boot, arcname='boot')
    uos = dict(value, component='utility-os', version='2026.11.1', tag='utility-os-2026.11.1',
        compatibility=dict(value['compatibility'], min_cmprovision=version),
        assets=[{'name': archive.name, 'size': archive.stat().st_size, 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest()}],
        sources={'name': 'utility-os-2026.11.1-legal-info.tar.gz', 'size': 1, 'sha256': 'a'*64},
        utility={'architecture': 'aarch64', 'bits': 64, 'kernel': '6.18.50'})
    raw_uos = canonical_bytes(uos)
    metadata_uos = fixture/'uos.json'
    metadata_uos.write_bytes(raw_uos)
    subprocess.run(['minisign', '-S', '-W', '-s', str(key), '-m', str(metadata_uos)], check=True, stdout=subprocess.DEVNULL)
    source.selected = {'id': hashlib.sha256(raw_uos).hexdigest(), 'manifest': uos, 'raw': base64.b64encode(raw_uos).decode(),
        'signature': base64.b64encode(metadata_uos.with_name('uos.json.minisig').read_bytes()).decode(), 'incompatible': None}
    source.files[archive.name] = archive
    before = system.context()['utility_selector']
    entry = operation('download', component='utility-os', tag='utility-os-2026.11.1')
    assert entry['state'] == 'ready', entry
    assert system.context()['utility_selector'] == before
    ready_uos = next(item for item in store.snapshot()['ready'] if item['release']['component'] == 'utility-os')
    entry = operation('apply', candidate=ready_uos['id'], receipt=ready_uos['receipt'])
    assert entry['state'] == 'succeeded', entry
    assert system.context()['utility_selector'] == 'managed:2026.11.1'
    entry = operation('rollback', operation=entry['id'])
    assert entry['state'] == 'succeeded', entry
    assert system.context()['utility_selector'] == before
    entry = operation('activate', selector='managed:2026.11.1')
    assert entry['state'] == 'succeeded', entry
    entry = operation('rollback', operation=application_result['id'])
    assert entry['state'] == 'succeeded', entry
    assert system.package('cmprovision4') == original
    try: system.validate_utility('managed:2026.11.1')
    except UpdateError: pass
    else: raise AssertionError('Incompatible managed Utility OS accepted after application rollback.')
    print('PASS: real signed Utility OS prepare/apply/rollback and compatibility rejection after application rollback.')

    # Interrupt after real dpkg installation, then damage the live updater and issuer key.
    # The stable boot dispatcher must run the retained modules and restore the issuer too.
    source.selected = envelope
    ready = download()
    install = system.install
    class Interrupted(BaseException): pass
    def interrupt(*args):
        install(*args)
        raise Interrupted()
    system.install = interrupt
    request, _ = store.begin({'action': 'apply', 'candidate': ready['id'], 'receipt': ready['receipt'],
        'nonce': uuid.uuid4().hex+uuid.uuid4().hex})
    try: worker.run(request['id'])
    except Interrupted: pass
    else: raise AssertionError('Interruption was not injected.')
    assert store.snapshot()['pending']
    Path('/usr/lib/cmprovision/update_worker.py').write_text('invalid replacement payload !\n')
    Path('/etc/cmprovision-updates/publisher.pub').write_text('invalid replacement issuer key\n')
    subprocess.run(['/usr/bin/python3', '-B', '-I', '/var/lib/cmprovision-updates/recover.py'], check=True)
    for unused in range(120):
        result = store.operation(request['id'])
        if result['state'] == 'recovery-required' or (result['state'] == 'rolled-back' and not store.snapshot()['pending']): break
        time.sleep(2)
    assert result['state'] == 'rolled-back', result
    assert not store.snapshot()['pending']
    assert system.package('cmprovision4') == original
    assert Path('/etc/cmprovision-updates/publisher.pub').read_bytes() == public.read_bytes()
    print('PASS: interrupted real install recovered through retained Python runner despite damaged live helper/key.')


if __name__ == '__main__': run()
