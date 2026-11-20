"""Run the image recipe against an isolated path tree and the real updater store guard."""
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[2]
RECIPE = SOURCE/'image/pi-gen/stage-cmprovision/00-install/01-run-chroot.sh'


@unittest.skipUnless(os.name == 'posix' and shutil.which('bash'), 'POSIX shell required')
class ChrootRecoveryRuntimeTest(unittest.TestCase):
    def run_recipe(self, mode, fpms=False):
        with tempfile.TemporaryDirectory(prefix='cmp-chroot-runtime-', dir='/tmp') as tmp:
            root = Path(tmp)
            binary, runtime = root/'bin', root/'run'
            binary.mkdir()
            runtime.mkdir(mode=mode)
            runtime.chmod(mode)  # pi-gen mounts tmpfs without mode=, producing 1777.
            if fpms:
                (root/'opt').mkdir()
                (root/'opt/cmprovision-image-fpms.deb').touch()
            apt = binary/'apt-get'
            apt.write_text('#!/bin/sh\nfor arg do\n  if [ "$arg" = install ]; then\n'
                           '    printf "%s\\n" "$@" >> "$CASE_ROOT/installed"\n    break\n  fi\ndone\n')
            apt.chmod(0o755)
            for name in ('systemctl', 'passwd', 'rm'):
                path = binary/name
                path.write_text('#!/bin/sh\nexit 0\n')
                path.chmod(0o755)
            runuser = binary/'runuser'
            runuser.write_text('#!/bin/sh\ncat >/dev/null\n')
            runuser.chmod(0o755)
            initializer = binary/'python3'
            initializer.write_text(f'''#!{sys.executable}
import os,sys
from pathlib import Path
sys.path.insert(0,{str(SOURCE/'tools')!r})
from update_store import UpdateStore
assert sys.argv[1:]==['-B','-I','/usr/lib/cmprovision/station-updates.py','--initialize']
root=Path(os.environ['CASE_ROOT'])
UpdateStore(root=root/'var/lib/cmprovision-updates',runtime=root/'run/cmprovision-updates',owner=os.geteuid())
(root/'initialized').write_text('yes')
''')
            initializer.chmod(0o755)
            # Only paths in the disposable tree may be changed. Package/service and
            # unrelated sanitization commands are harmless doubles; chmod and the
            # updater's complete ownership/permission validation remain real.
            recipe = re.sub(r'/run\b|/var/lib/cmprovision(?:-image)?\b|/etc/ssh\b|/opt\b',
                            lambda m: str(root)+m.group(), RECIPE.read_text())
            environment = dict(os.environ, CASE_ROOT=str(root), PATH=str(binary)+':/usr/bin:/bin')
            result = subprocess.run(['bash', '-e'], input=recipe, text=True, capture_output=True, env=environment)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertTrue((root/'initialized').is_file(), 'Required recovery initialization did not run')
            self.assertEqual(0o755, runtime.stat().st_mode & 0o7777)
            return (root/'installed').read_text().splitlines()

    def test_pigen_sticky_runtime_is_normalized_before_protected_initialization(self):
        self.run_recipe(0o1777)

    def test_regular_runtime_remains_private_from_untrusted_writers(self):
        self.run_recipe(0o755)

    def test_both_image_profiles_install_the_hdmi_kms_bindings(self):
        for fpms in (False, True):
            with self.subTest(fpms=fpms):
                self.assertIn('python3-kms++', self.run_recipe(0o755, fpms))


if __name__ == '__main__':
    unittest.main()
