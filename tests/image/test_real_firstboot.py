#!/usr/bin/env python3
"""Run the first-boot coordinator with real Laravel and isolated SSH key directories.

CMP_IMAGE_LARAVEL_SOURCE must name a disposable project copy with vendor/ installed.
The host's SSH directory and application environment are never used as output.
"""

import importlib.util
import os
from pathlib import Path
import pwd
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('image_firstboot', ROOT / 'image/firstboot/cmprovision-image-firstboot.py')
firstboot = importlib.util.module_from_spec(spec)
spec.loader.exec_module(firstboot)
LARAVEL = os.environ.get('CMP_IMAGE_LARAVEL_SOURCE')


@unittest.skipUnless(os.geteuid() == 0 and LARAVEL and shutil.which('ssh-keygen') and shutil.which('php'),
                     'root, ssh-keygen, PHP and CMP_IMAGE_LARAVEL_SOURCE are required')
class RealFirstBootTest(unittest.TestCase):
    def test_independent_stations_generate_and_retain_application_and_ssh_keys(self):
        source = Path(LARAVEL)
        self.assertTrue((source / 'vendor/autoload.php').is_file())
        uid = pwd.getpwnam('www-data').pw_uid
        gid = pwd.getpwnam('www-data').pw_gid
        with tempfile.TemporaryDirectory(prefix='cmp-image-real-') as tmp:
            root = Path(tmp)
            root.chmod(0o755)
            application_keys, ssh_keys = [], []
            for name in ('a', 'b'):
                station = root / name
                station.mkdir(mode=0o755)
                app = station / 'app'
                shutil.copytree(source, app, ignore=shutil.ignore_patterns('.git', '.env', 'node_modules'))
                env = app / '.env'
                lines = (app / 'debian/env.example').read_text().splitlines()
                self.assertEqual(1, sum(line.startswith('APP_KEY=') for line in lines))
                env.write_text('\n'.join('APP_KEY=' if x.startswith('APP_KEY=') else x for x in lines) + '\n')
                os.chown(env, uid, gid)
                env.chmod(0o600)
                subprocess.run(['chown', '-R', 'www-data:www-data', str(app / 'bootstrap/cache'), str(app / 'storage')], check=True)
                sshroot = station / 'ssh-root'
                (sshroot / 'etc/ssh').mkdir(parents=True)
                def run(path, operation):
                    if operation == 'ssh-host-keys':
                        subprocess.run(['ssh-keygen', '-A', '-f', str(sshroot)], check=True,
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                    else:
                        firstboot.artisan(path, operation)
                state = station / 'state'
                firstboot.initialize(app, state, run)
                app_key = next(x for x in env.read_text().splitlines() if x.startswith('APP_KEY='))
                host_key = (sshroot / 'etc/ssh/ssh_host_ed25519_key.pub').read_text()
                firstboot.initialize(app, state, run)
                self.assertEqual(app_key, next(x for x in env.read_text().splitlines() if x.startswith('APP_KEY=')))
                self.assertEqual(host_key, (sshroot / 'etc/ssh/ssh_host_ed25519_key.pub').read_text())
                application_keys.append(app_key)
                ssh_keys.append(host_key)
            self.assertNotEqual(*application_keys)
            self.assertNotEqual(*ssh_keys)


if __name__ == '__main__':
    unittest.main()
