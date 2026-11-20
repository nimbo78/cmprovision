"""Verify fresh-install runtime paths in the actual production package."""
import os
from pathlib import Path
import pwd
import shutil
import stat
import subprocess
import tempfile
import unittest


PACKAGE = os.environ.get('CMP_IMAGE_PACKAGE')


@unittest.skipUnless(os.geteuid() == 0 and PACKAGE and shutil.which('dpkg-deb'),
                     'root, dpkg-deb and CMP_IMAGE_PACKAGE are required')
class PackageRuntimeLayoutTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root = Path(cls.enterClassContext(tempfile.TemporaryDirectory(prefix='cmp-package-layout-')))
        subprocess.run(['dpkg-deb', '-x', PACKAGE, cls.root], check=True)

    def test_fresh_package_has_writable_laravel_runtime_directories(self):
        app = self.root / 'var/lib/cmprovision'
        owner = pwd.getpwnam('www-data')
        required = (
            'storage/app/public', 'storage/framework/cache/data',
            'storage/framework/sessions', 'storage/framework/testing',
            'storage/framework/views', 'storage/logs',
            'bootstrap/cache', 'public/uploads',
        )
        for name in required:
            with self.subTest(directory=name):
                path = app / name
                self.assertTrue(path.is_dir(), f'missing runtime directory: {name}')
                self.assertFalse(path.is_symlink())
                metadata = path.stat()
                self.assertEqual((metadata.st_uid, metadata.st_gid), (owner.pw_uid, owner.pw_gid))
                self.assertTrue(metadata.st_mode & stat.S_IWUSR, f'owner cannot write: {name}')
                self.assertTrue(metadata.st_mode & stat.S_IXUSR, f'owner cannot enter: {name}')
                self.assertFalse(stat.S_IMODE(metadata.st_mode) & 0o002)

    def test_graphical_hdmi_package_ships_the_modules_and_runtime_dependencies(self):
        runtime = self.root / 'usr/lib/cmprovision'
        entrypoint = runtime / 'hdmi-wallboard.py'
        # The release controller also builds older tags that predate graphical HDMI.
        if not entrypoint.is_file() or 'hdmi_presentation' not in entrypoint.read_text():
            self.skipTest('This package predates the native HDMI renderer')

        def dependencies(field):
            value = subprocess.check_output(['dpkg-deb', '-f', PACKAGE, field], text=True)
            return {entry.split()[0] for entry in value.split(',') if entry.strip()}

        required = dependencies('Depends')
        self.assertTrue({'libdrm2', 'python3-pil'}.issubset(required), required)
        self.assertNotIn('python3-kms++', required)
        self.assertIn('python3-kms++', dependencies('Recommends'))
        modules = ['hdmi_graphics.py', 'hdmi_output.py', 'hdmi_presentation.py']
        graphics = runtime / 'hdmi_graphics.py'
        # Earlier graphical releases had no port detail helper.
        if graphics.is_file() and 'hdmi_port_info' in graphics.read_text():
            modules.append('hdmi_port_info.py')
        for name in modules:
            with self.subTest(module=name):
                path = runtime / name
                self.assertTrue(path.is_file(), f'missing HDMI module: {name}')
                metadata = path.lstat()
                self.assertTrue(stat.S_ISREG(metadata.st_mode))
                self.assertEqual((metadata.st_uid, metadata.st_gid), (0, 0))
                self.assertEqual(stat.S_IMODE(metadata.st_mode), 0o644)


if __name__ == '__main__':
    unittest.main()
