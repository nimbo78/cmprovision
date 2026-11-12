import json
import os
from pathlib import Path
import tempfile
import unittest

from support import manifest
from update_store import UpdateStore
from update_retention import prune


class RetentionTest(unittest.TestCase):
    def test_successful_history_immediately_keeps_only_the_latest_recovery(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            store = UpdateStore(root/'updates', root/'run', owner=os.getuid())
            for number in range(3):
                operation, _ = store.begin({'action': 'check', 'nonce': f'{number:064x}', 'components': ['application']})
                store.update(operation['id'], state='succeeded', previous={'cmprovision': '2.30.0'})
            self.assertEqual([operation['id']], [item['id'] for item in store.snapshot()['recoveries']])

    def test_only_unreferenced_owned_update_artifacts_are_removed(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            store = UpdateStore(root/'updates', root/'run', owner=os.getuid())
            entries = []
            for number in range(7):
                operation, _ = store.begin({'action': 'check', 'nonce': f'{number:064x}', 'components': ['application']})
                identity = operation['id']
                directory = store.private/'recovery'/identity
                store._directory(directory, 0o700)
                (directory/'evidence').write_text('old snapshot')
                os.utime(directory, (1, 1))
                store.update(identity, state='succeeded', backup={'directory': str(directory)},
                    previous={'utility_selector': 'managed:2026.10.1'})
                entries.append(directory)
            unrelated = store.private/'recovery'/'operator-backup'
            unrelated.mkdir()
            (unrelated/'keep').write_text('unrelated')
            orphan = store.payload_dir('a'*64)
            (orphan/'partial.deb').write_bytes(b'old')
            os.utime(orphan, (1, 1))
            managed = root/'managed/releases'
            managed.mkdir(parents=True)
            incomplete = managed/'.prepare-owned-crash'
            incomplete.mkdir()
            os.utime(incomplete, (1, 1))
            prune(store, root/'managed', now=10000)
            self.assertTrue(all(not path.exists() for path in entries[:-1]))
            self.assertTrue(entries[-1].exists())
            self.assertEqual([entries[-1].name], [item['id'] for item in store.snapshot()['recoveries']])
            self.assertTrue(unrelated.exists())
            self.assertFalse(orphan.exists())
            self.assertFalse(incomplete.exists())

    def test_prune_migrates_a_legacy_multi_snapshot_history_to_the_latest_one(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            store = UpdateStore(root/'updates', root/'run', owner=os.getuid())
            legacy = {}
            for number in range(3):
                operation, _ = store.begin({'action': 'check', 'nonce': f'{number:064x}', 'components': ['application']})
                identity = operation['id']
                directory = store.private/'recovery'/identity
                store._directory(directory, 0o700)
                (directory/'evidence').write_text('owned')
                store.update(identity, state='succeeded', backup={'directory': str(directory)})
                legacy[identity] = store.operation(identity)
            store._mutate(lambda state: state.update(recoveries=legacy))
            prune(store, root/'managed')
            self.assertEqual([identity], [item['id'] for item in store.snapshot()['recoveries']])
            self.assertEqual([identity], sorted(path.name for path in (store.private/'recovery').iterdir()))

    def test_active_recovery_is_protected_and_completed_failed_attempt_is_reclaimed(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            store = UpdateStore(root/'updates', root/'run', owner=os.getuid())
            previous, _ = store.begin({'action': 'check', 'nonce': 'a'*64, 'components': ['application']})
            old = store.private/'recovery'/previous['id']
            store._directory(old, 0o700)
            store.update(previous['id'], state='succeeded', backup={'directory': str(old)})
            current, _ = store.begin({'action': 'check', 'nonce': 'b'*64, 'components': ['application']})
            live = store.private/'recovery'/current['id']
            store._directory(live, 0o700)
            store.update(current['id'], state='preparing', backup={'directory': str(live)})
            store.mark_pending(current['id'])
            prune(store, root/'managed')
            self.assertTrue(old.exists())
            self.assertTrue(live.exists())
            store.update(current['id'], state='recovery-required')
            prune(store, root/'managed')
            self.assertTrue(live.exists())
            store.update(current['id'], state='failed', committed=True)
            store.clear_pending(current['id'])
            prune(store, root/'managed')
            self.assertTrue(old.exists())
            self.assertFalse(live.exists(), 'a resolved failed attempt is not another retained backup')

    def test_an_active_rollback_source_survives_legacy_history_pruning(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            store = UpdateStore(root/'updates', root/'run', owner=os.getuid())
            legacy, directories = {}, []
            for number in range(2):
                operation, _ = store.begin({'action': 'check', 'nonce': f'{number:064x}', 'components': ['application']})
                path = store.private/'recovery'/operation['id']
                store._directory(path, 0o700)
                store.update(operation['id'], state='succeeded', backup={'directory': str(path)})
                legacy[operation['id']] = store.operation(operation['id'])
                directories.append(path)
            store._mutate(lambda state: state.update(recoveries=legacy))
            rollback, _ = store.begin({'action': 'rollback', 'nonce': 'a'*64, 'operation': directories[0].name})
            store.update(rollback['id'], state='preparing')
            store.mark_pending(rollback['id'])
            prune(store, root/'managed')
            self.assertTrue(all(path.exists() for path in directories))
            self.assertIn(directories[0].name, {item['id'] for item in store.snapshot()['recoveries']})
            store.update(rollback['id'], state='failed', committed=True)
            store.clear_pending(rollback['id'])
            prune(store, root/'managed')
            self.assertFalse(directories[0].exists())
            self.assertTrue(directories[1].exists())


if __name__ == '__main__': unittest.main()
