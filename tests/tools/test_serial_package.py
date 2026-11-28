import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]


@unittest.skipUnless(shutil.which("dpkg") and Path("/bin/sh").is_file(), "Debian package tools required")
class SerialPackageTest(unittest.TestCase):
    def test_only_a_downgrade_past_the_broker_stops_its_socket_and_service(self):
        # Run the installed package's prerm without invoking helpers from the host station.
        script = (ROOT / "debian/prerm").read_text().replace(
            "/usr/lib/cmprovision/", '"$TEST_ROOT"/usr/lib/cmprovision/'
        )
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            log = root / "calls"
            (root / "dpkg").symlink_to(shutil.which("dpkg"))
            for name in ("deb-systemd-invoke", "deb-systemd-helper"):
                helper = root / name
                helper.write_text('#!/bin/sh\nprintf "%s %s\\n" "${0##*/}" "$*" >> "$CALLS"\n')
                helper.chmod(0o755)

            retired = [
                "deb-systemd-invoke stop cmprovision-serial-web.socket",
                "deb-systemd-invoke stop cmprovision-serial-web.service",
                "deb-systemd-helper purge cmprovision-serial-web.socket",
            ]
            env = dict(os.environ, PATH=temp, TEST_ROOT=temp, CALLS=str(log))
            versions = (
                ("2.30.13", retired),
                ("2.30.14~dev3", retired),
                ("2.30.14~rc1", retired),
                ("2.30.14", []),
                ("2.30.15", []),
            )
            for version, expected in versions:
                with self.subTest(version=version):
                    log.write_text("")
                    subprocess.run(
                        ["/bin/sh", "-s", "upgrade", version],
                        input=script, text=True, env=env, check=True, timeout=5,
                    )
                    calls = [call for call in log.read_text().splitlines() if "serial-web" in call]
                    self.assertEqual(expected, calls)
