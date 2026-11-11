#!/usr/bin/env python3
"""Enabled boot-service failure/reboot/recovery proof, ONLY inside the disposable station."""
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import uuid

sys.path.insert(0, '/usr/lib/cmprovision')
from backup_restore import Restorer
from backup_restore_system import LinuxRestoreSystem
from backup_store import BackupStore
from backup_worker import Worker, boot_recovery
from update_manifest import UpdateError


def command(*args): return subprocess.check_output(args, text=True).strip()
def state(kind, name): return subprocess.run(['systemctl', kind, name], capture_output=True, text=True).stdout.strip()


def run():
    if (os.geteuid() != 0 or Path('/etc/hostname').read_text().strip() != 'cmprovision-backups-bench'
            or not Path('/fixture/backup-acceptance-only').is_file() or not Path('/run/systemd/container').is_file()):
        raise RuntimeError('Refusing outside the disposable backup station.')
    services = ('cmprovision-dnsmasq.service', 'cmprovision-rpiboot.service')
    store = BackupStore(); system = LinuxRestoreSystem(store)
    assert store.configuration()['pending'] is None
    original = system.capture_services()
    for name in services:
        directory = Path('/run/systemd/system')/(name+'.d'); directory.mkdir(exist_ok=True)
        (directory/'acceptance.conf').write_text('[Service]\nType=simple\nExecStart=\nExecStart=/usr/bin/sleep infinity\n')
        command('systemctl', 'unmask', name)
        command('systemctl', 'enable', name)
    command('systemctl', 'daemon-reload')
    for name in services: command('systemctl', 'start', name)
    password = 'disposable full archive master password'
    store.configure_password(password)
    job, _ = store.begin(uuid.uuid4().hex*2, 'download', {'images': False, 'packages': False})
    Worker(store, system).run(job['id'])
    assert store.operation(job['id'])['state'] == 'ready', store.operation(job['id'])['message']
    archive = store.download_file(job['id'])
    class FailingConfiguration(LinuxRestoreSystem):
        def restore_context(self, recovery=False):
            context = super().restore_context(recovery)
            context['verified_packages'] = {} # Exercise a real same-version reinstall.
            return context
        def command(self, argv, **kwargs):
            result = super().command(argv, **kwargs)
            if '--fix-broken' in argv:
                # Real postinst attempted boot restarts; emulate its losing the final reply/failing.
                for name in services:
                    assert subprocess.run(['systemctl', 'restart', name], capture_output=True).returncode != 0
                    assert state('is-enabled', name) == 'masked-runtime'
                raise UpdateError('Injected configure failure after maintainer-script restarts.')
            return result
    try:
        try:
            Restorer(store, FailingConfiguration(store)).run(archive, password, apply=True, groups=[], allow_snapshot=True)
            raise AssertionError('Expected injected failure')
        except UpdateError as error: assert 'Injected configure failure' in str(error), str(error)
        pending = store.configuration()['pending']; assert pending
        restore = store.operation(pending['id'])
        assert restore['state'] == 'recovery-required'
        # Model /run loss at reboot, then execute the real Before= boot barrier.
        for name, service_state in pending['services'].items():
            if not service_state['enabled'].startswith('masked'): command('systemctl', 'unmask', '--runtime', name)
        store.pending_file.unlink()
        for name in services:
            command('systemctl', 'start', name) # Condition skips start even before Python boot recovery.
            assert state('is-active', name) == 'inactive'
        boot_recovery(store, system)
        for name in services:
            assert subprocess.run(['systemctl', 'start', name], capture_output=True).returncode != 0
            assert state('is-active', name) == 'inactive'
        # Let the asynchronous boot reconciler finish without repeating a snapshot.
        for _ in range(100):
            result = subprocess.run(['systemctl', 'is-active', 'cmprovision-backup-recovery.service'], capture_output=True, text=True)
            if result.stdout.strip() not in ('active', 'activating', 'deactivating'): break
            time.sleep(.1)
        Restorer(store, system).run(restore['recovery_file'], password, apply=True, recover=True,
            groups=['time', 'display', 'web', 'role'], allow_snapshot=True, mode='same-station')
        assert store.configuration()['pending'] is None
        for name in services:
            assert state('is-enabled', name) == 'enabled'
            assert state('is-active', name) == 'active'
        print('PASS: real same-version package reinstall, configure failure, enabled boot restart rejection, reboot barrier, authenticated recovery and exact enabled/active role.')
    finally:
        if store.configuration()['pending'] is None:
            system.resume(original)
            for name in services: (Path('/run/systemd/system')/(name+'.d/acceptance.conf')).unlink(missing_ok=True)
            command('systemctl', 'daemon-reload')


if __name__ == '__main__': run()
