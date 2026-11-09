from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class PackagingTest(unittest.TestCase):
    def test_broker_has_narrow_sudo_and_recovery_does_not_start_mid_upgrade(self):
        sudo = (ROOT/'debian/016_cmprovision-updates').read_text()
        self.assertIn('/usr/lib/cmprovision/station-updates ""', sudo)
        self.assertNotIn('SETENV', sudo)
        control = (ROOT/'debian/control').read_text()
        for dependency in ('minisign', 'dpkg-repack'): self.assertIn(dependency, control)
        rules = (ROOT/'debian/rules').read_text()
        self.assertIn('--no-start cmprovision-updates-recover.service', rules)
        unit = (ROOT/'debian/cmprovision-updates-check.service').read_text()
        self.assertIn('User=www-data', unit)
        self.assertNotIn('NoNewPrivileges=yes', unit)
        recover = (ROOT/'debian/cmprovision-updates-recover.service').read_text()
        for name in ('nginx.service', 'cmprovision-queue.service', 'cmprovision-wallboard-data.service'):
            self.assertIn(name, recover)


if __name__ == '__main__': unittest.main()
