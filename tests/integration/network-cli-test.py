#!/usr/bin/env python3
"""Test the root entrypoint using a private temporary state and SQLite database."""
import importlib.machinery
import io
import os
import sqlite3
import sys
import tempfile
import unittest

module = importlib.machinery.SourceFileLoader('network_cli', sys.argv.pop()).load_module()


class EntryPointTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='cmp-network-cli-')
        os.chmod(self.tmp.name, 0o755)  # the www-data sqlite reader needs traversal
        self.original = module.BASE, module.STATE, module.RUNTIME, module.DATABASE
        module.BASE = self.tmp.name + '/base'
        module.STATE = module.BASE + '/transactions'
        module.RUNTIME = self.tmp.name + '/runtime'
        module.DATABASE = self.tmp.name + '/database.sqlite'
        with sqlite3.connect(module.DATABASE) as db:
            db.execute('CREATE TABLE cms (phase TEXT, provisioning_started_at TEXT)')
        os.chmod(module.DATABASE, 0o644)

    def tearDown(self):
        module.BASE, module.STATE, module.RUNTIME, module.DATABASE = self.original
        self.tmp.cleanup()

    def call(self, action, body=b'{}'):
        argv, stdin = sys.argv, sys.stdin
        try:
            sys.argv = ['network-transaction', action]
            sys.stdin = io.TextIOWrapper(io.BytesIO(body), encoding='utf-8')
            return module.main()
        finally:
            sys.argv, sys.stdin = argv, stdin

    def test_initialization_and_status_do_not_modify_the_network(self):
        self.assertTrue(self.call('init')['initialized'])
        self.assertFalse(self.call('status')['pending'])
        files = module.store_module.Store(module.STATE, module.RUNTIME)
        files.store({'version': 1, 'token': 'secret', 'expires_at': 123,
                     'change': {'device': 'eth0', 'uuid': 'id'}})
        files.set_pending()
        self.assertEqual({'pending': True, 'token': 'secret', 'expires_at': 123, 'device': 'eth0', 'uuid': 'id', 'kind': 'profile'},
                         self.call('status'))
        files.store({'version': 1, 'token': 'secret', 'expires_at': 123,
                     'change': {'kind': 'stand', 'target': {'device': 'eth0.42', 'uuid': 'target'}}})
        self.assertEqual({'pending': True, 'token': 'secret', 'expires_at': 123, 'device': 'eth0.42',
                          'uuid': 'target', 'kind': 'stand'}, self.call('status'))

    def test_active_run_query_ignores_old_runs_but_fails_closed_on_bad_dates(self):
        self.assertFalse(module.active_runs())
        with sqlite3.connect(module.DATABASE) as db:
            db.execute("INSERT INTO cms VALUES ('write', datetime('now','-2 days'))")
        self.assertFalse(module.active_runs())
        with sqlite3.connect(module.DATABASE) as db:
            db.execute("INSERT INTO cms VALUES ('verify', datetime('now','-5 minutes'))")
        self.assertTrue(module.active_runs())
        with sqlite3.connect(module.DATABASE) as db:
            db.execute("DELETE FROM cms WHERE phase='verify'")
            db.execute("INSERT INTO cms VALUES ('write', 'bad-date')")
        self.assertTrue(module.active_runs())

    def test_oversized_or_unexpected_requests_are_rejected(self):
        self.call('init')
        with self.assertRaises(RuntimeError): self.call('status', b'{"unexpected":1}')
        with self.assertRaises(RuntimeError): self.call('status', b' ' * 16385)

    def test_recovery_initializes_an_empty_runtime_after_reboot_or_in_a_chroot(self):
        self.assertTrue(self.call('recover')['recovered'])
        self.assertTrue(os.path.isfile(module.RUNTIME + '/provisioning.lock'))


unittest.main()
