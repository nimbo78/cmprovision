#!/usr/bin/env python3
import importlib.machinery
import sys
import unittest

profile = importlib.machinery.SourceFileLoader('network_profile', sys.argv.pop()).load_module()


class ProfileTest(unittest.TestCase):
    def test_ipv4_and_ipv6_addresses_dns_gateways_and_routes(self):
        result = profile.properties({
            'ipv4': {'method': 'manual', 'addresses': ['192.0.2.10/24'], 'gateway': '192.0.2.1',
                     'dns': ['192.0.2.53'], 'route_metric': 150,
                     'routes': [{'destination': '198.51.100.0/24', 'via': '192.0.2.1', 'metric': 25}]},
            'ipv6': {'method': 'manual', 'addresses': ['fd42::10/64'], 'gateway': 'fd42::1', 'dns': ['fd42::53']},
        })
        values = dict(zip(result[::2], result[1::2]))
        self.assertEqual('192.0.2.10/24', values['ipv4.addresses'])
        self.assertEqual('198.51.100.0/24 192.0.2.1 25', values['ipv4.routes'])
        self.assertEqual('150', values['ipv4.route-metric'])
        self.assertEqual('fd42::1', values['ipv6.gateway'])

    def test_dhcp_does_not_require_static_addresses(self):
        values = profile.properties({'ipv4': {'method': 'auto'}, 'ipv6': {'method': 'dhcp'}})
        self.assertEqual('auto', dict(zip(values[::2], values[1::2]))['ipv4.method'])

    def test_direct_route_may_have_its_own_metric(self):
        values = profile.properties({'ipv4': {'method': 'auto', 'routes': [
            {'destination': '198.51.100.0/24', 'metric': 35}]}})
        self.assertEqual('198.51.100.0/24 35', dict(zip(values[::2], values[1::2]))['ipv4.routes'])

    def test_refuses_invalid_mixed_family_and_unsafe_values(self):
        configs = [
            {'ipv4': {'method': 'manual'}},
            {'ipv4': {'method': 'auto', 'gateway': '::1'}},
            {'ipv4': {'method': 'auto', 'dns': ['1.2.3.4;reboot']}},
            {'ipv4': {'method': 'auto', 'route_metric': True}},
            {'ipv4': {'method': 'auto', 'routes': [{'destination': '0.0.0.0/0'}], 'never_default': True}},
            {'ipv4': {'method': 'manual', 'addresses': ['192.0.2.10/24'], 'gateway': '192.0.2.1', 'never_default': True}},
            {'ipv4': {'method': 'disabled', 'dns': ['1.1.1.1']}},
            {'ipv6': {'method': 'manual', 'addresses': ['192.0.2.1/24']}},
            {'ipv4': {'method': 'auto', 'unknown': 'x'}},
            {'connection.id': 'something'},
        ]
        for config in configs:
            with self.subTest(config=config), self.assertRaises(profile.InvalidProfile):
                profile.properties(config)


unittest.main()
