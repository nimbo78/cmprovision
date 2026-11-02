#!/usr/bin/env python3
import importlib.machinery
import multiprocessing
import os
import stat
import sys
import tempfile
import unittest

module = importlib.machinery.SourceFileLoader('network_store', sys.argv.pop()).load_module()


def hold_guard(store, ready, release):
    with store.guard():
        ready.send(True)
        release.recv()


class StoreTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='cmp-store-')
        self.state = os.path.join(self.tmp.name, 'state')
        self.runtime = os.path.join(self.tmp.name, 'runtime')
        self.store = module.Store(self.state, self.runtime, verify_root=False)

    def tearDown(self):
        self.tmp.cleanup()

    def test_state_is_private_and_lock_is_readable_even_with_restrictive_umask(self):
        old = os.umask(0o077)
        try:
            self.store.initialize()
            self.store.store({'version': 1, 'token': 'secret', 'original': {'address': '192.0.2.1'}})
        finally:
            os.umask(old)
        self.assertEqual(0o700, stat.S_IMODE(os.stat(self.state).st_mode))
        self.assertEqual(0o755, stat.S_IMODE(os.stat(self.runtime).st_mode))
        self.assertEqual(0o644, stat.S_IMODE(os.stat(self.store.lock_path).st_mode))
        self.assertEqual(0o600, stat.S_IMODE(os.stat(self.store.state_path).st_mode))
        self.assertEqual('secret', self.store.load()['token'])

    def test_boot_recreates_pending_marker_before_accepting_runs(self):
        self.store.initialize()
        self.store.store({'version': 1, 'token': 'secret'})
        os.unlink(self.store.lock_path)
        self.store.initialize()
        self.assertTrue(self.store.pending())
        self.assertTrue(os.path.isfile(self.store.lock_path))

    def test_corrupt_recovery_state_fails_closed(self):
        self.store.initialize()
        with open(self.store.state_path, 'w', encoding='utf-8') as output: output.write('{broken')
        os.chmod(self.store.state_path, 0o600)
        os.unlink(self.store.lock_path)
        with self.assertRaises(module.UnsafeStore): self.store.initialize()
        self.assertTrue(self.store.pending())
        self.assertFalse(os.path.exists(self.store.lock_path))

    def test_cross_process_guard_excludes_another_network_operation(self):
        self.store.initialize()
        ready_read, ready_write = multiprocessing.Pipe(duplex=False)
        release_read, release_write = multiprocessing.Pipe(duplex=False)
        proc = multiprocessing.Process(target=hold_guard, args=(self.store, ready_write, release_read))
        proc.start()
        try:
            self.assertTrue(ready_read.poll(5))
            self.assertTrue(ready_read.recv())
            with self.assertRaises(module.UnsafeStore):
                with self.store.guard(timeout=.1): pass
        finally:
            release_write.send(True)
            proc.join(5)
        self.assertEqual(0, proc.exitcode)
        with self.store.guard(timeout=.1): pass

    def test_symlinked_state_directory_and_public_state_are_rejected(self):
        os.symlink(self.tmp.name, self.state)
        with self.assertRaises(module.UnsafeStore): self.store.initialize()
        os.unlink(self.state)
        self.store.initialize()
        self.store.store({'version': 1, 'token': 'secret'})
        os.chmod(self.store.state_path, 0o644)
        with self.assertRaises(module.UnsafeStore): self.store.load()


unittest.main()
