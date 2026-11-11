import json
import unittest
import tempfile
import os
from pathlib import Path
import subprocess
from unittest.mock import Mock

from support import *
from update_manifest import UpdateError
from update_broker import Broker, decode_request, reconcile
from update_store import UpdateStore


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
