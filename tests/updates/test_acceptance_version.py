import shutil
import subprocess
import unittest

from acceptance_version import candidate_version


class AcceptanceVersionTest(unittest.TestCase):
    def test_fixture_is_newer_than_current_and_future_package_versions(self):
        for installed in ('2.27.1', '2.28.0', '2.28.0~dev1', '2.99.99', '3.0.0', '3.0.0+local1'):
            with self.subTest(installed=installed):
                candidate = candidate_version(installed)
                self.assertRegex(candidate, r'^\d+\.\d+\.\d+$')
                self.assertNotEqual(installed, candidate)
                if shutil.which('dpkg'):
                    self.assertEqual(0, subprocess.run(['dpkg', '--compare-versions', candidate, 'gt', installed]).returncode)

    def test_invalid_installed_versions_cannot_make_ambiguous_fixtures(self):
        for installed in ('', '2.28', '2.28.0\n', 'invalid', '2.28.0;false'):
            with self.subTest(installed=installed), self.assertRaises(ValueError):
                candidate_version(installed)
