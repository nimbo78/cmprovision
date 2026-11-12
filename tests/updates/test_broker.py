import json
import unittest
import tempfile
import os
from pathlib import Path
import subprocess
from unittest.mock import Mock, patch

from support import *
from update_manifest import UpdateError
from update_broker import Broker, decode_request, reconcile
from update_store import UpdateStore


class RecoveryMigrationTest(unittest.TestCase):
    def test_idle_status_retries_expired_files_after_delete_failure_and_a_later_check(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            now = [1000]
            store = UpdateStore(root/'updates', root/'run', owner=os.getuid(), clock=lambda: now[0])
            previous, _ = store.begin({'action': 'check', 'nonce': 'a'*64, 'components': ['application']})
            directory = store.private/'recovery'/previous['id']
            store._directory(directory, 0o700); os.utime(directory, (1000, 1000))
            store.update(previous['id'], state='succeeded', backup={'directory': str(directory)})
            current, _ = store.begin({'action': 'check', 'nonce': 'b'*64, 'components': ['application']})
            store.update(current['id'], state='succeeded')
            now[0] += 604800
            broker = Broker(store)
            with patch('update_retention.shutil.rmtree', side_effect=OSError('temporary deletion failure')) as remove:
                self.assertEqual([], broker.request({'action': 'status'})['recoveries'])
                remove.assert_called_once()
            self.assertTrue(directory.exists(), 'metadata is retired before physical deletion')
            with store.lock('worker', blocking=False):
                broker.request({'action': 'status'})
                self.assertTrue(directory.exists(), 'a retry must still respect a live worker')
            broker.request({'action': 'status'})
            self.assertFalse(directory.exists(), 'later hourly polls retry even when the point is no longer current')

    def test_idle_status_retires_one_expired_point_but_skips_a_locked_worker(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            now = [1000]
            store = UpdateStore(root/'updates', root/'run', owner=os.getuid(), clock=lambda: now[0])
            job, _ = store.begin({'action': 'check', 'nonce': 'a'*64, 'components': ['application']})
            directory = store.private/'recovery'/job['id']
            store._directory(directory, 0o700)
            os.utime(directory, (now[0], now[0]))
            store.update(job['id'], state='succeeded', backup={'directory': str(directory)})
            now[0] += 604800
            broker = Broker(store)
            with store.lock('worker', blocking=False):
                self.assertEqual(1, len(broker.request({'action': 'status'})['recoveries']))
                self.assertTrue(directory.exists())
            self.assertEqual([], broker.request({'action': 'status'})['recoveries'])
            self.assertFalse(directory.exists())

    def test_idle_status_migrates_legacy_recoveries_but_does_not_interrupt_a_worker_or_backup(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            store = UpdateStore(root/'updates', root/'run', owner=os.getuid())
            legacy, paths = {}, []
            for number in range(2):
                operation, _ = store.begin({'action': 'check', 'nonce': f'{number:064x}', 'components': ['application']})
                path = store.private/'recovery'/operation['id']
                store._directory(path, 0o700)
                store.update(operation['id'], state='succeeded', backup={'directory': str(path)})
                legacy[operation['id']] = store.operation(operation['id'])
                paths.append(path)
            store._mutate(lambda state: state.update(recoveries=legacy))
            broker = Broker(store)
            pending = store.runtime.with_name('cmprovision-backups')/'pending.json'
            pending.parent.mkdir()
            pending.write_text('{}')
            self.assertEqual(2, len(broker.request({'action': 'status'})['recoveries']))
            self.assertTrue(all(path.exists() for path in paths))
            pending.unlink()
            with store.lock('worker', blocking=False):
                self.assertEqual(2, len(broker.request({'action': 'status'})['recoveries']))
                self.assertTrue(all(path.exists() for path in paths))
            self.assertEqual([paths[-1].name], [row['id'] for row in broker.request({'action': 'status'})['recoveries']])
            self.assertFalse(paths[0].exists())
            self.assertTrue(paths[-1].exists())


class BrokerTest(unittest.TestCase):
    def test_backup_gate_rejects_new_checks_but_keeps_status_available(self):
        with tempfile.TemporaryDirectory() as temporary:
            store = UpdateStore(Path(temporary)/'state', Path(temporary)/'run', owner=os.getuid())
            marker = store.runtime.with_name('cmprovision-backups')/'pending.json'
            marker.parent.mkdir(); marker.write_text('{}')
            broker = Broker(store, launcher=Mock())
            self.assertEqual(1, broker.request({'action': 'status'})['schema'])
            with self.assertRaises(UpdateError):
                broker.request({'action': 'check', 'nonce': 'a'*64, 'components': ['application']})

    def test_bounded_json_has_no_ambiguous_or_unknown_protocol(self):
        for raw in (b'{}', b'{"action":"status","action":"check"}', b'[]', b'x'*8193,
                    b'{"action":"status","path":"/etc/shadow"}', b'{"action":"cancel","id":"../../x"}'):
            with self.subTest(raw=raw[:50]):
                with self.assertRaises(UpdateError): decode_request(raw)
        self.assertEqual({'action': 'status'}, decode_request(b'{"action":"status"}'))
        self.assertEqual({'action': 'diagnostics', 'id': 'a'*32}, decode_request(json.dumps({'action': 'diagnostics', 'id': 'a'*32})))
        for value in ({'action': 'diagnostics', 'id': '../../shadow'}, {'action': 'diagnostics', 'id': 'a'*32, 'path': '/etc/shadow'}):
            with self.assertRaises(UpdateError): decode_request(json.dumps(value))

    def test_reply_loss_cannot_launch_a_second_worker(self):
        store = Mock()
        store.runtime = Path('/nonexistent/cmprovision-update-test')
        store.begin.side_effect = [({'id': 'a'*32}, True), ({'id': 'a'*32}, False)]
        store.snapshot.return_value = {'schema': 1}
        launcher = Mock()
        broker = Broker(store, launcher)
        request = {'action': 'check', 'nonce': 'f'*64, 'components': ['application']}
        self.assertEqual('a'*32, broker.request(request)['id'])
        self.assertEqual('a'*32, broker.request(request)['id'])
        launcher.assert_called_once_with('a'*32)

    def test_dead_noncritical_worker_becomes_failed_without_launching_recovery(self):
        with tempfile.TemporaryDirectory() as temporary:
            store = UpdateStore(Path(temporary)/'state', Path(temporary)/'run', owner=os.getuid(), clock=lambda: 1)
            operation, _ = store.begin({'action': 'check', 'nonce': 'e'*64, 'components': ['application']})
            calls = []
            def run(argv, **kwargs):
                calls.append(argv)
                return subprocess.CompletedProcess(argv, 3, stdout='inactive\n', stderr='')
            reconcile(store, run=run, clock=lambda: 100)
            self.assertEqual('failed', store.operation(operation['id'])['state'])
            self.assertEqual(1, len(calls))


if __name__ == '__main__': unittest.main()
