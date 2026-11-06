#!/usr/bin/env python3
"""Test image staging in a temporary root without running or installing a package."""

import hashlib
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


SOURCE = Path(__file__).resolve().parents[2]
STAGE = SOURCE / 'image/pi-gen/stage-cmprovision/00-install/00-run.sh'


@unittest.skipUnless(shutil.which('dpkg-deb') and shutil.which('bash'), 'Debian build tools are required')
class ImageStagingTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='cmp-image-stage-')
        self.root = Path(self.temp.name)
        control = self.root / 'package/DEBIAN'
        control.mkdir(parents=True)
        (control / 'control').write_text(
            'Package: cmprovision4\nVersion: 1\nArchitecture: all\n'
            'Maintainer: Test <test@example.invalid>\nDescription: test package\n'
        )
        self.deb = self.root / 'package.deb'
        subprocess.run(['dpkg-deb', '--build', str(control.parent), str(self.deb)],
                       check=True, stdout=subprocess.DEVNULL)
        self.rootfs = self.root / 'rootfs'
        self.rootfs.mkdir()
        self.environment = dict(os.environ, ROOTFS_DIR=str(self.rootfs), CMP_IMAGE_SOURCE=str(SOURCE),
                                CMP_IMAGE_DEB=str(self.deb),
                                CMP_IMAGE_DEB_SHA256=hashlib.sha256(self.deb.read_bytes()).hexdigest())

    def tearDown(self):
        self.temp.cleanup()

    def run_stage(self):
        return subprocess.run(['bash', str(STAGE)], env=self.environment, capture_output=True, text=True)

    def test_provisioning_is_masked_and_services_wait_for_firstboot(self):
        result = self.run_stage()
        self.assertEqual(0, result.returncode, result.stderr)
        unitdir = self.rootfs / 'etc/systemd/system'
        for name in ('cmprovision-dnsmasq', 'cmprovision-rpiboot'):
            self.assertEqual('/dev/null', os.readlink(unitdir / (name + '.service')))
        for name in ('ssh', 'nginx', 'cmprovision-queue', 'cmprovision-queue-images',
                     'cmprovision-wallboard-data', 'cmprovision-fpms-data'):
            dropin = unitdir / (name + '.service.d') / 'image-firstboot.conf'
            self.assertIn('Requires=cmprovision-image-firstboot.service', dropin.read_text())
        self.assertTrue((self.rootfs / 'opt/cmprovision-image.deb').is_file())

    def test_wrong_checksum_stops_before_any_files_are_installed(self):
        self.environment['CMP_IMAGE_DEB_SHA256'] = '0' * 64
        self.assertNotEqual(0, self.run_stage().returncode)
        self.assertEqual([], list(self.rootfs.iterdir()))

    def test_other_package_is_not_accepted_even_with_a_matching_checksum(self):
        control = self.root / 'package/DEBIAN/control'
        control.write_text(control.read_text().replace('Package: cmprovision4', 'Package: unrelated'))
        subprocess.run(['dpkg-deb', '--build', str(control.parent.parent), str(self.deb)],
                       check=True, stdout=subprocess.DEVNULL)
        self.environment['CMP_IMAGE_DEB_SHA256'] = hashlib.sha256(self.deb.read_bytes()).hexdigest()
        self.assertNotEqual(0, self.run_stage().returncode)
        self.assertEqual([], list(self.rootfs.iterdir()))

    def test_wrong_architecture_is_rejected_before_installing_files(self):
        control = self.root / 'package/DEBIAN/control'
        control.write_text(control.read_text().replace('Architecture: all', 'Architecture: amd64'))
        subprocess.run(['dpkg-deb', '--build', str(control.parent.parent), str(self.deb)],
                       check=True, stdout=subprocess.DEVNULL)
        self.environment['CMP_IMAGE_DEB_SHA256'] = hashlib.sha256(self.deb.read_bytes()).hexdigest()
        self.assertNotEqual(0, self.run_stage().returncode)
        self.assertEqual([], list(self.rootfs.iterdir()))

    def test_optional_fpms_is_staged_with_a_guarded_service(self):
        control = self.root / 'package/DEBIAN/control'
        control.write_text(control.read_text().replace('Package: cmprovision4', 'Package: fpms-lite'))
        deb = self.root / 'fpms.deb'
        subprocess.run(['dpkg-deb', '--build', str(control.parent.parent), str(deb)],
                       check=True, stdout=subprocess.DEVNULL)
        self.environment['CMP_IMAGE_FPMS'] = str(deb)
        self.environment['CMP_IMAGE_FPMS_SHA256'] = hashlib.sha256(deb.read_bytes()).hexdigest()
        result = self.run_stage()
        self.assertEqual(0, result.returncode, result.stderr)
        units = self.rootfs / 'etc/systemd/system'
        self.assertTrue((self.rootfs / 'opt/cmprovision-image-fpms.deb').is_file())
        self.assertIn('ConditionPathExists=/run/cmprovision-image-fpms/enabled',
                      (units / 'fpms-lite.service.d/cmprovision-image.conf').read_text())
        self.assertTrue((units / 'cmprovision-image-fpms.path').is_file())
        self.assertTrue((units / 'cmprovision-image-fpms.timer').is_file())

    def test_invalid_optional_package_stops_before_staging_anything(self):
        self.environment['CMP_IMAGE_FPMS'] = str(self.deb)
        self.environment['CMP_IMAGE_FPMS_SHA256'] = '0' * 64
        self.assertNotEqual(0, self.run_stage().returncode)
        self.assertEqual([], list(self.rootfs.iterdir()))


if __name__ == '__main__':
    unittest.main()
