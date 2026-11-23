import json
from contextlib import nullcontext
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools'))
try:
    import display_auto
except ModuleNotFoundError as error:
    if error.name != 'display_auto':
        raise
    display_auto = None


class AutoDisplayTest(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(display_auto, 'The station needs a boot coordinator')
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.boot = self.root / 'firmware'
        self.boot.mkdir()
        (self.boot / 'config.txt').write_text('dtoverlay=vc4-kms-v3d\n[all]\n')
        (self.boot / 'overlays').mkdir()
        (self.boot / 'overlays' / 'vc4-kms-dpi-hyperpixel4.dtbo').touch()
        self.config = self.root / 'fpms.json'
        self.state = self.root / 'state'
        self.state.mkdir()
        self.io = Mock()
        self.io.boot = 'boot-1'
        self.io.tryboot = False
        self.io.now.return_value = 10000
        self.io.profile.return_value = 'hyperpixel-4-touch'
        self.io.installed.return_value = True
        self.io.admission.return_value = nullcontext()
        self.io.idle.return_value = True
        self.io.active_panel.return_value = None
        self.io.spi_ready.return_value = False
        self.session = display_auto.AutoDisplay(self.boot, self.state, self.config, self.io)

    def test_confirmed_hyperpixel_uses_one_trial_then_returns_to_neutral_next_boot(self):
        normal = (self.boot / 'config.txt').read_bytes()
        self.assertEqual(self.session.step(), 'restarting')
        self.assertEqual(self.session.step(), 'restarting')
        self.io.reboot.assert_called_once()
        self.assertEqual((self.boot / 'config.txt').read_bytes(), normal)
        self.assertIn('dtoverlay=vc4-kms-dpi-hyperpixel4', (self.boot / 'tryboot.txt').read_text())
        self.io.boot, self.io.tryboot = 'boot-2', True
        self.io.active_panel.return_value = 'hyperpixel-4-touch'
        self.assertEqual(self.session.step(), 'active')
        self.assertFalse((self.boot / 'tryboot.txt').exists())
        self.assertEqual(self.io.reboot.call_count, 1)
        self.io.boot, self.io.tryboot = 'boot-3', False
        self.io.active_panel.return_value = None
        self.io.profile.return_value = None
        self.assertEqual(self.session.step(), 'no-panel')
        self.assertEqual(self.io.reboot.call_count, 1)

    def test_missing_package_uses_installer_once_while_offline_then_resumes(self):
        self.io.installed.return_value = False
        self.assertEqual(self.session.step(), 'install-pending')
        self.assertEqual(self.session.step(), 'install-pending')
        self.io.install.assert_called_once()
        self.io.reboot.assert_not_called()
        self.assertFalse((self.boot / 'tryboot.txt').exists())
        self.io.installed.return_value = True
        self.assertEqual(self.session.step(), 'restarting')

    def test_does_not_install_or_reboot_on_uncertain_or_busy_station(self):
        self.io.profile.return_value = None
        self.assertEqual(self.session.step(), 'no-panel')
        self.io.profile.return_value = 'hyperpixel-4-touch'
        self.io.boot = 'boot-2'
        self.io.idle.return_value = False
        self.assertEqual(self.session.step(), 'busy')
        self.io.reboot.assert_not_called()
        self.io.install.assert_not_called()
        self.assertFalse(self.config.exists())

    def test_explicit_off_and_unowned_boot_files_are_preserved(self):
        self.config.write_text(json.dumps({'enabled': False, 'open_on_start': False}))
        self.assertEqual(self.session.step(), 'off')
        self.io.profile.assert_not_called()
        self.config.unlink()
        for name in ('tryboot.txt', 'autoboot.txt'):
            with self.subTest(name=name):
                p = self.boot / name
                p.write_text('[all]\n# third-party boot flow\n')
                self.assertEqual(self.session.step(), 'manual')
                self.assertEqual(p.read_text(), '[all]\n# third-party boot flow\n')
                p.unlink()
        self.io.reboot.assert_not_called()

    def test_unconfirmed_trial_is_not_repeated_after_power_loss(self):
        self.assertEqual(self.session.step(), 'restarting')
        self.io.boot = 'boot-after-power-loss'
        self.assertEqual(self.session.step(), 'trial-failed')
        self.assertFalse((self.boot / 'tryboot.txt').exists())
        self.assertEqual(self.session.step(), 'trial-failed')
        self.io.reboot.assert_called_once()

    def test_waveshare_enables_spi_and_menu_without_reboot(self):
        self.io.profile.return_value = 'waveshare-144'
        self.io.enable_spi.return_value = True
        self.assertEqual(self.session.step(), 'active')
        self.io.enable_spi.assert_called_once()
        self.io.start.assert_called_once()
        self.io.reboot.assert_not_called()
        self.assertTrue(json.loads(self.config.read_text())['enabled'])

    def test_unverified_second_boot_eventually_releases_the_trial_and_stays_available(self):
        self.assertEqual(self.session.step(), 'restarting')
        self.io.boot, self.io.tryboot = 'boot-2', True
        self.assertEqual(self.session.step(), 'waiting-for-panel')
        self.io.now.return_value += 121
        self.assertEqual(self.session.step(), 'trial-failed')
        self.assertFalse((self.boot / 'tryboot.txt').exists())
        self.io.reboot.assert_called_once()

    def test_unconfirmed_reboot_request_expires_without_scheduling_again(self):
        self.assertEqual(self.session.step(), 'restarting')
        self.io.now.return_value += 121
        self.assertEqual(self.session.step(), 'trial-failed')
        self.assertFalse((self.boot / 'tryboot.txt').exists())
        self.io.reboot.assert_called_once()

    def test_existing_fixed_profile_can_install_but_keeps_its_configuration(self):
        p = self.boot / 'config.txt'
        original = p.read_text() + 'dtoverlay=vc4-kms-dpi-hyperpixel4\n'
        p.write_text(original)
        self.io.active_panel.return_value = 'hyperpixel-4-touch'
        self.io.installed.return_value = False
        self.assertEqual(self.session.step(), 'install-pending')
        self.io.install.assert_called_once()
        self.io.profile.assert_not_called()
        self.assertEqual(p.read_text(), original)
        self.io.reboot.assert_not_called()
