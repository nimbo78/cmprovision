#!/usr/bin/env python3
import importlib.machinery
import os
import sys
import tempfile
import unittest
from unittest.mock import patch

module = importlib.machinery.SourceFileLoader('stand_dnsmasq', sys.argv.pop()).load_module()


class DnsmasqTest(unittest.TestCase):
    def test_legacy_start_is_unchanged_without_a_binding(self):
        with patch.object(module.subprocess, 'run', side_effect=AssertionError('should not read config')):
            self.assertEqual(['/usr/sbin/dnsmasq', '-C', module.CONFIG, '--pid-file=' + module.PIDFILE], module.command(None))

    def test_root_binding_is_validated_and_symlinks_are_refused(self):
        with tempfile.TemporaryDirectory(prefix='cmp-stand-binding-') as directory:
            path = directory + '/interface'
            self.assertIsNone(module.binding(path))
            with open(path, 'w', encoding='ascii') as output: output.write('eth0.42\n')
            os.chmod(path, 0o600)
            self.assertEqual('eth0.42', module.binding(path))
            with open(path, 'w', encoding='ascii') as output: output.write('eth0;bad\n')
            with self.assertRaises(ValueError): module.binding(path)
            os.unlink(path)
            os.symlink('/etc/passwd', path)
            with self.assertRaises(OSError): module.binding(path)

    def test_config_rejects_interface_overrides_and_includes(self):
        module.check_config('# interface=ignored\nport=0\ndhcp-host=aa:bb:cc:dd:ee:ff,172.20.0.50\n')
        for option in module.CONFLICTS:
            with self.subTest(option=option), self.assertRaises(ValueError):
                module.check_config('  ' + option + ' = something\n')

    def test_bound_start_reads_configuration_as_web_user_and_limits_interface(self):
        result = type('Result', (), {'returncode': 0, 'stdout': b'port=0\n'})()
        with patch.object(module.subprocess, 'run', return_value=result) as call:
            args = module.command('eth0.42')
            self.assertEqual(['--interface=eth0.42', '--bind-dynamic'], args[-2:])
            self.assertEqual(['runuser', '-u', 'www-data', '--', 'head', '-c', '1048577', module.CONFIG], call.call_args.args[0])


unittest.main()
