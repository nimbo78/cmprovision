#!/usr/bin/env python3
"""Check that image export rejects shared credentials and missing startup gates."""

import importlib.util
import os
from pathlib import Path
import tempfile
import unittest


SOURCE = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('image_audit', SOURCE / 'image/audit-rootfs.py')
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)


@unittest.skipUnless(os.geteuid() == 0, 'image helper ownership must be root')
class RootfsAuditTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='cmp-image-audit-')
        self.root = Path(self.temp.name)
        self.write('etc/shadow', 'root:!:1:0:99999:7:::\npi:*:1:0:99999:7:::\n')
        self.write('var/lib/cmprovision/.env', 'APP_ENV=production\nAPP_KEY=\n')
        (self.root / 'etc/ssh').mkdir(parents=True)
        self.units = self.root / 'etc/systemd/system'
        self.units.mkdir(parents=True)
        for name in ('cmprovision-dnsmasq', 'cmprovision-rpiboot'):
            (self.units / (name + '.service')).symlink_to('/dev/null')
        for name in ('ssh', 'nginx', 'cmprovision-queue', 'cmprovision-queue-images',
                     'cmprovision-wallboard-data', 'cmprovision-fpms-data'):
            self.write('etc/systemd/system/' + name + '.service.d/image-firstboot.conf',
                       '[Unit]\nRequires=cmprovision-image-firstboot.service\nAfter=cmprovision-image-firstboot.service\n')
        wants = self.units / 'multi-user.target.wants'
        wants.mkdir()
        (wants / 'cmprovision-image-firstboot.service').symlink_to('../cmprovision-image-firstboot.service')
        self.write('usr/lib/cmprovision/image-firstboot', '#!/bin/sh\nexit 0\n')
        (self.root / 'usr/lib/cmprovision/image-firstboot').chmod(0o755)

    def tearDown(self):
        self.temp.cleanup()

    def write(self, relative, text):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)

    def test_safe_image_passes(self):
        audit.audit(self.root)

    def test_shared_key_is_rejected(self):
        self.write('var/lib/cmprovision/.env', 'APP_KEY=base64:shared\n')
        with self.assertRaises(ValueError):
            audit.audit(self.root)

    def test_unlocked_root_is_rejected(self):
        self.write('etc/shadow', 'root:$y$hash:1:0:99999:7:::\npi:*:1:0:99999:7:::\n')
        with self.assertRaises(ValueError):
            audit.audit(self.root)

    def test_build_time_ssh_host_key_is_rejected(self):
        self.write('etc/ssh/ssh_host_ed25519_key', 'not a real key')
        with self.assertRaises(ValueError):
            audit.audit(self.root)

    def test_missing_firstboot_dependency_is_rejected(self):
        (self.units / 'nginx.service.d/image-firstboot.conf').unlink()
        with self.assertRaises(OSError):
            audit.audit(self.root)

    def test_wrong_enable_target_is_rejected(self):
        enabled = self.units / 'multi-user.target.wants/cmprovision-image-firstboot.service'
        enabled.unlink()
        enabled.symlink_to('/dev/null')
        with self.assertRaises(ValueError):
            audit.audit(self.root)


if __name__ == '__main__':
    unittest.main()
