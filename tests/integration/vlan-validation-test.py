#!/usr/bin/env python3
import importlib.machinery
import sys
import unittest

helper = importlib.machinery.SourceFileLoader('vlan_networks', sys.argv.pop()).load_module()


class ValidationTest(unittest.TestCase):
    def setUp(self):
        self.devices = [{'ifname': 'eth0', 'addr_info': [
            {'family': 'inet', 'scope': 'global', 'local': '192.0.2.10', 'prefixlen': 24},
        ]}]

    def valid(self, parent='eth0', vlan_id=42, address='198.51.100.3/24'):
        return helper.validate(parent, vlan_id, address, self.devices, lambda p: p == 'eth0')

    def test_valid_address(self):
        self.assertEqual('198.51.100.3/24', self.valid())

    def test_rejects_invalid_tags_and_parent(self):
        for tag in [0, 4095, True, '42', -1]:
            with self.subTest(tag=tag), self.assertRaises(helper.Refused):
                self.valid(vlan_id=tag)
        for parent in ['../eth0', 'eth0;touch', 'eth1', '']:
            with self.subTest(parent=parent), self.assertRaises(helper.Refused):
                self.valid(parent=parent)

    def test_rejects_overlap_and_invalid_hosts(self):
        for address in ['172.20.1.5/16', '172.16.1.5/12', '192.0.2.50/24', '198.51.100.0/24',
                        '198.51.100.255/24', '127.0.0.1/24', '224.0.0.1/24', '0.1.2.3/24', '240.0.0.1/24',
                        'not-an-address', '::1/64']:
            with self.subTest(address=address), self.assertRaises(helper.Refused):
                self.valid(address=address)


unittest.main()
