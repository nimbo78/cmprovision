"""Exercise the production payload audit with valid and contaminated packages."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

CHECK = Path(__file__).resolve().parents[2] / 'image/ci/check-package.php'


@unittest.skipUnless(shutil.which('php'), 'PHP CLI is required for the package payload audit')
class ReleasePackageAuditTest(unittest.TestCase):
    def audit(self, extra=None, version='v2.26.0', commit='ccccccc', dev=False):
        with tempfile.TemporaryDirectory() as directory:
            app = Path(directory)
            (app / 'config').mkdir()
            (app / 'config/version.php').write_text(
                "<?php return ['describe'=>" + json.dumps(version) + ", 'commit'=>" + json.dumps(commit) + "];")
            (app / 'vendor/composer').mkdir(parents=True)
            (app / 'vendor/composer/installed.json').write_text(json.dumps(
                {'packages': [], 'dev': dev, 'dev-package-names': ['phpunit/phpunit'] if dev else []}))
            if extra:
                path = app / extra
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('fixture')
            return subprocess.run(['php', str(CHECK), str(app), '2.26.0', 'c' * 40],
                                  capture_output=True, text=True)

    def test_clean_production_metadata_is_accepted(self):
        result = self.audit()
        self.assertEqual(0, result.returncode, result.stderr)

    def test_upstream_windows_source_is_not_a_composer_generated_launcher(self):
        result = self.audit('vendor/nesbot/carbon/bin/carbon.bat')
        self.assertEqual(0, result.returncode, result.stderr)

    def test_composer_generated_windows_launcher_is_refused(self):
        self.assertNotEqual(0, self.audit('vendor/bin/carbon.bat').returncode)

    def test_test_credentials_vcs_and_populated_database_are_refused(self):
        for path in ('.env', 'vendor/example/.git', 'database/database.sqlite'):
            with self.subTest(path=path):
                self.assertNotEqual(0, self.audit(path).returncode)

    def test_dev_dependencies_are_refused(self):
        self.assertNotEqual(0, self.audit(dev=True).returncode)

    def test_wrong_version_or_source_is_refused(self):
        for values in ({'version': 'v2.25.0'}, {'commit': 'ddddddd'}, {'commit': ''}):
            with self.subTest(values=values):
                self.assertNotEqual(0, self.audit(**values).returncode)


if __name__ == '__main__':
    unittest.main()
