import unittest
from support import ROOT


class PackagingTest(unittest.TestCase):
    def test_installed_broker_crypto_and_restore_are_registered_without_wide_sudo(self):
        rules = (ROOT/'debian/rules').read_text()
        for name in ('station-backups', 'station-backups.py', 'backup-crypto.php', 'TransferCipher.php', 'cmprovision-full-restore'):
            self.assertIn(name, rules)
        self.assertIn('tools/backup_*.py', rules)
        self.assertIn('rclone (>= 1.60.1)', (ROOT/'debian/control').read_text())
        sudo = (ROOT/'debian/018_cmprovision-backups').read_text()
        self.assertIn('/usr/lib/cmprovision/station-backups ""', sudo)
        self.assertNotIn('*', sudo)
        self.assertNotIn('full-restore', sudo)

    def test_backup_recovery_reestablishes_gate_before_boot_and_does_not_start_in_dpkg(self):
        service = (ROOT/'debian/cmprovision-backups-recover.service').read_text()
        self.assertIn('--recover-boot', service)
        self.assertIn('cmprovision-dnsmasq.service', service)
        self.assertIn('--no-start cmprovision-backups-recover.service', (ROOT/'debian/rules').read_text())
        self.assertIn('station-backups.py --initialize', (ROOT/'debian/postinst').read_text())


if __name__ == '__main__': unittest.main()
