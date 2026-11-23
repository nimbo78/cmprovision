import errno
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock
import tempfile

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools'))
try:
    import display_boot_probe as probe
except ModuleNotFoundError as error:
    if error.name != 'display_boot_probe':
        raise
    probe = None


def missing(*_):
    raise OSError(errno.ENXIO, 'no address acknowledgement')


class DisplayBootProbeTest(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(probe, 'The station package needs a detector independent of fpms-lite')
        self.identity = bytes.fromhex('393131006010')
        self.geometry = bytes.fromhex('e0012003')
        self.spi = Mock(side_effect=AssertionError('SPI must stay idle'))

    def chip(self, address, register, length):
        if address != 0x14:
            return missing()
        return {0x8140: self.identity, 0x8048: self.geometry}[register][:length]

    def test_neutral_d62_capture_identifies_rectangular_touch_without_spi(self):
        read = Mock(side_effect=self.chip)
        self.assertEqual(probe.identify(read, self.spi), 'hyperpixel-4-touch')
        self.assertGreaterEqual(read.call_args_list.count(unittest.mock.call(0x14, 0x8140, 6)), 2)
        self.spi.assert_not_called()

    def test_wrong_geometry_corrupt_or_changing_identity_does_not_enable_spi(self):
        for second, geometry in [(self.identity, bytes.fromhex('d002d002')),
                                 (b'911\x00xx', self.geometry), (b'', self.geometry)]:
            with self.subTest(second=second, geometry=geometry):
                replies = iter((self.identity, second))
                def read(address, register, length):
                    if address != 0x14: return missing()
                    return next(replies) if register == 0x8140 else geometry
                self.assertIsNone(probe.identify(read, self.spi))
        self.spi.assert_not_called()

    def test_bus_error_is_not_an_absent_i2c_device(self):
        bad_bus = Mock(side_effect=OSError(errno.ETIMEDOUT, 'SCL did not release'))
        self.assertIsNone(probe.identify(bad_bus, self.spi))
        self.spi.assert_not_called()

    def test_waveshare_requires_repeated_matching_id_and_pixel_format(self):
        combined = ((0x7c89f0 << 7) | 0x7f).to_bytes(4, 'big')
        regs = {0xda: b'\x7c', 0xdb: b'\x89', 0xdc: b'\xf0', 0x04: combined, 0x0c: b'\x05'}
        self.assertEqual(probe.identify(missing, lambda cmd, size: regs[cmd]), 'waveshare-144')
        regs[0xdc] = b'\xf1'
        self.assertIsNone(probe.identify(missing, lambda cmd, size: regs[cmd]))
        self.assertIsNone(probe.identify(missing, lambda cmd, size: b'\xff' * size))

    def test_two_touch_controllers_are_ambiguous(self):
        def both(address, register, length):
            return self.chip(0x14, register, length)
        self.assertIsNone(probe.identify(both, self.spi))
        self.spi.assert_not_called()

    def test_gpiochip_uses_character_device_and_real_controller_label(self):
        self.assertTrue(hasattr(probe, 'select_chip'), 'CM4 sysfs base 512 is not a character-device number')
        candidates = {Path('/dev/gpiochip0'): 'ftdi-cbus', Path('/dev/gpiochip4'): 'pinctrl-bcm2711'}
        self.assertEqual(probe.select_chip(candidates, candidates.get), Path('/dev/gpiochip4'))
        candidates[Path('/dev/gpiochip5')] = 'pinctrl-bcm2711'
        self.assertIsNone(probe.select_chip(candidates, candidates.get))

    def test_raspberry_pi_gpiochip4_compatibility_alias_is_one_controller(self):
        with tempfile.TemporaryDirectory() as temp:
            gpio = Path(temp) / 'gpiochip0'
            gpio.touch()
            alias = Path(temp) / 'gpiochip4'
            try:
                alias.symlink_to(gpio)
            except OSError:
                self.skipTest('Host cannot create a symlink')
            self.assertEqual(probe.select_chip([gpio, alias], lambda _: 'pinctrl-bcm2711'), gpio)

    def test_bus_releases_after_address_nack_and_distinguishes_a_data_nack(self):
        self.assertTrue(hasattr(probe, 'Header'), 'Missing neutral GPIO transport')
        for replies, code in [([False], errno.ENXIO), ([True, False], errno.EIO)]:
            with self.subTest(replies=replies):
                bus = Mock(spec=probe.Header)
                bus.write_byte.side_effect = replies
                with self.assertRaises(OSError) as caught:
                    probe.Header.read_i2c(bus, 0x14, 0x8140, 6)
                self.assertEqual(caught.exception.errno, code)
                bus.stop.assert_called_once()

    def test_spi_always_deselects_the_panel_after_a_failed_read(self):
        self.assertTrue(hasattr(probe, 'Header'), 'Missing neutral GPIO transport')
        bus = Mock(spec=probe.Header)
        bus.spi_ready = True
        bus.spi_byte.side_effect = OSError(errno.EIO, 'input failed')
        with self.assertRaises(OSError):
            probe.Header.read_spi(bus, 0x04, 4)
        self.assertIn(unittest.mock.call(8, True), bus.drive.call_args_list)


if __name__ == '__main__':
    unittest.main()
