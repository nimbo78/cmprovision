import importlib
import json
import os
from pathlib import Path
import tempfile
import unittest

from support import ROOT
from update_manifest import UpdateError


class StoreTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.module = importlib.import_module('backup_store')
        self.now = 1000
        self.store = self.module.BackupStore(self.root/'store', self.root/'run', owner=os.getuid(), clock=lambda: self.now)

    def test_default_and_legacy_retention_are_one_without_changing_credentials_or_schedule_switches(self):
        self.assertEqual(1, self.store.status()['schedule']['keep'])
        profile = {'id': 'a'*32, 'type': 'sftp', 'name': 'fixture', 'host': '127.0.0.1',
                   'user': 'backup', 'path': '/backups', 'password': 'fixture credential', 'keep': 365}
        self.store.save_profile(profile)
        self.assertEqual(1, self.store.configuration()['profiles']['a'*32]['keep'])
        state = self.store.configuration()
        state['profiles']['a'*32]['keep'] = 365
        state['schedule'].update(keep=7, enabled=False)
        self.store._atomic(self.store.state_file, state, 0o600)
        fresh = self.module.BackupStore(self.root/'store', self.root/'run', owner=os.getuid(), clock=lambda: self.now)
        config = fresh.configuration()
        self.assertEqual(1, config['profiles']['a'*32]['keep'])
        self.assertEqual(1, config['schedule']['keep'])
        self.assertEqual('fixture credential', config['profiles']['a'*32]['password'])
        self.assertFalse(config['schedule']['enabled'])
        self.assertEqual(1, fresh.export_configuration()['schedule']['keep'])

    def test_master_password_is_private_and_status_never_returns_secrets(self):
        self.store.configure_password('a sufficiently long master password')
        raw = json.dumps(self.store.status())
        self.assertNotIn('sufficiently', raw)
        self.assertTrue(self.store.status()['password_configured'])
        self.assertEqual(0o600, self.store.state_file.stat().st_mode & 0o777)
        self.assertEqual(0o700, self.store.private.stat().st_mode & 0o777)
        self.assertNotIn('master_password', self.store.export_configuration())
        self.assertNotIn('station_id', self.store.export_configuration())

    def test_duplicate_request_is_idempotent_and_second_job_does_not_overtake(self):
        self.store.configure_password('a sufficiently long master password')
        first, created = self.store.begin('a'*64, 'download', {'images': False, 'packages': False})
        replay, again = self.store.begin('a'*64, 'download', {'images': False, 'packages': False})
        self.assertTrue(created)
        self.assertFalse(again)
        self.assertEqual(first['id'], replay['id'])
        with self.assertRaises(UpdateError): self.store.begin('b'*64, 'download', {'images': False, 'packages': False})
        with self.assertRaises(UpdateError): self.store.configure_password('a different master password')
        self.store.update(first['id'], state='failed', message='fixture ended')
        self.assertIsNone(self.store.status()['pending'])

    def test_ready_download_is_fixed_private_regular_file_and_expires(self):
        self.store.configure_password('a sufficiently long master password')
        job, _ = self.store.begin('a'*64, 'download', {'images': False, 'packages': False})
        directory = self.store.job_dir(job['id'])
        archive = directory/'backup.cmpbackup'
        archive.write_bytes(b'encrypted fixture')
        archive.chmod(0o600)
        self.store.update(job['id'], state='ready', expires_at=1100, bytes=archive.stat().st_size)
        self.assertEqual(archive, self.store.download_file(job['id']))
        with self.assertRaises(UpdateError): self.store.download_file('../../etc/shadow')
        self.now = 1101
        with self.assertRaises(UpdateError): self.store.download_file(job['id'])

    def test_symlink_directories_and_state_fail_closed(self):
        other = self.root/'other'
        other.mkdir()
        (self.root/'link').symlink_to(other, target_is_directory=True)
        with self.assertRaises(UpdateError): self.module.BackupStore(self.root/'link', self.root/'run2', owner=os.getuid())
        self.store.state_file.unlink()
        self.store.state_file.symlink_to(self.root/'outside')
        with self.assertRaises(UpdateError): self.store.status()


if __name__ == '__main__': unittest.main()
