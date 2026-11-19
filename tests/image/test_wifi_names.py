"""Check the station image's Wi-Fi naming on simulated sysfs devices."""

import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


SOURCE = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    'image_wifi_names', SOURCE / 'image/network/cmprovision-image-wifi-names.py')
wifi = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wifi)


class WifiNamesTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.net = self.root / 'net'
        self.net.mkdir()

    def radio(self, name, driver, bus, wireless=True):
        path = self.net / name
        path.mkdir()
        if wireless:
            (path / 'phy80211').mkdir()
        device = self.root / 'devices' / name
        device.mkdir(parents=True)
        for kind, value in (('driver', driver), ('subsystem', bus)):
            target = self.root / kind / value
            target.mkdir(parents=True, exist_ok=True)
            (device / kind).symlink_to(target, target_is_directory=True)
        (path / 'device').symlink_to(device, target_is_directory=True)

    def test_inventory_matches_the_onboard_driver_and_bus(self):
        self.radio('wlan1', 'brcmfmac', 'sdio')
        self.radio('wlan0', 'iwlwifi', 'pci')
        self.radio('eth0', 'bcmgenet', 'platform', wireless=False)
        (self.net / 'p2p').mkdir()
        (self.net / 'p2p/phy80211').mkdir()
        self.assertEqual({'wlan1': True, 'wlan0': False}, wifi.inventory(self.net))

    def test_pairs_registered_in_both_orders_end_at_the_same_names(self):
        for links in ({'wlan0': True, 'wlan1': False}, {'wlan0': False, 'wlan1': True}):
            with self.subTest(links=links):
                actual = dict(links)
                moves = wifi.name_moves(links, set(links))
                wifi.apply_moves(moves, lambda old, new: self.move(actual, old, new))
                self.assertEqual({'wlan0': True, 'wlan1': False}, actual)

    def test_onboard_only_is_named_wlan0(self):
        links = {'wlp0s1': True}
        actual = dict(links)
        wifi.apply_moves(wifi.name_moves(links, set(links)), lambda old, new: self.move(actual, old, new))
        self.assertEqual({'wlan0': True}, actual)

    def test_late_additional_card_is_included_before_naming(self):
        pair = {'wlan0': False, 'wlan1': True}
        with patch.object(wifi, 'inventory', side_effect=[{'wlan1': True}, pair]), \
                patch.object(wifi.time, 'monotonic', return_value=0), patch.object(wifi.time, 'sleep'):
            self.assertEqual(pair, wifi.wait_for_radios(self.net))

    def test_no_onboard_or_ambiguous_hardware_is_left_alone(self):
        for links in ({'wlan0': False}, {'wlan0': False, 'wlan1': True, 'wlan2': False},
                      {'wlan0': True, 'wlan1': True}, {}):
            with self.subTest(links=links):
                self.assertEqual([], wifi.name_moves(links, set(links)))

    def test_unrelated_interfaces_cannot_be_renamed(self):
        links = {'wlan1': True}
        for collision in ('wlan0', 'cmpwifi0tmp'):
            with self.subTest(collision=collision), self.assertRaisesRegex(ValueError, 'occupied'):
                wifi.name_moves(links, set(links) | {collision})

    def test_a_failed_swap_restores_the_original_names(self):
        original = {'wlan0': False, 'wlan1': True}
        actual = dict(original)
        failed = False

        def rename(old, new):
            nonlocal failed
            if new == 'wlan1' and not failed:
                failed = True
                raise OSError('rename failed')
            self.move(actual, old, new)

        with self.assertRaisesRegex(OSError, 'rename failed'):
            wifi.apply_moves(wifi.name_moves(original, set(original)), rename)
        self.assertEqual(original, actual)

    def move(self, links, old, new):
        self.assertNotIn(new, links)
        links[new] = links.pop(old)


if __name__ == '__main__':
    unittest.main()
