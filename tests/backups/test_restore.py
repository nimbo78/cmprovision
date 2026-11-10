import importlib
import json
import os
from pathlib import Path
import tempfile
import unittest

from support import ROOT
from backup_archive import Entry, create, inspect_archive
from update_manifest import UpdateError, canonical_bytes


class RestoreTest(unittest.TestCase):
    def setUp(self):
        self.module = importlib.import_module('backup_restore')
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.metadata = {'station_id': 'a'*32, 'version': '2.28.0', 'source_commit': 'd918911',
            'os': {'id': 'raspbian', 'version': '13', 'architecture': 'arm64'}, 'options': {'images': False, 'packages': False},
            'software': [{'package': 'cmprovision4', 'version': '2.28.0', 'archive': 'software/cmprovision4_2.28.0_all.deb', 'provenance': 'local-snapshot'},
                         {'component': 'utility-os', 'selector': 'bundled-modern', 'provenance': 'local-snapshot', 'covered_by': 'cmprovision4'}],
            'utility_selector': 'bundled-modern', 'absent': []}
        self.context = {'cmprovision': '2.28.0', 'fpms_version': None, 'os': self.metadata['os'],
            'utility_selector': 'bundled-modern', 'utility_os_version': '2026.10',
            'accounts': [{'name': 'root', 'uid': 0, 'gid': 0}], 'groups': {'root': 0, 'sudo': 27},
            'interfaces': {'eth0': '00:11:22:33:44:55'}}
        self.account = {'name': 'operator', 'uid': 1000, 'gid': 1000, 'primary_group': 'operator', 'groups': ['sudo'],
            'home': '/home/operator', 'shell': '/bin/bash', 'gecos': 'Operator', 'password_hash': '$y$fixture',
            'aging': ['20000', '0', '99999', '7', '', '', ''], 'authorized_keys': ''}

    def archive(self):
        entries = [Entry('application/.env', data=b'APP_KEY=fixture'), Entry('application/database.sqlite', data=b'database fixture'),
            Entry('application/etc/dnsmasq.conf', data=b'fixture'), Entry('software/cmprovision4_2.28.0_all.deb', data=b'package fixture'),
            Entry('system/accounts.json', data=canonical_bytes([self.account])),
            Entry('system/backup-config.json', data=b'{"schema":1,"profiles":{},"schedule":{"enabled":false}}'),
            Entry('system/service-state.json', data=b'{}'), Entry('system/time.json', data=b'{"timezone":"UTC","enabled":"enabled","active":"active"}'),
            Entry('system/etc/hostname', data=b'source-station'), Entry('system/etc/ssh/sshd_config', data=b'PasswordAuthentication yes'),
            Entry('system/etc/default/cmprovision-stand-interface', data=b'eth0'),
            Entry('system/etc/NetworkManager/system-connections/stand.nmconnection',
                data=b'[connection]\ninterface-name=eth0\n[ethernet]\nmac-address=AA:BB:CC:DD:EE:FF\n')]
        path = self.root/'copy.cmpbackup'
        create(entries, path, 'a sufficiently long password', self.metadata)
        return path

    def test_migration_preserves_destination_network_ssh_accounts_host_and_role_by_default(self):
        with inspect_archive(self.archive(), 'a sufficiently long password', self.root) as archive:
            plan = self.module.plan_restore(archive, self.context)
        self.assertEqual([], plan['accounts'])
        self.assertFalse(any(path.startswith(('etc/ssh/', 'etc/NetworkManager/')) or path in ('etc/hostname', 'etc/default/cmprovision-stand-interface') for path in plan['system']))
        self.assertEqual([], plan['install'])
        self.assertFalse(plan['restore_role'])
        self.assertNotIn('$y$fixture', json.dumps(plan['preview']))

    def test_network_restore_needs_explicit_mapping_for_source_mac_binding(self):
        with inspect_archive(self.archive(), 'a sufficiently long password', self.root) as archive:
            with self.assertRaises(UpdateError): self.module.plan_restore(archive, self.context, groups=['network'])
            plan = self.module.plan_restore(archive, self.context, groups=['network'], interfaces={'eth0': 'eth0'})
            profile = next(data for name, data in plan['rewritten'].items() if name.endswith('.nmconnection'))
            self.assertIn(b'mac-address=00:11:22:33:44:55', profile)

    def test_account_selection_rejects_emergency_or_service_uid_collision(self):
        with inspect_archive(self.archive(), 'a sufficiently long password', self.root) as archive:
            self.context['accounts'].append({'name': 'service-existing', 'uid': 1000, 'gid': 1000})
            with self.assertRaises(UpdateError): self.module.plan_restore(archive, self.context, selected_accounts=['operator'])
            self.context['accounts'].pop()
            with self.assertRaises(UpdateError): self.module.plan_restore(archive, self.context, selected_accounts=['operator'], emergency='operator')
            plan = self.module.plan_restore(archive, self.context, selected_accounts=['operator'])
            self.assertEqual('operator', plan['accounts'][0]['name'])

    def test_snapshot_software_requires_explicit_root_opt_in_when_not_reusing_installed_version(self):
        self.context['cmprovision'] = '2.27.0'
        with inspect_archive(self.archive(), 'a sufficiently long password', self.root) as archive:
            with self.assertRaises(UpdateError): self.module.plan_restore(archive, self.context)
            plan = self.module.plan_restore(archive, self.context, allow_snapshot=True)
            self.assertEqual(['software/cmprovision4_2.28.0_all.deb'], plan['install'])

    def test_atomic_writer_refuses_parent_and_leaf_symlinks(self):
        destination = self.root/'destination'; destination.mkdir()
        outside = self.root/'outside'; outside.mkdir(); (outside/'value').write_bytes(b'unchanged')
        (destination/'etc').symlink_to(outside)
        with self.assertRaises(UpdateError): self.module.write_file(destination, 'etc/value', b'new', 0o600)
        (destination/'etc').unlink(); (destination/'etc').mkdir(); (destination/'etc/value').symlink_to(outside/'value')
        with self.assertRaises(UpdateError): self.module.write_file(destination, 'etc/value', b'new', 0o600)
        self.assertEqual(b'unchanged', (outside/'value').read_bytes())

    def test_corrupt_archive_or_failed_recovery_snapshot_never_changes_destination(self):
        from backup_store import BackupStore
        store = BackupStore(self.root/'state', self.root/'run', owner=os.getuid())
        calls = []
        class Effects:
            def restore_context(inner): return self.context
            def freeze_restore(inner, job): calls.append('freeze'); store.mark_pending(job['id'], {})
            def recovery_snapshot(inner, archive, job, password): calls.append('recovery'); raise UpdateError('No recovery space.')
            def release_restore(inner, job): calls.append('release'); store.clear_pending(job['id'])
            def apply_restore(inner, archive, plan, directory): calls.append('mutate')
        path = self.archive()
        controller = self.module.Restorer(store, Effects())
        with self.assertRaises(UpdateError): controller.run(path, 'wrong password', apply=True)
        self.assertEqual([], calls)
        with self.assertRaises(UpdateError): controller.run(path, 'a sufficiently long password', apply=True)
        self.assertEqual(['freeze', 'recovery', 'release'], calls)
        self.assertIsNone(store.status()['pending'])

    def test_removing_absent_setting_and_database_sidecars_is_idempotent(self):
        from backup_store import BackupStore
        module = importlib.import_module('backup_restore_system')
        store = BackupStore(self.root/'state', self.root/'run', owner=os.getuid())
        system = module.LinuxRestoreSystem(store, root=self.root)
        system._remove('etc/missing/value')
        (self.root/'etc').mkdir(exist_ok=True)
        (self.root/'etc/value').write_bytes(b'old')
        system._remove('etc/value'); system._remove('etc/value')
        self.assertFalse((self.root/'etc/value').exists())

    def test_application_restore_replaces_storage_and_checks_sqlite_as_www_data(self):
        import sqlite3
        import subprocess
        from backup_store import BackupStore
        module = importlib.import_module('backup_restore_system')
        store = BackupStore(self.root/'state', self.root/'run', owner=os.getuid())
        calls = []
        def run(argv, **options):
            calls.append(argv)
            return subprocess.CompletedProcess(argv, 0, stdout='ok', stderr='')
        system = module.LinuxRestoreSystem(store, root=self.root, runner=run)
        stage = self.root/'stage'; source = stage/'application'
        (source/'storage/app').mkdir(parents=True); (source/'storage/app/restored').write_bytes(b'new')
        (source/'etc').mkdir(); (source/'etc/dnsmasq.conf').write_bytes(b'fixture'); (source/'.env').write_bytes(b'key')
        database = sqlite3.connect(':memory:'); database.execute('CREATE TABLE settings(key TEXT)')
        (source/'database.sqlite').write_bytes(database.serialize()); database.close()
        old = self.root/'var/lib/cmprovision/storage/app'; old.mkdir(parents=True); (old/'old-secret').write_bytes(b'old')
        (self.root/'var/lib/cmprovision/database').mkdir(); (self.root/'var/lib/cmprovision/database/database.sqlite-wal').write_bytes(b'old')
        system._application(stage, None)
        self.assertFalse((old/'old-secret').exists())
        self.assertEqual(b'new', (old/'restored').read_bytes())
        self.assertTrue(any(argv[:4] == ['/usr/sbin/runuser', '-u', 'www-data', '--'] and '/usr/bin/sqlite3' in argv for argv in calls))


if __name__ == '__main__': unittest.main()
