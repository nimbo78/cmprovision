#!/usr/bin/env python3
import importlib.machinery
import json
import sys
import unittest

module = importlib.machinery.SourceFileLoader('network_backend', sys.argv.pop()).load_module()


class SecretsTest(unittest.TestCase):
    def snapshot(self, secret):
        def run(*args):
            if '--show-secrets' in args:
                return 'connection.timestamp:123\n802-1x.password:' + secret
            return 'auto'
        backend = module.Backend(None, None, None, lambda: False, run=run)
        backend._hash = lambda _: 'file-hash'
        backend._expected_persisted_hash = lambda _: 'file-hash'
        return backend.snapshot({'uuid': 'id', 'filename': '/unused', 'arguments': ['ipv4.method', 'auto']})

    def test_secret_changes_affect_only_the_profile_hash(self):
        first = self.snapshot('private-password-one')
        second = self.snapshot('private-password-two')
        self.assertNotEqual(first['profile_hash'], second['profile_hash'])
        self.assertNotIn('private-password', json.dumps(first))
        self.assertEqual(first['values'], second['values'])

    def test_unavailable_secret_fails_closed(self):
        with self.assertRaises(module.BackendError): self.snapshot('<hidden>')


unittest.main()
