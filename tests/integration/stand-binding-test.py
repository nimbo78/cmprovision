#!/usr/bin/env python3
import importlib.machinery
import os
import sys
import tempfile
import unittest
from unittest.mock import Mock

binding_module = importlib.machinery.SourceFileLoader('stand_binding', sys.argv.pop()).load_module()
dnsmasq = importlib.machinery.SourceFileLoader('stand_dnsmasq', sys.argv.pop()).load_module()


class StandBindingTest(unittest.TestCase):
    def test_atomic_binding_can_be_restored_to_no_override(self):
        with tempfile.TemporaryDirectory(prefix='cmp-stand-binding-') as directory:
            binding = binding_module.Binding(dnsmasq, directory + '/interface')
            self.assertIsNone(binding.read())
            binding.write('eth0.42')
            self.assertEqual('eth0.42', binding.read())
            self.assertEqual(0o644, os.stat(binding.path).st_mode & 0o777)
            binding.write('eth0')
            self.assertEqual('eth0', binding.read())
            binding.write(None)
            self.assertIsNone(binding.read())

    def test_symlink_and_bad_interface_are_refused(self):
        with tempfile.TemporaryDirectory(prefix='cmp-stand-binding-') as directory:
            binding = binding_module.Binding(dnsmasq, directory + '/interface')
            with self.assertRaises(binding_module.BindingError): binding.write('eth0;bad')
            os.symlink('/etc/passwd', binding.path)
            with self.assertRaises(OSError): binding.write('eth0')

    def test_masked_or_inactive_dnsmasq_is_not_started(self):
        run = Mock(return_value='LoadState=masked\nActiveState=inactive')
        service = binding_module.DnsmasqService(run=run)
        self.assertFalse(service.active())
        service.reconcile(False)
        self.assertFalse(any('restart' in call.args for call in run.call_args_list))

    def test_unexpected_service_start_is_not_overridden(self):
        run = Mock(return_value='LoadState=loaded\nActiveState=active')
        service = binding_module.DnsmasqService(run=run)
        with self.assertRaises(binding_module.BindingError): service.reconcile(False)
        self.assertFalse(any('restart' in call.args for call in run.call_args_list))


unittest.main()
