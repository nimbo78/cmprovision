#!/usr/bin/env python3
"""Filesystem/application contract tests; run only in a disposable root Linux lab."""
import importlib.machinery
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

setup = importlib.machinery.SourceFileLoader('station_setup', sys.argv.pop(1)).load_module()


class SetupTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='cmp-station-test-')
        self.root = Path(self.directory.name)
        (self.root / 'etc').mkdir()
        (self.root / 'etc/passwd').write_text('root:x:0:0:root:/root:/bin/bash\npi:x:1000:1000:pi:/home/pi:/bin/bash\n')
        (self.root / 'etc/hosts').write_text('127.0.0.1 localhost\n127.0.1.1 raspberrypi\n')
        self.identity = {'schema': 1, 'serial': '10000000e611110c', 'cid': 'a'*32, 'run': 'x'*40}
        self.settings = {'schema': 1, 'hostname': 'station-test', 'username': 'operator',
            'password_hash': '$6$rounds=100000$0123456789abcdef$' + 'a'*86,
            'keys': [], 'ssh': True, 'wifi': {'ssid': ' Lab;Wifi ', 'password': ' Secret\\value ',
            'security': 'mixed', 'country': 'IL', 'hidden': False}}
        self.commands = []

    def tearDown(self):
        self.directory.cleanup()

    def run_command(self, args, data=None):
        self.commands.append((args, data))

    def apply(self):
        setup.configure(self.root, self.identity, self.settings, self.run_command)

    def test_secrets_permissions_and_idempotency(self):
        self.apply()
        files = [self.root / 'etc/NetworkManager/system-connections/cmprovision-management.nmconnection',
                 self.root / 'home/operator/.ssh/authorized_keys']
        self.assertTrue(all(p.stat().st_mode & 0o777 == 0o600 for p in files))
        self.assertEqual((self.root / 'home/operator/.ssh').stat().st_mode & 0o777, 0o700)
        self.assertNotIn(self.settings['password_hash'], str([c[0] for c in self.commands]))
        self.assertIn((['chpasswd', '-e'], ('operator:' + self.settings['password_hash'] + '\n').encode()), self.commands)
        self.assertNotIn('password', (self.root / setup.STATE / 'project-setup.json').read_text())
        count = len(self.commands)
        self.apply()
        self.assertEqual(count, len(self.commands))
        self.settings['hostname'] = 'other'
        with self.assertRaises(ValueError): self.apply()

    def test_invalid_or_existing_system_is_untouched(self):
        self.settings['hostname'] = 'host\nINJECTION'
        with self.assertRaises(ValueError): self.apply()
        self.assertEqual([], self.commands)
        self.assertFalse((self.root / setup.STATE).exists())
        self.settings['hostname'] = 'station'
        (self.root / setup.STATE).mkdir(parents=True)
        (self.root / setup.STATE / 'firstboot-ready').touch()
        with self.assertRaises(ValueError): self.apply()
        self.assertEqual([], self.commands)

    def test_symlink_cannot_redirect_a_configuration_write(self):
        (self.root / 'etc/hostname').symlink_to(self.root / 'outside')
        with self.assertRaises(ValueError): self.apply()
        self.assertFalse((self.root / 'outside').exists())

    def test_wifi_keyfile_is_parsed_by_real_networkmanager(self):
        self.apply()
        path = self.root / 'etc/NetworkManager/system-connections/cmprovision-management.nmconnection'
        # Offline nmcli uses libnm's real keyfile parser, without a running daemon.
        command = os.environ.get('CMP_LAB_NMCLI', 'nmcli')
        parsed = subprocess.run([command, '--offline', 'connection', 'modify', 'connection.id', 'checked'],
                                input=path.read_bytes(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout
        self.assertIn(b'psk=\\sSecret\\\\value \n', parsed)
        self.assertIn(b'pmf=2', parsed)
        self.assertNotIn(b'\\\\sSecret', parsed)
        wired = self.root / 'etc/NetworkManager/system-connections/cmprovision-stand-uplink.nmconnection'
        parsed = subprocess.run([command, '--offline', 'connection', 'modify', 'connection.id', 'checked'],
                                input=wired.read_bytes(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout
        self.assertEqual(2, parsed.count(b'never-default=true'))
        self.assertIn(b'interface-name=eth0', parsed)

    def test_no_wifi_does_not_change_ethernet_default_routes(self):
        self.settings['wifi'] = None
        self.apply()
        self.assertFalse((self.root / 'etc/NetworkManager/system-connections/cmprovision-stand-uplink.nmconnection').exists())


if __name__ == '__main__':
    if os.geteuid() != 0: raise SystemExit('Run in a disposable root Linux test environment.')
    unittest.main()
