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
    def test_fresh_package_has_writable_laravel_runtime_directories(self):
        with tempfile.TemporaryDirectory(prefix='cmp-package-layout-') as directory:
            subprocess.run(['dpkg-deb', '-x', PACKAGE, directory], check=True)
            app = Path(directory) / 'var/lib/cmprovision'
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
                    self.assertFalse(stat.S_IMODE(metadata.st_mode) & 0o002)


if __name__ == '__main__':
    unittest.main()
