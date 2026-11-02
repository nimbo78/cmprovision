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

    def test_builder_clears_inherited_secrets_and_writes_manifests(self):
        fakebin = self.root / 'bin'
        fakebin.mkdir()
        git = fakebin / 'git'
        git.write_text('''#!/bin/bash
set -eu
if [ "$1" = clone ]; then
    target="${@: -1}"
    mkdir -p "$target/stage2/04-cloud-init"
    cat > "$target/build.sh" <<'BUILD'
#!/bin/bash
set -eu
if [ "${TEST_SECRET+x}" = x ] || [ "${WPA_PASSPHRASE+x}" = x ]; then exit 11; fi
BASE_DIR="$PWD"
source "$2"
test -z "$FIRST_USER_PASS"
test -z "$PUBKEY_SSH_FIRST_USER"
test "$ENABLE_CLOUD_INIT" = 0
test "$ENABLE_SSH" = 0
test -f stage2/04-cloud-init/SKIP
test -f stage2/SKIP_IMAGES
test -f "$CMP_IMAGE_FPMS"
test -n "$CMP_IMAGE_FPMS_SHA256"
mkdir -p deploy
printf image > deploy/test.img.xz
BUILD
    chmod +x "$target/build.sh"
elif [ "$1" != -C ]; then
    exit 12
fi
''')
        git.chmod(0o755)
        env = dict(os.environ, PATH=str(fakebin) + ':' + os.environ['PATH'],
                   TEST_SECRET='not for the image', FIRST_USER_PASS='not for the image',
                   WPA_PASSPHRASE='not for the image', PUBKEY_SSH_FIRST_USER='not for the image')
        work = self.root / 'fresh-work'
        fpms_package = self.root / 'fpms.deb'
        fpms_package.write_bytes(b'test optional package')
        fpms_hash = hashlib.sha256(fpms_package.read_bytes()).hexdigest()
        result = subprocess.run(['bash', str(BUILDER), str(work), str(self.package), self.digest,
                                 str(fpms_package), fpms_hash],
                                env=env, capture_output=True, text=True, timeout=10)
        self.assertEqual(0, result.returncode, result.stderr)
        deploy = work / 'pi-gen/deploy'
        self.assertTrue((deploy / 'SHA256SUMS').is_file())
        self.assertTrue((deploy / 'cmprovision-image-source.sha256').is_file())
        self.assertIn(self.digest, (deploy / 'cmprovision-build-manifest.txt').read_text())
        self.assertIn(fpms_hash, (deploy / 'cmprovision-build-manifest.txt').read_text())


if __name__ == '__main__':
    unittest.main()
