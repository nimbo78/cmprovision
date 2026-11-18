import hashlib
import io
import os
import shutil
import sqlite3
import subprocess
import tarfile
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from update_manifest import UpdateError
from update_store import UpdateStore
from update_system import LinuxSystem, parse_plan


def write_snapshot(snapshot):
    snapshot.mkdir(mode=0o700)
    archives = {
        'app.tar.gz': ('var/lib/cmprovision/.env', 'var/lib/cmprovision/etc/dnsmasq.conf'),
        'system-config.tar.gz': ('etc/nginx/sites-available/cmprovision',),
    }
    for name, members in archives.items():
        with tarfile.open(snapshot/name, 'w:gz') as archive:
            for member in members:
                data = b'configuration\n'
                info = tarfile.TarInfo(member)
                info.size = len(data)
                archive.addfile(info, io.BytesIO(data))
    database = sqlite3.connect(snapshot/'database.sqlite')
    try:
        database.execute('CREATE TABLE projects (id INTEGER PRIMARY KEY)')
    finally:
        database.close()
    with tempfile.TemporaryDirectory() as temp:
        control = Path(temp)/'DEBIAN'
        control.mkdir()
        (control/'control').write_text('Package: cmprovision4\nVersion: 2.30.0\nArchitecture: all\n'
                                      'Maintainer: Test <test@example.invalid>\nDescription: Recovery fixture\n')
        subprocess.run(['dpkg-deb', '--build', temp, snapshot/'cmprovision4_2.30.0_all.deb'],
                       check=True, capture_output=True)


class RecoverySnapshotTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        root = Path(temp.name)
        self.store = UpdateStore(root/'store', root/'run', owner=os.getuid())
        entry, _ = self.store.begin({'action': 'check', 'nonce': 'a'*64, 'components': ['application']})
        self.identity = entry['id']
        self.store.update(self.identity, previous={'cmprovision': '2.30.0', 'fpms_installed': False,
                                                   'fpms_version': None})
        directory = self.store.private/'recovery'/self.identity
        directory.mkdir(mode=0o700, parents=True)
        self.snapshot = directory/'20261118-120000-2.30.0'
        write_snapshot(self.snapshot)
        self.restore = directory/'restore.sh'
        self.restore.write_bytes(b'fixture')
        self.system = LinuxSystem(self.store)
        self.installers = []
        command = self.system.command
        def intercept(argv, **options):
            if argv[0] in ('/usr/bin/dpkg', '/bin/sh'):
                self.installers.append(argv[0])
                raise UpdateError('Test stopped the installer.')
            return command(argv, **options)
        self.system.command = intercept

    def backup(self):
        return {'identity': self.identity, 'directory': str(self.snapshot),
                'restore_sha256': hashlib.sha256(self.restore.read_bytes()).hexdigest(),
                'hashes': {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in self.snapshot.iterdir()}}

    def test_missing_required_archive_never_calls_installer(self):
        (self.snapshot/'system-config.tar.gz').unlink()
        with self.assertRaises(UpdateError):
            self.system.recover(self.backup())
        self.assertEqual([], self.installers)

    def test_archive_with_matching_checksum_and_truncated_footer_never_calls_installer(self):
        for name in ('app.tar.gz', 'system-config.tar.gz'):
            with self.subTest(name=name):
                archive = self.snapshot/name
                original = archive.read_bytes()
                archive.write_bytes(original[:-5])
                try:
                    with self.assertRaises(UpdateError):
                        self.system.recover(self.backup())
                    self.assertEqual([], self.installers)
                finally:
                    archive.write_bytes(original)

    def test_absent_application_package_never_calls_installer(self):
        (self.snapshot/'cmprovision4_2.30.0_all.deb').unlink()
        with self.assertRaises(UpdateError):
            self.system.recover(self.backup())
        self.assertEqual([], self.installers)

    def test_damaged_package_payload_with_readable_control_never_calls_installer(self):
        package = self.snapshot/'cmprovision4_2.30.0_all.deb'
        package.write_bytes(package.read_bytes()[:-12])
        with self.assertRaises(UpdateError):
            self.system.recover(self.backup())
        self.assertEqual([], self.installers)

    def test_changed_source_after_preflight_does_not_start_mutation(self):
        backup = self.backup()
        verified = self.system.validate_backup(backup)
        database = self.snapshot/'database.sqlite'
        replacement = self.snapshot.parent/'replacement.sqlite'
        shutil.copyfile(database, replacement)
        replacement.replace(database)
        mutations = []
        with self.assertRaises(UpdateError):
            self.system.recover(backup, verified=verified, before_mutation=lambda: mutations.append(True))
        self.assertEqual([], mutations)
        self.assertEqual([], self.installers)

    def test_invalid_database_with_recorded_checksum_never_calls_installer(self):
        (self.snapshot/'database.sqlite').write_bytes(b'not a database')
        with self.assertRaises(UpdateError):
            self.system.recover(self.backup())
        self.assertEqual([], self.installers)

    def test_missing_previously_installed_display_package_never_calls_installer(self):
        self.store.update(self.identity, previous={'cmprovision': '2.30.0', 'fpms_installed': True,
                                                   'fpms_version': '2.30.0'})
        with self.assertRaises(UpdateError):
            self.system.recover(self.backup())
        self.assertEqual([], self.installers)

    def test_unrecorded_package_is_never_passed_to_installer(self):
        backup = self.backup()
        (self.snapshot/'extra.deb').write_bytes(b'unrecorded')
        with self.assertRaises(UpdateError):
            self.system.recover(backup)
        self.assertEqual([], self.installers)

    def test_complete_legacy_snapshot_reaches_installer(self):
        with self.assertRaisesRegex(UpdateError, 'Test stopped the installer'):
            self.system.recover(self.backup())
        self.assertEqual(['/usr/bin/dpkg'], self.installers)


class SystemTest(unittest.TestCase):
    def test_backup_retires_even_the_latest_recovery_before_testing_free_space(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            store = UpdateStore(root/'updates', root/'run', owner=os.getuid())
            legacy, old = {}, []
            for number in range(2):
                operation, _ = store.begin({'action': 'check', 'nonce': f'{number:064x}', 'components': ['application']})
                path = store.private/'recovery'/operation['id']
                store._directory(path, 0o700)
                store.update(operation['id'], state='succeeded', backup={'directory': str(path)})
                legacy[operation['id']] = store.operation(operation['id'])
                old.append(path)
            store._mutate(lambda state: state.update(recoveries=legacy))
            current, _ = store.begin({'action': 'check', 'nonce': 'a'*64, 'components': ['application']})
            store.update(current['id'], state='preparing', previous={'cmprovision': '2.30.0', 'fpms_installed': False})
            store.mark_pending(current['id'])
            system = LinuxSystem(store)
            system.www = lambda *args: {}
            from update_retention import prune
            system.prune = lambda **kwargs: prune(store, root/'managed', **kwargs)
            system.package = lambda name: None
            read_only_command = system.command
            def command(argv, **options):
                if argv[0].endswith('/du'): return '1024 app'
                if argv[0].endswith('/cmprovision-backup'):
                    snapshot = Path(argv[-1])/'20261112-120000-2.30.0'
                    write_snapshot(snapshot)
                    return ''
                if argv[0] in ('/usr/bin/tar', '/usr/bin/dpkg-deb'):
                    return read_only_command(argv, **options)
                raise AssertionError(argv)
            system.command = command
            def usage(path):
                free = 0 if any(path.exists() for path in old) else 512*1024**2
                return shutil._ntuple_diskusage(1024**3, 1024**3-free, free)
            with patch('update_system.shutil.disk_usage', side_effect=usage), patch('update_system.shutil.copyfile',
                    side_effect=lambda source, target: Path(target).write_bytes(b'fixture restore')):
                backup = system.backup(current['id'])
            self.assertFalse(old[0].exists())
            self.assertFalse(old[1].exists())
            self.assertEqual(current['id'], backup['identity'])

    def test_space_failure_after_retirement_does_not_advertise_the_removed_recovery(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            store = UpdateStore(root/'updates', root/'run', owner=os.getuid())
            previous, _ = store.begin({'action': 'check', 'nonce': 'a'*64, 'components': ['application']})
            old = store.private/'recovery'/previous['id']
            store._directory(old, 0o700)
            store.update(previous['id'], state='succeeded', backup={'directory': str(old)})
            current, _ = store.begin({'action': 'check', 'nonce': 'b'*64, 'components': ['application']})
            store.update(current['id'], state='preparing'); store.mark_pending(current['id'])
            system = LinuxSystem(store)
            from update_retention import prune
            system.prune = lambda **kwargs: prune(store, root/'managed', **kwargs)
            system.command = lambda *args, **kwargs: '1024 app'
            def no_space(path):
                self.assertFalse(old.exists(), 'retirement must precede the disk-space check')
                return shutil._ntuple_diskusage(1, 1, 0)
            with patch('update_system.shutil.disk_usage', side_effect=no_space), self.assertRaisesRegex(UpdateError, 'Not enough free space'):
                system.backup(current['id'])
            self.assertEqual([], store.snapshot()['recoveries'])

    def test_apt_plan_rejects_removal_and_pins_every_install(self):
        self.assertEqual([{'package': 'cmprovision4', 'version': '2.28.0'},
                          {'package': 'libexample:arm64', 'version': '1:2.0-1'}],
            parse_plan('Inst cmprovision4 [2.27.0] (2.28.0 local-deb [all])\n'
                       'Inst libexample:arm64 (1:2.0-1 Debian:13/stable [arm64])\nConf libexample (1:2.0-1)'))
        for value in ('Remv nginx [1.2]', 'Inst invalid;name (1.0 local)', 'Inst nginx (bad/version local)'):
            with self.assertRaises(UpdateError): parse_plan(value)

    def test_critical_install_uses_no_download_and_only_approved_versions(self):
        system = LinuxSystem.__new__(LinuxSystem)
        calls = []
        system.command = lambda argv, **kw: calls.append(([str(x) for x in argv], kw)) or ''
        plan = [{'package': 'cmprovision4', 'version': '2.28.0'}]
        system.plan_install = lambda *args: plan
        system.install([Path('/root/private/app.deb')], plan, {})
        self.assertEqual(['/usr/bin/dpkg', '--force-confold', '--unpack', '/root/private/app.deb'], calls[0][0])
        self.assertIn('--no-download', calls[1][0])
        self.assertIn('--no-remove', calls[1][0])
        self.assertIn('cmprovision4=2.28.0', calls[1][0])
        self.assertNotIn('/root/private/app.deb', calls[1][0])

    def test_service_restore_does_not_unmask_observer_boot_services(self):
        system = LinuxSystem.__new__(LinuxSystem)
        calls = []
        system.command = lambda argv, **kw: calls.append(argv) or ''
        system.resume({'cmprovision-dnsmasq.service': {'enabled': 'masked', 'active': False},
                       'cmprovision-queue.service': {'enabled': 'enabled', 'active': True}}, boot=True)
        self.assertFalse(any('unmask' in c and 'cmprovision-dnsmasq.service' in c for c in calls))
        self.assertFalse(any('start' in c and 'cmprovision-dnsmasq.service' in c for c in calls))
        self.assertTrue(any('start' in c and 'cmprovision-queue.service' in c for c in calls))

    def test_masked_but_running_service_is_rejected_before_changing_anything(self):
        system = LinuxSystem.__new__(LinuxSystem)
        calls = []
        def command(argv, **kwargs):
            calls.append(argv)
            if 'list-unit-files' in argv: return ''
            import subprocess
            return subprocess.CompletedProcess(argv, 0, stdout='LoadState=loaded\nUnitFileState=masked\nActiveState=active\n')
        system.command = command
        with self.assertRaisesRegex(UpdateError, 'masked service is still running'): system.capture_services()
        self.assertTrue(all('stop' not in argv and 'mask' not in argv for argv in calls))


if __name__ == '__main__': unittest.main()
