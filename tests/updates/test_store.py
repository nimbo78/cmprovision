import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from support import encode, manifest
from update_manifest import UpdateError
from update_store import UpdateStore


class StoreTest(unittest.TestCase):
    def test_recovery_expiry_is_seven_days_after_success_and_metadata_does_not_extend_it(self):
        now = [1000]
        self.store.clock = lambda: now[0]
        job, _ = self.store.begin(self.request)
        self.store.update(job['id'], state='succeeded', previous={'cmprovision': '2.30.1'})
        point = self.store.snapshot()['recoveries'][0]
        self.assertEqual(1000+604800, point.get('recovery_expires_at'))
        now[0] += 3600
        self.store.update(job['id'], message='Housekeeping note after completion.')
        self.assertEqual(point['recovery_expires_at'], self.store.snapshot()['recoveries'][0]['recovery_expires_at'])

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.store = UpdateStore(self.base/'state', self.base/'runtime', owner=os.getuid())
        self.request = {'action': 'check', 'components': ['application', 'utility-os'], 'nonce': 'a'*64}

    def test_nonce_replay_returns_one_durable_operation_even_after_completion(self):
        first, created = self.store.begin(self.request)
        self.assertTrue(created)
        repeated, created = self.store.begin(self.request)
        self.assertFalse(created)
        self.assertEqual(first['id'], repeated['id'])
        self.store.update(first['id'], state='succeeded')
        fresh = UpdateStore(self.base/'state', self.base/'runtime', owner=os.getuid())
        repeated, created = fresh.begin(self.request)
        self.assertFalse(created)
        self.assertEqual('succeeded', repeated['state'])
        changed = dict(self.request, components=['application'])
        with self.assertRaises(UpdateError): fresh.begin(changed)

    def test_second_operation_cannot_overtake_an_active_one(self):
        first, _ = self.store.begin(self.request)
        with self.assertRaises(UpdateError): self.store.begin(dict(self.request, nonce='b'*64))
        self.store.update(first['id'], state='succeeded')
        second, created = self.store.begin(dict(self.request, nonce='b'*64))
        self.assertTrue(created)
        self.assertNotEqual(first['id'], second['id'])

    def test_terminal_worker_cleanup_blocks_a_new_nonce_but_allows_replay(self):
        operation, _ = self.store.begin(self.request)
        self.store.update(operation['id'], state='succeeded')
        with self.store.lock('worker', blocking=False):
            repeated, created = self.store.begin(self.request)
            self.assertFalse(created)
            self.assertEqual(operation['id'], repeated['id'])
            with self.assertRaises(UpdateError): self.store.begin(dict(self.request, nonce='b'*64))

    def test_pending_is_durable_and_unknown_recovery_cannot_clear_it(self):
        operation, _ = self.store.begin({'action': 'apply', 'candidate': 'f'*64, 'receipt': 'b'*64, 'nonce': 'c'*64})
        self.store.mark_pending(operation['id'])
        self.assertEqual(operation['id'], self.store.snapshot()['pending']['id'])
        self.store.update(operation['id'], state='recovery-required')
        with self.assertRaises(UpdateError): self.store.clear_pending(operation['id'])
        self.assertTrue(self.store.snapshot()['pending'])
        self.store.update(operation['id'], state='rolled-back')
        self.store.clear_pending(operation['id'])
        self.assertIsNone(self.store.snapshot()['pending'])

    def test_public_projection_excludes_private_recovery_paths_and_redacts_logs(self):
        operation, _ = self.store.begin(self.request)
        self.store.update(operation['id'], state='checking', backup='/root/private-backup',
                          log=['password=hunter2 token=secret https://name:pass@example.com/file'])
        public = self.store.snapshot()
        serialized = json.dumps(public)
        for secret in ['hunter2', 'token=secret', 'name:pass', '/root/private-backup', self.request['nonce']]:
            self.assertNotIn(secret, serialized)
        self.assertEqual(public, json.loads((self.store.public/'status.json').read_text()))
        self.assertEqual(0o700, self.store.private.stat().st_mode & 0o777)
        self.assertEqual(0o644, (self.store.public/'status.json').stat().st_mode & 0o777)

    def test_unsafe_requests_and_progress_are_rejected(self):
        for change in [{'nonce': '../evil'}, {'command': 'rm'}, {'components': ['firmware']}, {'action': 'shell'}]:
            with self.subTest(change=change), self.assertRaises(UpdateError):
                self.store.begin(dict(self.request, **change))
        operation, _ = self.store.begin(self.request)
        with self.assertRaises(UpdateError): self.store.update(operation['id'], state='unknown-command')
        with self.assertRaises(UpdateError): self.store.update(operation['id'], downloaded=True)
        with self.assertRaises(UpdateError): self.store.update('../x', state='succeeded')

    def test_corrupt_or_symlink_state_is_not_read_or_replaced(self):
        self.store.state_file.write_text('{broken')
        with self.assertRaises(UpdateError): self.store.snapshot()
        self.store.state_file.unlink()
        other = self.base/'secret'
        other.write_text('do not read or change')
        self.store.state_file.symlink_to(other)
        with self.assertRaises(UpdateError): self.store.snapshot()
        self.assertEqual('do not read or change', other.read_text())

    def test_insecure_or_symlink_directories_are_rejected(self):
        for name, mode in [('writable', 0o777), ('group-writable', 0o770)]:
            parent = self.base/name
            parent.mkdir(mode=mode)
            parent.chmod(mode)
            with self.assertRaises(UpdateError): UpdateStore(parent/'state', self.base/'runtime2', owner=os.getuid())
        link = self.base/'link'
        link.symlink_to(self.base/'state', target_is_directory=True)
        with self.assertRaises(UpdateError): UpdateStore(link, self.base/'runtime2', owner=os.getuid())

    def test_failed_atomic_replace_keeps_previous_operation_state(self):
        operation, _ = self.store.begin(self.request)
        with patch('update_store.os.replace', side_effect=OSError('injected write failure')):
            with self.assertRaises(OSError): self.store.update(operation['id'], state='downloading')
        self.assertEqual('queued', self.store.operation(operation['id'])['state'])
        self.assertEqual([], list(self.store.private.glob('*.tmp')))

    def test_cancel_is_only_available_before_critical_apply(self):
        operation, _ = self.store.begin(self.request)
        self.store.cancel(operation['id'])
        self.assertTrue(self.store.operation(operation['id'])['cancel_requested'])
        self.store.mark_pending(operation['id'])
        with self.assertRaises(UpdateError): self.store.cancel(operation['id'])

    def test_public_permissions_are_independent_of_root_umask(self):
        previous = os.umask(0o077)
        try:
            store = UpdateStore(self.base/'restricted', self.base/'runtime3', owner=os.getuid())
            operation, _ = store.begin(self.request)
            store.mark_pending(operation['id'])
        finally:
            os.umask(previous)
        self.assertEqual(0o644, (store.public/'status.json').stat().st_mode & 0o777)
        self.assertEqual(0o644, store.pending_file.stat().st_mode & 0o777)

    def test_completed_backup_history_is_bounded_without_deleting_backups(self):
        backup = self.base/'retained-snapshot'
        backup.mkdir()
        for number in range(45):
            operation, _ = self.store.begin(dict(self.request, nonce=f'{number:064x}'))
            self.store.update(operation['id'], state='succeeded', backup=str(backup))
        private = json.loads(self.store.state_file.read_text())
        self.assertLessEqual(len(private['operations']), 40)
        self.assertTrue(backup.is_dir())

    def test_projection_failure_does_not_undo_a_committed_durable_result(self):
        operation, _ = self.store.begin(self.request)
        original = os.replace
        def fail_public(source, destination):
            if Path(destination).parent == self.store.public: raise OSError('injected projection failure')
            return original(source, destination)
        with patch('update_store.os.replace', side_effect=fail_public):
            self.store.update(operation['id'], state='succeeded')
        self.assertEqual('succeeded', self.store.operation(operation['id'])['state'])
        repeated, created = self.store.begin(self.request)
        self.assertFalse(created)
        self.assertEqual(operation['id'], repeated['id'])


if __name__ == '__main__': unittest.main()
