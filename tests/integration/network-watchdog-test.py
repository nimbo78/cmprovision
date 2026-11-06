#!/usr/bin/env python3
import importlib.machinery
import sys
import unittest

module = importlib.machinery.SourceFileLoader('network_watchdog', sys.argv.pop()).load_module()


class WatchdogTest(unittest.TestCase):
    def test_fixed_recovery_command_is_scheduled_and_timer_cancelled(self):
        calls = []
        watchdog = module.Watchdog(run=lambda *args: (calls.append(args) or (0, '')))
        identifier = 'a' * 32
        watchdog.schedule(identifier)
        watchdog.cancel(identifier)
        self.assertEqual(('systemd-run', '--quiet', '--collect', '--unit=cmprovision-network-revert-' + identifier,
                          '--on-active=125s', '--timer-property=AccuracySec=1s', '--property=Restart=on-failure',
                          '--property=RestartSec=10s', '--property=StartLimitIntervalSec=0', '--',
                          '/usr/lib/cmprovision/network-transaction', 'recover', identifier), calls[0])
        self.assertEqual(('systemctl', 'stop', 'cmprovision-network-revert-' + identifier + '.timer'), calls[1])

    def test_failure_to_schedule_is_not_reported_as_success(self):
        watchdog = module.Watchdog(run=lambda *args: (1, 'systemd unavailable'))
        with self.assertRaises(module.WatchdogError): watchdog.schedule('a' * 32)

    def test_invalid_unit_or_delay_is_rejected(self):
        for args in [{'unit': '../other'}, {'delay': 0}, {'delay': True}, {'delay': 3601}]:
            with self.subTest(args=args), self.assertRaises(module.WatchdogError): module.Watchdog(**args)


unittest.main()
