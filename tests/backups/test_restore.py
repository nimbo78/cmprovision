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
    def test_rescue_configuration_is_network_data_not_web_or_station_role(self):
        from backup_restore import file_group
        self.assertEqual('network', file_group('etc/default/cmprovision-rescue-wifi.json'))

    def setUp(self):
        self.module = importlib.import_module('backup_restore')
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.metadata = {'station_id': 'a'*32, 'version': '2.28.0', 'source_commit': 'd918911',
            'os': {'id': 'raspbian', 'version': '13', 'architecture': 'arm64'}, 'options': {'images': False, 'packages': False},
            'software': [{'package': 'cmprovision4', 'version': '2.28.0', 'archive': 'software/cmprovision4_2.28.0_all.deb', 'provenance': 'local-snapshot'},
                         {'component': 'utility-os', 'selector': 'bundled-modern', 'provenance': 'local-snapshot', 'covered_by': 'cmprovision4'}],
            'utility_selector': 'bundled-modern', 'absent': []}
        self.context = {'cmprovision': '2.28.0', 'fpms_version': None, 'os': self.metadata['os'], 'verified_packages': {'cmprovision4': True},
            'utility_selector': 'bundled-modern', 'utility_os_version': '2026.10',
            'accounts': [{'name': 'root', 'uid': 0, 'gid': 0}], 'groups': {'root': 0, 'sudo': 27},
            'interfaces': {'eth0': '00:11:22:33:44:55'}}
        self.account = {'name': 'operator', 'uid': 1000, 'gid': 1000, 'primary_group': 'operator', 'groups': ['sudo'],
            'home': '/home/operator', 'shell': '/bin/bash', 'gecos': 'Operator', 'password_hash': '$y$fixture',
            'aging': ['20000', '0', '99999', '7', '', '', ''], 'authorized_keys': ''}
        self.time_settings = {'timezone': 'UTC', 'enabled': 'enabled', 'active': 'active'}

    def archive(self):
        entries = [Entry('application/.env', data=b'APP_KEY=fixture'), Entry('application/database.sqlite', data=b'database fixture'),
            Entry('application/etc/dnsmasq.conf', data=b'fixture'), Entry('software/cmprovision4_2.28.0_all.deb', data=b'package fixture'),
            Entry('system/accounts.json', data=canonical_bytes([self.account])),
            Entry('system/backup-config.json', data=canonical_bytes({'schema': 1, 'profiles': {}, 'schedule': {'enabled': False,
                'frequency': 'daily', 'time': '03:00', 'weekday': 0, 'profile': None, 'keep': 7, 'images': False, 'packages': False}})),
            Entry('system/service-state.json', data=b'{}'), Entry('system/time.json', data=canonical_bytes(self.time_settings)),
            Entry('system/payloads.json', data=b'{"schema":1,"references":[]}'),
            Entry('system/etc/hostname', data=b'source-station'), Entry('system/etc/ssh/sshd_config', data=b'PasswordAuthentication yes'),
            Entry('system/etc/default/cmprovision-stand-interface', data=b'eth0'),
            Entry('system/etc/default/cmprovision-rescue-wifi.json', data=b'{"password":"secret-rescue-fixture","enabled":true}'),
            Entry('system/etc/NetworkManager/system-connections/cmprovision-rescue.nmconnection', data=b'[connection]\nid=cmprovision-rescue\n'),
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

    def test_encrypted_ap_credentials_require_explicit_network_restore_and_stay_out_of_preview(self):
        path = self.archive()
        self.assertNotIn(b'secret-rescue-fixture', path.read_bytes())
        with inspect_archive(path, 'a sufficiently long password', self.root) as archive:
            default = self.module.plan_restore(archive, self.context)
            explicit = self.module.plan_restore(archive, self.context, groups=['network'], interfaces={'eth0': 'eth0'})
        config = 'etc/default/cmprovision-rescue-wifi.json'
        profile = 'etc/NetworkManager/system-connections/cmprovision-rescue.nmconnection'
        self.assertNotIn(config, default['system'])
        self.assertNotIn(profile, default['system'])
        self.assertIn(config, explicit['system'])
        self.assertIn(profile, explicit['system'])
        self.assertNotIn('secret-rescue-fixture', json.dumps(explicit['preview']))

    def test_network_restore_needs_explicit_mapping_for_source_mac_binding(self):
        with inspect_archive(self.archive(), 'a sufficiently long password', self.root) as archive:
            with self.assertRaises(UpdateError): self.module.plan_restore(archive, self.context, groups=['network'])
            plan = self.module.plan_restore(archive, self.context, groups=['network'], interfaces={'eth0': 'eth0'})
            profile = plan['rewritten']['etc/NetworkManager/system-connections/stand.nmconnection']
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
            def restore_context(inner, recovery=False): return self.context
            def validate_restore_payloads(inner, archive): pass
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

    def test_recovery_can_inspect_without_bootstrapping_a_broken_application(self):
        import subprocess
        from unittest.mock import patch
        from backup_store import BackupStore
        module = importlib.import_module('backup_restore_system')
        store = BackupStore(self.root/'state', self.root/'run', owner=os.getuid())
        (self.root/'etc').mkdir(); (self.root/'etc/os-release').write_text('ID=debian\nVERSION_ID="13"\n')
        (self.root/'etc/passwd').write_text('root:x:0:0:root:/root:/bin/bash\n'); (self.root/'etc/group').write_text('root:x:0:\n')
        def run(argv, **options):
            text = '[]' if 'link' in argv else '8.4.27' if argv[0].endswith('/php') else 'amd64' if '--print-architecture' in argv else 'installed\t2.29.0~dev1'
            return subprocess.CompletedProcess(argv, 0, stdout=text, stderr='')
        system = module.LinuxRestoreSystem(store, root=self.root, runner=run)
        with patch.object(system, 'context', side_effect=UpdateError('Application cannot boot.')):
            with self.assertRaises(UpdateError): system.restore_context()
            context = system.restore_context(recovery=True)
        self.assertEqual('0', context['cmprovision'])
        self.assertEqual('13', context['os']['version'])
        self.assertIsNone(context['utility_selector'])

    def test_saved_schedule_runtime_boundary_does_not_break_repeated_backup_restore(self):
        from backup_store import BackupStore
        store = BackupStore(self.root/'state', self.root/'run', owner=os.getuid(), clock=lambda: 100)
        store.save_schedule({'enabled': False, 'frequency': 'daily', 'time': '03:00', 'weekday': 0,
            'profile': None, 'keep': 7, 'images': False, 'packages': False})
        restored = self.module.restore_backup_configuration(store.export_configuration(), now=200)
        self.assertFalse(restored['schedule']['enabled'])
        self.assertEqual(200, restored['schedule']['not_before'])

    def test_absent_source_ntp_provider_preserves_destination_ntp_configuration(self):
        self.time_settings = {'timezone': 'UTC', 'enabled': 'not-found', 'active': 'inactive'}
        self.metadata['absent'] = ['etc/systemd/timesyncd.conf']
        with inspect_archive(self.archive(), 'a sufficiently long password', self.root) as archive:
            plan = self.module.plan_restore(archive, self.context)
        self.assertFalse(plan['time']['restore_ntp'])
        self.assertNotIn('etc/systemd/timesyncd.conf', plan['remove'])

    def test_matching_snapshot_database_is_in_place_before_package_configuration(self):
        import subprocess
        from backup_store import BackupStore
        module = importlib.import_module('backup_restore_system')
        store = BackupStore(self.root/'state', self.root/'run', owner=os.getuid())
        job, _ = store.begin('c'*64, 'download', {'images': False, 'packages': False}, action='restore')
        store.mark_pending(job['id'], {'nginx.service': {'active': True, 'enabled': 'enabled'}})
        calls = []
        def runner(argv, **options):
            calls.append('configure' if '--fix-broken' in argv else 'unpack' if '--unpack' in argv else 'command')
            return subprocess.CompletedProcess(argv, 0, stdout='', stderr='')
        class Effects(module.LinuxRestoreSystem):
            def stage_restore(inner, archive, plan, directory): return self.root
            def package_plan(inner, paths, archive): return [{'package': 'cmprovision4', 'version': '2.28.0'}]
            def _application(inner, stage, archive): calls.append('database')
            def restore_accounts(inner, rows): pass
            def www(inner, *args): return {'ok': True}
            def _restore_utility(inner, archive, plan, stage): pass
        system = Effects(store, root=self.root, runner=runner)
        self.context['cmprovision'] = '2.27.0'
        with inspect_archive(self.archive(), 'a sufficiently long password', self.root) as archive:
            plan = self.module.plan_restore(archive, self.context, groups=[], allow_snapshot=True)
            system.apply_restore(archive, plan, self.root)
        self.assertLess(calls.index('unpack'), calls.index('database'))
        self.assertLess(calls.index('database'), calls.index('configure'))

    def test_restored_public_display_and_ntp_settings_are_readable_but_secrets_stay_private(self):
        import subprocess
        from types import SimpleNamespace
        from backup_store import BackupStore
        from backup_restore_system import LinuxRestoreSystem
        public = ['etc/default/cmprovision-fpms.json', 'etc/default/cmprovision-wallboard.json',
                  'etc/systemd/timesyncd.conf', 'etc/systemd/timesyncd.conf.d/90-cmprovision.conf']
        private = ['etc/NetworkManager/system-connections/stand.nmconnection', 'etc/ssh/ssh_host_ed25519_key']
        stage = self.root/'stage'
        for name in public + private:
            path = stage/'system'/name; path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(b'fixture'); path.chmod(0o777)
        store = BackupStore(self.root/'state', self.root/'run', owner=os.getuid())
        job, _ = store.begin('e'*64, 'download', {'images': False, 'packages': False}, action='restore')
        store.mark_pending(job['id'], {})
        class Effects(LinuxRestoreSystem):
            def stage_restore(inner, archive, plan, directory): return stage
            def _application(inner, stage, archive): pass
            def restore_accounts(inner, rows): pass
            def www(inner, *args): return {'ok': True}
            def _restore_utility(inner, archive, plan, stage): pass
        system = Effects(store, root=self.root, runner=lambda argv, **options: subprocess.CompletedProcess(argv, 0, stdout='', stderr=''))
        plan = {'install': [], 'system': public + private, 'remove': [], 'accounts': [], 'groups': []}
        system.apply_restore(SimpleNamespace(manifest={'options': {'images': False, 'packages': False}}), plan, stage)
        for name in public:
            self.assertEqual(0o644, (self.root/name).stat().st_mode & 0o777, name)
        for name in private:
            self.assertEqual(0o600, (self.root/name).stat().st_mode & 0o777, name)

    def test_recovery_repairs_only_its_interrupted_station_packages(self):
        import subprocess
        from types import SimpleNamespace
        from backup_store import BackupStore
        module = importlib.import_module('backup_restore_system')
        store = BackupStore(self.root/'state', self.root/'run', owner=os.getuid())
        broken = ['cmprovision4']
        def runner(argv, **options):
            if '--audit' in argv: text = 'An interrupted package exists.'
            elif argv[0].endswith('/dpkg-deb'): text = 'Package: cmprovision4\nVersion: 2.28.0\nArchitecture: all'
            elif argv[0].endswith('/dpkg-query'): text = '\n'.join(name+'\tiU ' for name in broken)
            else: text = 'Inst cmprovision4 (2.28.0 local [all])'
            return subprocess.CompletedProcess(argv, 0, stdout=text, stderr='')
        system = module.LinuxRestoreSystem(store, root=self.root, runner=runner); system.recovering = True
        result = system.package_plan([self.root/'fixture.deb'], SimpleNamespace(manifest={'version': '2.28.0'}))
        self.assertEqual([{'package': 'cmprovision4', 'version': '2.28.0'}], result)
        broken.append('unrelated-package')
        with self.assertRaises(UpdateError): system.package_plan([self.root/'fixture.deb'], SimpleNamespace(manifest={'version': '2.28.0'}))

    def test_cli_group_selection_remains_explicit_during_emergency_recovery(self):
        self.assertEqual(['time', 'web'], self.module.selected_groups('time,web', recovery=True))
        self.assertIn('role', self.module.selected_groups(None, recovery=True))
        self.assertNotIn('role', self.module.selected_groups(None, recovery=False))

    def test_same_version_recovery_reinstalls_damaged_software(self):
        self.context.update(recovering=True, verified_packages={'cmprovision4': False})
        with inspect_archive(self.archive(), 'a sufficiently long password', self.root) as archive:
            plan = self.module.plan_restore(archive, self.context, allow_snapshot=True)
        self.assertIn('software/cmprovision4_2.28.0_all.deb', plan['install'])

    def test_preview_can_disclose_snapshot_software_without_authorizing_its_execution(self):
        from backup_store import BackupStore
        store = BackupStore(self.root/'state', self.root/'run', owner=os.getuid())
        self.context.update(recovering=True, verified_packages={})
        class Effects:
            def restore_context(inner, recovery=False): return self.context
            def validate_restore_payloads(inner, archive): pass
        preview = self.module.Restorer(store, Effects()).run(self.archive(), 'a sufficiently long password')
        self.assertEqual(['software/cmprovision4_2.28.0_all.deb'], preview['install'])
        self.assertFalse(preview['snapshot_software_opt_in'])
        self.assertTrue(preview['requires_snapshot_software_opt_in'])
        self.assertIsNone(store.configuration()['pending'])

    def test_utility_replacement_is_exact_for_unsigned_managed_and_bundled_recovery(self):
        import hashlib
        from types import SimpleNamespace
        from unittest.mock import Mock
        from backup_store import BackupStore
        from backup_restore_system import LinuxRestoreSystem
        from update_utility import REQUIRED, verify_tree
        store = BackupStore(self.root/'state', self.root/'run', owner=os.getuid())
        system = LinuxRestoreSystem(store, root=self.root)
        system.context = lambda: {'utility_selector': 'bundled-modern'}
        system.platform.activate_utility = Mock()
        target = system.app/'scriptexecute64'; target.mkdir(parents=True)
        (target/'obsolete.dtb').write_bytes(b'old')
        for selector, label in (('managed:2026.11.1', 'managed-2026.11.1'), ('bundled-modern', 'bundled-modern')):
            stage = self.root/label; base = stage/'utility-os'/label; base.mkdir(parents=True)
            lines = ['version=2026.11.1', 'bits=64', 'kernel=6.18.50']
            for name in sorted(REQUIRED):
                path = base/name; path.parent.mkdir(exist_ok=True, parents=True); path.write_bytes(label.encode()); path.chmod(0o600)
                lines.append('file='+hashlib.sha256(path.read_bytes()).hexdigest()+' '+str(path.stat().st_size)+' '+name)
            (base/'MANIFEST').write_text('\n'.join(lines)+'\n'); (base/'MANIFEST').chmod(0o600)
            if selector.startswith('managed:'): (base/'receipt.json').write_text('{}')
            archive = SimpleNamespace(manifest={'utility_selector': selector, 'software': [{'component': 'utility-os'}]})
            system._restore_utility(archive, {'evidence': {}}, stage)
            verify_tree(target, owner=os.getuid())
            self.assertFalse((target/'obsolete.dtb').exists())
            self.assertEqual(label.encode(), (target/'kernel8.img').read_bytes())
            system.platform.activate_utility.assert_called_with('bundled-modern')


if __name__ == '__main__': unittest.main()
