#!/usr/bin/env python3
"""Exercise first-boot coordination with real files and processes."""

import importlib.util
import base64
import multiprocessing
import os
from pathlib import Path
import pwd
import secrets
import tempfile
import time
import unittest


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('image_firstboot', ROOT / 'image/firstboot/cmprovision-image-firstboot.py')
firstboot = importlib.util.module_from_spec(spec)
spec.loader.exec_module(firstboot)


def prepare(app, state):
    def run(path, operation):
        if operation == 'key:generate':
            time.sleep(0.2)
            with (path / 'calls').open('a') as log:
                log.write('generated\n')
            key = base64.b64encode(secrets.token_bytes(32)).decode()
            env = path / '.env'
            env.write_text('APP_KEY=base64:' + key + '\n')
            os.chown(env, pwd.getpwnam('www-data').pw_uid, -1)
            env.chmod(0o600)
    firstboot.initialize(app, state, run)


@unittest.skipUnless(os.geteuid() == 0, 'the production state files must be root-owned')
class FirstBootTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='cmp-image-firstboot-')
        self.base = Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def station(self, name):
        root = self.base / name
        root.mkdir()
        app = root / 'app'
        app.mkdir()
        return app, root / 'state'

    def test_two_stations_get_distinct_keys_and_a_restart_keeps_them(self):
        a, a_state = self.station('a')
        b, b_state = self.station('b')
        prepare(a, a_state)
        prepare(b, b_state)
        key = (a / '.env').read_text()
        self.assertNotEqual(key, (b / '.env').read_text())
        prepare(a, a_state)
        self.assertEqual(key, (a / '.env').read_text())
        self.assertEqual(['generated'], (a / 'calls').read_text().splitlines())

    def test_failed_initialization_leaves_no_marker_and_can_retry(self):
        app, state = self.station('failure')
        def fail(path, operation):
            raise RuntimeError('failed')
        with self.assertRaises(RuntimeError):
            firstboot.initialize(app, state, fail)
        self.assertFalse((state / 'firstboot-ready').exists())
        prepare(app, state)
        self.assertTrue((state / 'firstboot-ready').is_file())

    def test_parallel_boot_attempts_generate_only_once(self):
        app, state = self.station('parallel')
        processes = [multiprocessing.Process(target=prepare, args=(app, state)) for _ in range(2)]
        for process in processes:
            process.start()
        for process in processes:
            process.join(10)
            self.assertEqual(0, process.exitcode)
        self.assertEqual(['generated'], (app / 'calls').read_text().splitlines())

    def test_untrusted_state_directory_is_rejected(self):
        app, state = self.station('untrusted')
        state.mkdir(mode=0o777)
        state.chmod(0o777)
        with self.assertRaises(RuntimeError):
            prepare(app, state)
        self.assertFalse((app / '.env').exists())

    def test_missing_key_does_not_unblock_services(self):
        app, state = self.station('missing-key')
        (app / '.env').write_text('APP_KEY=\n')
        os.chown(app / '.env', pwd.getpwnam('www-data').pw_uid, -1)
        (app / '.env').chmod(0o600)
        with self.assertRaises(RuntimeError):
            firstboot.initialize(app, state, lambda path, operation: None)
        self.assertFalse((state / 'firstboot-ready').exists())

    def test_interrupted_project_setup_blocks_services_until_its_receipt_is_committed(self):
        app, state = self.station('setup-interrupted')
        state.mkdir(mode=0o700)
        pending = state / 'project-setup-pending.json'
        pending.write_text('{"digest":"test"}')
        pending.chmod(0o600)
        with self.assertRaisesRegex(RuntimeError, 'interrupted'):
            prepare(app, state)
        self.assertFalse((app / '.env').exists())
        receipt = state / 'project-setup.json'
        receipt.write_text(pending.read_text())
        receipt.chmod(0o600)
        prepare(app, state)
        self.assertFalse(pending.exists())
        self.assertTrue((state / 'firstboot-ready').exists())

    def test_ssh_key_failure_does_not_unblock_services(self):
        app, state = self.station('ssh-key-failure')
        def run(path, operation):
            if operation == 'ssh-host-keys':
                raise RuntimeError('SSH key generation failed')
        with self.assertRaises(RuntimeError):
            firstboot.initialize(app, state, run)
        self.assertFalse((state / 'firstboot-ready').exists())

    def test_symlink_marker_is_not_trusted(self):
        app, state = self.station('symlink')
        state.mkdir(mode=0o700)
        (state / 'firstboot-ready').symlink_to('/etc/passwd')
        with self.assertRaises(OSError):
            prepare(app, state)
        self.assertFalse((app / '.env').exists())


if __name__ == '__main__':
    unittest.main()
