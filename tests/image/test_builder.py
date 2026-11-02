#!/usr/bin/env python3
"""Validate that unsafe image inputs are rejected before cloning or building."""

import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


SOURCE = Path(__file__).resolve().parents[2]
BUILDER = SOURCE / 'image/build.sh'


@unittest.skipUnless(os.geteuid() == 0, 'the image builder is root-only')
class BuilderSafetyTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='cmp-image-builder-')
        self.root = Path(self.tmp.name)
        self.package = self.root / 'package.deb'
        self.package.write_bytes(b'test input, not a Debian package')
        self.digest = hashlib.sha256(self.package.read_bytes()).hexdigest()

    def tearDown(self):
        self.tmp.cleanup()

    def run_builder(self, work, digest):
        return subprocess.run(['bash', str(BUILDER), str(work), str(self.package), digest],
                              capture_output=True, text=True, timeout=10)

    def test_bad_checksum_does_not_create_build_directory(self):
        work = self.root / 'work'
        self.assertNotEqual(0, self.run_builder(work, '0' * 64).returncode)
        self.assertFalse(work.exists())

    def test_existing_build_directory_is_never_removed(self):
        work = self.root / 'work'
        work.mkdir()
        marker = work / 'keep'
        marker.write_text('preserve')
        self.assertNotEqual(0, self.run_builder(work, self.digest).returncode)
        self.assertEqual('preserve', marker.read_text())


if __name__ == '__main__':
    unittest.main()
