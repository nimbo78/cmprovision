import unittest
from pathlib import Path
from unittest.mock import patch

from update_manifest import UpdateError
from update_system import LinuxSystem, parse_plan


class SystemTest(unittest.TestCase):
    def test_apt_plan_rejects_removal_and_pins_every_install(self):
        self.assertEqual([{'package': 'cmprovision4', 'version': '2.28.0'},
                          {'package': 'libexample:arm64', 'version': '1:2.0-1'}],
            parse_plan('Inst cmprovision4 [2.27.0] (2.28.0 local-deb [all])\n'
                       'Inst libexample:arm64 (1:2.0-1 Debian:13/stable [arm64])\nConf libexample (1:2.0-1)'))
        for value in ('Remv nginx [1.2]', 'Inst invalid;name (1.0 local)', 'Inst nginx (bad/version local)'):
            with self.assertRaises(UpdateError): parse_plan(value)

    def test_critical_install_uses_no_download_and_only_approved_versions(self):
        system = LinuxSystem.__new__(LinuxSystem)
        calls = []
        system.command = lambda argv, **kw: calls.append(([str(x) for x in argv], kw)) or ''
        plan = [{'package': 'cmprovision4', 'version': '2.28.0'}]
        system.plan_install = lambda *args: plan
        system.install([Path('/root/private/app.deb')], plan, {})
        self.assertEqual(['/usr/bin/dpkg', '--force-confold', '--unpack', '/root/private/app.deb'], calls[0][0])
        self.assertIn('--no-download', calls[1][0])
        self.assertIn('--no-remove', calls[1][0])
        self.assertIn('cmprovision4=2.28.0', calls[1][0])
        self.assertNotIn('/root/private/app.deb', calls[1][0])

    def test_service_restore_does_not_unmask_observer_boot_services(self):
        system = LinuxSystem.__new__(LinuxSystem)
        calls = []
        system.command = lambda argv, **kw: calls.append(argv) or ''
        system.resume({'cmprovision-dnsmasq.service': {'enabled': 'masked', 'active': False},
                       'cmprovision-queue.service': {'enabled': 'enabled', 'active': True}}, boot=True)
        self.assertFalse(any('unmask' in c and 'cmprovision-dnsmasq.service' in c for c in calls))
        self.assertFalse(any('start' in c and 'cmprovision-dnsmasq.service' in c for c in calls))
        self.assertTrue(any('start' in c and 'cmprovision-queue.service' in c for c in calls))

    def test_masked_but_running_service_is_rejected_before_changing_anything(self):
        system = LinuxSystem.__new__(LinuxSystem)
        calls = []
        def command(argv, **kwargs):
            calls.append(argv)
            if 'list-unit-files' in argv: return ''
            import subprocess
            return subprocess.CompletedProcess(argv, 0, stdout='LoadState=loaded\nUnitFileState=masked\nActiveState=active\n')
        system.command = command
        with self.assertRaisesRegex(UpdateError, 'masked service is still running'): system.capture_services()
        self.assertTrue(all('stop' not in argv and 'mask' not in argv for argv in calls))


if __name__ == '__main__': unittest.main()
