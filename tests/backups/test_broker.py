import importlib
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock

from support import ROOT
from backup_store import BackupStore
from update_manifest import UpdateError


class BrokerTest(unittest.TestCase):
    def setUp(self):
        self.module = importlib.import_module('backup_broker')
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.store = BackupStore(self.root/'store', self.root/'run', owner=os.getuid(), clock=lambda: 1000)
        self.launcher = Mock()
        self.broker = self.module.Broker(self.store, launcher=self.launcher)

    def request(self, value): return self.broker.request(self.module.decode_request(json.dumps(value).encode()))

    def test_protocol_rejects_ambiguous_unknown_and_unbounded_requests(self):
        for raw in (b'[]', b'{"action":"status","action":"password"}', b'{"action":"status","path":"/etc/shadow"}',
                b'x'*196609, b'{"action":"cancel","id":"../../x"}', b'{"action":"create","nonce":"x","target":"download","options":{}}'):
            with self.subTest(raw=raw[:80]), self.assertRaises(UpdateError): self.module.decode_request(raw)
        self.assertEqual({'action': 'status'}, self.module.decode_request(b'{"action":"status"}'))

    def test_profile_secrets_are_private_and_test_does_not_need_master_password(self):
        result = self.request({'action': 'save-profile', 'profile': {'type': 'ftp', 'name': 'Backup', 'host': '127.0.0.1',
            'user': 'backup', 'password': 'private-transport-password', 'path': '/backups'}})
        profile = result['profiles'][0]
        self.assertNotIn('private-transport-password', json.dumps(result))
        self.assertTrue(profile['has_password'])
        self.request({'action': 'test-profile', 'nonce': 'a'*64, 'id': profile['id']})
        self.launcher.assert_called_once()
        self.assertFalse(self.store.status()['password_configured'])
        self.assertIsNone(self.store.status()['pending'])

    def test_create_replay_launches_once_and_password_never_leaves_private_state(self):
        self.request({'action': 'password', 'password': 'long private master password'})
        request = {'action': 'create', 'nonce': 'a'*64, 'target': 'download', 'options': {'images': True, 'packages': False}}
        first = self.request(request)
        replay = self.request(request)
        self.assertEqual(first['id'], replay['id'])
        self.launcher.assert_called_once_with(first['id'])
        self.assertNotIn('long private', json.dumps(replay))
        self.assertIsNone(self.store.status()['pending'])
        self.request({'action': 'cancel', 'id': first['id']})
        self.assertTrue(self.store.operation(first['id'])['cancel_requested'])

    def test_pending_services_survive_reconstruction_and_history_order_uses_sequence(self):
        self.store.configure_password('a sufficiently long master password')
        options = {'images': False, 'packages': False}
        first, _ = self.store.begin('a'*64, 'download', options)
        services = {'cmprovision-dnsmasq.service': {'active': False, 'enabled': 'masked'}}
        self.store.mark_pending(first['id'], services)
        reconstructed = BackupStore(self.root/'store', self.root/'run', owner=os.getuid(), clock=lambda: 1000)
        self.assertEqual(services, reconstructed.configuration()['pending']['services'])
        self.assertTrue(reconstructed.pending_file.exists())
        reconstructed.update(first['id'], state='failed')
        reconstructed.clear_pending(first['id'])
        second, _ = reconstructed.begin('b'*64, 'download', options)
        self.assertEqual(second['id'], reconstructed.status()['history'][0]['id'])

    def test_live_cli_restore_lock_does_not_spawn_recovery_units_on_every_status_poll(self):
        import subprocess
        job, _ = self.store.begin('d'*64, 'download', {'images': False, 'packages': False}, action='restore')
        self.store.mark_pending(job['id'], {})
        self.store.clock = lambda: 2000
        system = Mock()
        system.command.return_value = subprocess.CompletedProcess([], 3, stdout='inactive', stderr='')
        with self.store.lock('worker', blocking=False): self.module.reconcile(self.store, system)
        self.assertFalse(any('systemd-run' in call.args[0][0] for call in system.command.call_args_list))


if __name__ == '__main__': unittest.main()
