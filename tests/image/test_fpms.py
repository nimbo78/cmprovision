#!/usr/bin/env python3
"""Exercise the image-only, opt-in FPMS service controller."""

import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest


SOURCE = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('image_fpms', SOURCE / 'image/fpms/cmprovision-image-fpms.py')
fpms = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fpms)


@unittest.skipUnless(os.geteuid() == 0, 'the production settings and marker are root-owned')
class ImageFpmsTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='cmp-image-fpms-')
        self.root = Path(self.temp.name)
        self.config = self.root / 'config.json'
        self.runtime = self.root / 'runtime'
        self.active = False
        self.calls = []

    def tearDown(self):
        self.temp.cleanup()

    def service(self, action):
        self.calls.append(action)
        if action == 'is-active':
            return self.active
        self.active = action == 'start'
        return True

    def settings(self, enabled):
        self.config.write_text(json.dumps({'enabled': enabled, 'open_on_start': False}))
        self.config.chmod(0o644)

    def test_off_on_off_starts_and_stops_the_display_once(self):
        fpms.sync(self.config, self.runtime, self.service)
        self.assertFalse((self.runtime / 'enabled').exists())
        self.assertNotIn('start', self.calls)
        self.settings(True)
        fpms.sync(self.config, self.runtime, self.service)
        self.assertTrue((self.runtime / 'enabled').is_file())
        self.assertTrue(self.active)
        fpms.sync(self.config, self.runtime, self.service)
        self.assertEqual(1, self.calls.count('start'))
        self.settings(False)
        fpms.sync(self.config, self.runtime, self.service)
        self.assertFalse(self.active)
        self.assertFalse((self.runtime / 'enabled').exists())
        self.assertEqual(1, self.calls.count('stop'))

    def test_corrupt_or_linked_settings_stop_the_optional_service(self):
        self.active = True
        self.config.symlink_to('/etc/passwd')
        fpms.sync(self.config, self.runtime, self.service)
        self.assertFalse(self.active)
        self.assertFalse((self.runtime / 'enabled').exists())

    def test_world_writable_settings_cannot_enable_the_service(self):
        self.settings(True)
        self.config.chmod(0o666)
        fpms.sync(self.config, self.runtime, self.service)
        self.assertFalse(self.active)
        self.assertNotIn('start', self.calls)

    def test_failed_start_is_reported_for_retry(self):
        self.settings(True)
        with self.assertRaises(RuntimeError):
            fpms.sync(self.config, self.runtime, lambda action: False)


if __name__ == '__main__':
    unittest.main()
