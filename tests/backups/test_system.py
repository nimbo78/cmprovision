import importlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import unittest

from support import ROOT
from backup_store import BackupStore
from update_manifest import UpdateError


class SystemTest(unittest.TestCase):
    def setUp(self):
        self.module = importlib.import_module('backup_system')
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.store = BackupStore(self.root/'state', self.root/'runtime', owner=os.getuid())

    def test_snapshot_uses_www_data_and_clears_only_snapshot_runtime_tables(self):
        if os.geteuid() != 0 or not shutil.which('sqlite3'): self.skipTest('root and sqlite3 for disposable account boundary')
        self.root.chmod(0o755)
        database = self.root/'var/lib/cmprovision/database'
        database.mkdir(parents=True)
        for parent in database.parents:
            if parent == self.root: break
            parent.chmod(0o755)
        database.chmod(0o700); os.chown(database, 33, 33)
        source = database/'database.sqlite'
        subprocess.run(['runuser', '-u', 'www-data', '--', 'sqlite3', str(source),
            'CREATE TABLE jobs(id INTEGER); INSERT INTO jobs VALUES(1); CREATE TABLE sessions(id TEXT); INSERT INTO sessions VALUES("session"); CREATE TABLE projects(id INTEGER); INSERT INTO projects VALUES(78);'], check=True)
        system = self.module.LinuxBackupSystem(self.store, root=self.root)
        stage = self.root/'snapshot'; stage.mkdir(mode=0o700)
        saved = system.snapshot_database(stage)
        memory = sqlite3.connect(':memory:')
        try:
            memory.deserialize(saved.read_bytes())
            self.assertEqual(0, memory.execute('SELECT COUNT(*) FROM jobs').fetchone()[0])
            self.assertEqual(0, memory.execute('SELECT COUNT(*) FROM sessions').fetchone()[0])
            self.assertEqual(78, memory.execute('SELECT id FROM projects').fetchone()[0])
        finally: memory.close()
        original = subprocess.run(['runuser','-u','www-data','--','sqlite3',str(source),'SELECT COUNT(*) FROM jobs;'], capture_output=True, text=True, check=True)
        self.assertEqual('1', original.stdout.strip())
        self.assertEqual(0o600, saved.stat().st_mode & 0o777)

    def test_pause_never_stops_nginx_or_php_and_resume_keeps_masks(self):
        calls = []
        def run(argv, **options):
            calls.append(argv)
            return subprocess.CompletedProcess(argv, 0, stdout='', stderr='')
        system = self.module.LinuxBackupSystem(self.store, root=self.root, runner=run)
        services = {'nginx.service': {'active': True, 'enabled': 'enabled'},
            'php8.4-fpm.service': {'active': True, 'enabled': 'enabled'},
            'cmprovision-dnsmasq.service': {'active': False, 'enabled': 'masked'},
            'cmprovision-queue.service': {'active': True, 'enabled': 'enabled'}}
        system.pause(services)
        self.assertFalse(any('nginx.service' in call or 'php8.4-fpm.service' in call for call in calls))
        self.assertIn(['/usr/bin/systemctl', 'mask', '--runtime', 'cmprovision-queue.service'], calls)
        system.resume(services)
        self.assertTrue(any('mask' in call and 'cmprovision-dnsmasq.service' in call for call in calls))
        self.assertFalse(any('unmask' in call and 'cmprovision-dnsmasq.service' in call for call in calls))

    def test_reboot_reestablishes_restore_masks_before_recovery_is_scheduled(self):
        from backup_worker import boot_recovery
        calls, masked = [], set()
        def run(argv, **options):
            calls.append(argv)
            if 'mask' in argv: masked.add(argv[-1])
            if 'systemd-run' in argv[0]:
                self.assertEqual({'nginx.service', 'cmprovision-dnsmasq.service'}, masked)
            return subprocess.CompletedProcess(argv, 0, stdout='', stderr='')
        system = self.module.LinuxBackupSystem(self.store, root=self.root, runner=run)
        job, _ = self.store.begin('d'*64, 'download', {'images': False, 'packages': False}, action='restore')
        services = {name: {'active': True, 'enabled': 'enabled'} for name in ('nginx.service', 'cmprovision-dnsmasq.service')}
        self.store.mark_pending(job['id'], services)
        self.store.pending_file.unlink() # /run disappeared at reboot; durable state remains.
        boot_recovery(self.store, system)
        self.assertTrue(self.store.pending_file.exists())
        self.assertTrue(any('systemd-run' in argv[0] for argv in calls))
        system.resume(services)
        self.assertTrue(all(['/usr/bin/systemctl', 'unmask', '--runtime', name] in calls for name in services))

    def test_migration_rebinds_only_known_job_owners_and_expiry_removes_plaintext(self):
        system = self.module.LinuxBackupSystem(self.store, root=self.root)
        job, _ = self.store.begin('e'*64, 'download', {'images': False, 'packages': False}, action='restore')
        directory = self.store.job_dir(job['id'])
        (directory/'plaintext').write_text('old station secret')
        unknown = self.store.private/'jobs'/('0'*32); unknown.mkdir(mode=0o700)
        self.store._atomic(unknown/'owner.json', {'schema': 1, 'id': '0'*32, 'station_id': '0'*32, 'created_at': 0}, 0o600)
        recovery = self.root/'var/backups/cmprovision-full-restore'/job['id']
        recovery.mkdir(parents=True); (recovery/'before.cmpbackup').write_bytes(b'encrypted recovery')
        self.store.update(job['id'], state='succeeded')
        self.store.rotate_station_identity()
        self.store.begin('f'*64, 'download', {'images': False, 'packages': False}, action='restore')
        self.store.clock = lambda: job['created_at']+7200
        system.sweep()
        self.assertFalse(directory.exists())
        self.assertTrue((recovery/'before.cmpbackup').exists())
        self.assertTrue(unknown.exists())

    def test_mutation_lock_exists_readable_but_not_writable_by_web_and_is_first(self):
        system = self.module.LinuxBackupSystem(self.store, root=self.root)
        lock = self.store.runtime/'mutations.lock'
        self.assertEqual(0o644, lock.stat().st_mode & 0o777)
        network = self.root/'run/cmprovision-network'
        network.mkdir(parents=True)
        (network/'provisioning.lock').touch(); (network/'provisioning.lock').chmod(0o644)
        with system.admission_lock():
            import fcntl
            with lock.open('r') as stream, self.assertRaises(BlockingIOError): fcntl.flock(stream, fcntl.LOCK_SH | fcntl.LOCK_NB)

    def test_collection_requires_own_package_and_active_boot_files(self):
        from update_utility import REQUIRED
        for name, data in {'var/lib/cmprovision/.env': 'APP_KEY=fixture', 'var/lib/cmprovision/etc/dnsmasq.conf': 'fixture',
                'etc/passwd': 'root:x:0:0::/root:/bin/bash\n', 'etc/shadow': 'root:!:1:0:99999:7:::\n', 'etc/group': 'root:x:0:\n',
                'etc/os-release': 'ID=debian\nVERSION_ID=13\n'}.items():
            path = self.root/name; path.parent.mkdir(parents=True, exist_ok=True); path.write_text(data)
        boot = self.root/'var/lib/cmprovision/scriptexecute64'
        for name in REQUIRED:
            path = boot/name; path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(b'boot fixture')
        (self.root/'var/lib/cmprovision/etc/boot').symlink_to('../scriptexecute64')
        calls = []
        def run(argv, **options):
            calls.append(argv)
            text, code = '', 0
            if 'dpkg-query' in argv[0]:
                text, code = ('1024', 0) if any('Installed-Size' in item for item in argv) else (('installed\t2.28.0', 0) if argv[-1] == 'cmprovision4' else ('', 1))
            elif 'dpkg-repack' in argv[0]: (Path(options['cwd'])/'cmprovision4_2.28.0_all.deb').write_bytes(b'package fixture')
            elif 'dpkg-deb' in argv[0]: text = 'Package: cmprovision4\nVersion: 2.28.0\nArchitecture: all\n'
            elif argv[0].endswith('/dpkg'): text = 'arm64'
            elif 'updates:inspect' in argv: text = json.dumps({'utility_selector': 'bundled-modern', 'source_commit': 'd918911'})
            elif 'is-enabled' in argv: text = 'enabled'
            elif 'is-active' in argv: text = 'active'
            return subprocess.CompletedProcess(argv, code, stdout=text, stderr='')
        system = self.module.LinuxBackupSystem(self.store, root=self.root, runner=run)
        self.store.configure_password('a sufficiently long master password')
        job, _ = self.store.begin('a'*64, 'download', {'images': False, 'packages': False})
        self.store.mark_pending(job['id'], {'cmprovision-dnsmasq.service': {'active': False, 'enabled': 'masked'}})
        def snapshot(directory):
            path = directory/'database.sqlite'; path.write_bytes(b'snapshot fixture'); return path
        system.snapshot_database = snapshot
        system.payload_references = lambda path: {'schema': 1, 'references': []}
        directory = self.store.job_dir(job['id'])
        entries, metadata = system.collect(job, directory)
        names = {entry.name for entry in entries}
        self.assertIn('software/cmprovision4_2.28.0_all.deb', names)
        self.assertIn('utility-os/bundled-modern/usb/boot.img', names)
        self.assertIn('application/database.sqlite', names)
        self.assertEqual('d918911', metadata['source_commit'])
        self.assertNotIn('master_password', next(entry.data.decode() for entry in entries if entry.name == 'system/backup-config.json'))
        (boot/'usb/boot.img').unlink()
        retry = directory/'retry'; retry.mkdir()
        with self.assertRaises(UpdateError): system.collect(job, retry)

    def test_active_updater_lock_defers_backup_instead_of_reading_prunable_payloads(self):
        import fcntl
        system = self.module.LinuxBackupSystem(self.store, root=self.root)
        network = self.root/'run/cmprovision-network'; network.mkdir(parents=True)
        (network/'provisioning.lock').touch(); (network/'provisioning.lock').chmod(0o644)
        private = self.root/'var/lib/cmprovision-updates/private'; private.mkdir(parents=True, mode=0o700)
        lock = private/'worker.lock'; lock.touch(); lock.chmod(0o600)
        with lock.open('r') as active:
            fcntl.flock(active, fcntl.LOCK_EX)
            with self.assertRaises(UpdateError):
                with system.admission_lock(): self.fail('must defer while an updater can prune source assets')

    def test_repacking_requires_space_before_creating_an_extra_package(self):
        from unittest.mock import patch
        calls = []
        def run(argv, **options):
            calls.append(argv)
            text = '1024' if any('Installed-Size' in item for item in argv) else ''
            return subprocess.CompletedProcess(argv, 0, stdout=text, stderr='')
        system = self.module.LinuxBackupSystem(self.store, root=self.root, runner=run)
        with patch('backup_system.shutil.disk_usage', return_value=shutil._ntuple_diskusage(10**9, 10**9-268435456, 268435456)):
            with self.assertRaises(UpdateError): system._package('cmprovision4', '2.29.0~dev1', self.root, [])
        self.assertFalse(any('dpkg-repack' in argv[0] for argv in calls))

    def test_package_without_optional_installed_size_uses_only_its_registered_files(self):
        from unittest.mock import patch
        package_root = self.root/'usr/lib/fpms-lite'; package_root.mkdir(parents=True)
        (package_root/'panel.py').write_bytes(b'panel fixture')
        (package_root/'shared.py').symlink_to('panel.py')
        calls = []
        def run(argv, **options):
            calls.append(argv)
            text = ''
            if '--listfiles' in argv: text = '/usr\n/usr/lib\n/usr/lib/fpms-lite\n/usr/lib/fpms-lite/panel.py\n/usr/lib/fpms-lite/shared.py\n'
            elif argv[0].endswith('/dpkg-repack'): (Path(options['cwd'])/'fpms-lite_2.29.0~dev1_all.deb').write_bytes(b'package fixture')
            elif argv[0].endswith('/dpkg-deb'): text = 'Package: fpms-lite\nVersion: 2.29.0~dev1\nArchitecture: all\n'
            return subprocess.CompletedProcess(argv, 0, stdout=text, stderr='')
        system = self.module.LinuxBackupSystem(self.store, root=self.root, runner=run)
        entry, evidence = system._package('fpms-lite', '2.29.0~dev1', self.root, [])
        self.assertEqual('local-snapshot', evidence['provenance'])
        self.assertEqual('software/fpms-lite_2.29.0~dev1_all.deb', entry.name)
        self.assertTrue(any('--listfiles' in argv for argv in calls))
        self.assertTrue(any(argv[0].endswith('/dpkg-repack') for argv in calls))
        retry = self.root/'retry'; retry.mkdir()
        with patch('backup_system.shutil.disk_usage', return_value=shutil._ntuple_diskusage(10**9,10**9-268435456,268435456)):
            with self.assertRaises(UpdateError): system._package('fpms-lite', '2.29.0~dev1', retry, [])


if __name__ == '__main__': unittest.main()
