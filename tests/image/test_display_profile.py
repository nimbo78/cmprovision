import importlib.util
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest


SPEC = importlib.util.spec_from_file_location(
    'image_display_profile', Path(__file__).resolve().parents[2] / 'image/fpms/prepare-display.py'
)
display = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(display)


class DisplayProfileTest(unittest.TestCase):
    def setUp(self):
        space = TemporaryDirectory()
        self.addCleanup(space.cleanup)
        self.root = Path(space.name)
        self.config = self.root / 'boot/firmware/config.txt'
        self.config.parent.mkdir(parents=True)
        self.original = '# Base Raspberry Pi OS settings\ndtoverlay=vc4-kms-v3d\n[pi5]\ndtoverlay=nospi10\n'
        self.config.write_text(self.original)
        self.unit = self.root / 'usr/lib/systemd/system/fpms-lite.service'
        self.unit.parent.mkdir(parents=True)
        self.unit.touch()
        watcher = self.root / 'usr/lib/cmprovision/image-fpms'
        watcher.parent.mkdir(parents=True)
        watcher.touch()
        self.fpms = self.root / 'etc/default/cmprovision-fpms.json'
        self.fpms.parent.mkdir(parents=True)

    def install_hyperpixel_overlay(self):
        overlay = self.config.parent / 'overlays/vc4-kms-dpi-hyperpixel4.dtbo'
        overlay.parent.mkdir()
        overlay.touch()

    def test_hyperpixel_configures_gpio_and_enables_fpms_before_first_boot(self):
        self.install_hyperpixel_overlay()
        display.prepare(self.root, 'hyperpixel-4-touch')
        self.assertEqual(
            self.config.read_text().splitlines()[-6:],
            ['# BEGIN cmprovision display profile', '[all]', 'dtparam=i2c_arm=off', 'dtparam=spi=off',
             'dtoverlay=vc4-kms-dpi-hyperpixel4', '# END cmprovision display profile'],
        )
        self.assertTrue(self.config.read_text().startswith(self.original))
        self.assertEqual(json.loads(self.fpms.read_text()), {'enabled': True, 'open_on_start': True})

    def test_reapplying_or_switching_a_profile_replaces_the_managed_block(self):
        self.install_hyperpixel_overlay()
        display.prepare(self.root, 'hyperpixel-4-touch')
        before = self.config.read_text()
        display.prepare(self.root, 'hyperpixel-4-touch')
        self.assertEqual(self.config.read_text(), before)
        display.prepare(self.root, 'waveshare-144')
        result = self.config.read_text()
        self.assertEqual(result.count('# BEGIN cmprovision display profile'), 1)
        self.assertNotIn('hyperpixel', result)
        self.assertNotIn('dtparam=i2c_arm=off', result)
        self.assertIn('[all]\ndtparam=spi=on\n', result)

    def test_refuses_a_booted_image(self):
        ready = self.root / 'var/lib/cmprovision-image/firstboot-ready'
        ready.parent.mkdir(parents=True)
        ready.touch()
        with self.assertRaisesRegex(ValueError, 'unbooted'):
            display.prepare(self.root, 'waveshare-144')
        self.assertEqual(self.config.read_text(), self.original)
        self.assertFalse(self.fpms.exists())

    def test_refuses_missing_display_prerequisites_before_writing(self):
        with self.assertRaisesRegex(ValueError, 'HyperPixel overlay'):
            display.prepare(self.root, 'hyperpixel-4-touch')
        self.unit.unlink()
        with self.assertRaisesRegex(ValueError, 'fpms-lite'):
            display.prepare(self.root, 'waveshare-144')
        self.assertEqual(self.config.read_text(), self.original)
        self.assertFalse(self.fpms.exists())

    def test_default_keeps_existing_boot_and_fpms_settings(self):
        display.prepare(self.root, 'default')
        self.assertEqual(self.config.read_text(), self.original)
        self.assertFalse(self.fpms.exists())
