"""Root-only state paths must remain safe even with malicious files below them."""
import importlib.util
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import unittest

TOOLS = Path(__file__).resolve().parents[2]/'tools'
sys.path.insert(0, str(TOOLS))


@unittest.skipUnless(os.name == 'posix', 'Linux file ownership/locks')
class StoreTest(unittest.TestCase):
    def setUp(self):
        if not (TOOLS/'rescue_wifi.py').exists():
            self.fail('The root rescue controller/store has not been implemented.')
        from rescue_wifi import Store, RescueError
        self.Store, self.Error = Store, RescueError
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.store = Store(self.root, verify_root=False)
        self.store.initialize()

    def test_private_config_and_public_status_are_separate(self):
        self.store.write(self.store.config, {'password':'private-password'})
        self.store.write(self.store.status, {'state':'waiting'}, public=True)
        self.assertEqual(0o600, stat.S_IMODE(self.store.config.stat().st_mode))
        self.assertEqual(0o644, stat.S_IMODE(self.store.status.stat().st_mode))
        self.assertNotIn('password', self.store.status.read_text())

    def test_config_symlink_is_rejected_without_reading_target(self):
        target = self.root/'target'
        target.write_text('{"password":"secret"}')
        self.store.config.symlink_to(target)
        with self.assertRaises(self.Error):
            self.store.read(self.store.config)
        with self.assertRaises(self.Error):
            self.store.write(self.store.config, {})
        self.assertIn('secret', target.read_text())

    def test_insecure_directory_cannot_be_a_root_output_target(self):
        os.chmod(self.store.status.parent, 0o777)
        with self.assertRaises(self.Error):
            self.store.write(self.store.status, {}, public=True)

    def test_two_workers_cannot_own_radio_mutation_at_once(self):
        other = self.Store(self.root, verify_root=False)
        with self.store.lock():
            with self.assertRaises(self.Error), other.lock():
                self.fail('Second worker acquired an already-held radio lock.')
        with other.lock():
            pass

    def test_large_and_non_json_state_fail_closed(self):
        self.store.write(self.store.config, {})
        self.store.config.write_text('[')
        with self.assertRaises(self.Error):
            self.store.read(self.store.config)
        self.store.config.write_text('x'*65537)
        with self.assertRaises(self.Error):
            self.store.read(self.store.config)


if __name__ == '__main__':
    unittest.main()
