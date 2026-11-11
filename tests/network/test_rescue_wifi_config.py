import sys
from pathlib import Path
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]/'tools'))
import rescue_wifi_policy as policy


class ConfigTest(unittest.TestCase):
    def validate(self, data):
        self.assertTrue(hasattr(policy, 'validate_config'), 'Validated AP configuration is missing.')
        return policy.validate_config(data)

    def config(self, **overrides):
        return dict(policy.DEFAULTS, enabled=True, ssid='cmprovision-test-rescue', password='test-secret-123456789',
                    ap_mac='02:00:00:00:00:01', client_mac='02:00:00:00:00:02', subnet='192.168.240.0/24', **overrides)

    def test_default_is_disabled_with_ten_minute_search(self):
        result = self.validate({})
        self.assertFalse(result['enabled'])
        self.assertEqual(600, result['delay'])

    def test_valid_config_and_unicode_ssid(self):
        data = self.config()
        data['ssid'] = 'Стенд cmprovision'
        self.assertEqual(data, self.validate(data))

    def test_invalid_values_never_reach_linux_effects(self):
        for key, value in [('enabled', 'yes'), ('delay', True), ('delay', 59), ('delay', 1801),
                           ('ssid', 'a'*33), ('ssid', 'bad\n[connection]'), ('password', 'short'),
                           ('password', 'x'*64), ('password', 'bad\nsecretvalue'), ('ap_mac', '../../eth0'),
                           ('client_mac', '02:00:00:00:00:01'), ('channel', 100), ('management_profiles', ['bad'])]:
            with self.subTest(key=key, value=value):
                data = self.config()
                data[key] = value
                with self.assertRaises(ValueError):
                    self.validate(data)

    def test_forbidden_or_noncanonical_subnets(self):
        for subnet in ['172.20.0.0/24', '8.8.8.0/24', '192.168.240.1/24', '10.0.0.0/8', '::/0', '127.0.0.0/24']:
            data = self.config()
            data['subnet'] = subnet
            with self.subTest(subnet=subnet), self.assertRaises(ValueError):
                self.validate(data)

    def test_unknown_keys_are_not_forwarded_to_root_tools(self):
        with self.assertRaises(ValueError):
            self.validate(dict(self.config(), command='anything'))

    def test_subnet_allocation_avoids_routes_and_ignores_default(self):
        self.assertTrue(hasattr(policy, 'choose_subnet'), 'AP subnet collision checking is missing.')
        self.assertEqual('192.168.241.0/24', policy.choose_subnet(['0.0.0.0/0', '192.168.240.0/24', '10.0.0.0/8']))
        with self.assertRaises(ValueError):
            policy.choose_subnet(['192.168.240.0/24'], '192.168.240.0/24')


if __name__ == '__main__':
    unittest.main()
