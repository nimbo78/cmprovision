#!/usr/bin/env python3
"""Opt-in systemd integration: atomic FPMS settings updates trigger the path unit."""

import os
import importlib.util
import json
from pathlib import Path
import secrets
import subprocess
import tempfile
import time
import unittest


SOURCE = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('image_fpms_systemd', SOURCE / 'image/fpms/cmprovision-image-fpms.py')
fpms = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fpms)


@unittest.skipUnless(os.geteuid() == 0 and os.environ.get('CMP_IMAGE_SYSTEMD_TEST') == '1',
                     'requires an explicitly selected disposable systemd host')
class FpmsPathIntegrationTest(unittest.TestCase):
    def test_atomic_create_and_replace_trigger_the_service(self):
        name = 'cmprovision-test-fpms-' + secrets.token_hex(8)
        unitdir = Path('/run/systemd/system')
        service, pathunit = unitdir / (name + '.service'), unitdir / (name + '.path')
        with tempfile.TemporaryDirectory(prefix='cmp-fpms-path-') as tmp:
            root = Path(tmp)
            config, fired = root / 'settings.json', root / 'fired'
            service.write_text('[Service]\nType=oneshot\nExecStart=/usr/bin/touch ' + str(fired) + '\n')
            pathunit.write_text('[Path]\nPathChanged=' + str(config) + '\nUnit=' + name + '.service\n')
            try:
                subprocess.run(['systemctl', 'daemon-reload'], check=True)
                subprocess.run(['systemctl', 'start', name + '.path'], check=True)
                for value in ('first', 'second'):
                    temporary = root / 'new.json'
                    temporary.write_text(value)
                    os.replace(temporary, config)
                    end = time.monotonic() + 10
                    while not fired.exists() and time.monotonic() < end:
                        time.sleep(0.1)
                    self.assertTrue(fired.exists(), 'systemd did not observe an atomic settings update')
                    # The file appears just before the oneshot process exits. A second
                    # update while that service is still active may be coalesced by
                    # systemd; wait until it is ready for another independent event.
                    state = None
                    end = time.monotonic() + 10
                    while time.monotonic() < end:
                        state = subprocess.run(
                            ['systemctl', 'show', name + '.service', '--property=ActiveState', '--value'],
                            check=True, capture_output=True, text=True,
                        ).stdout.strip()
                        if state == 'inactive':
                            break
                        time.sleep(0.1)
                    self.assertEqual(state, 'inactive', 'systemd oneshot did not finish')
                    fired.unlink()
            finally:
                subprocess.run(['systemctl', 'stop', name + '.path'], check=False,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                pathunit.unlink(missing_ok=True)
                service.unlink(missing_ok=True)
                subprocess.run(['systemctl', 'daemon-reload'], check=False,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    def test_enabled_setting_starts_and_disabled_setting_stops_a_guarded_unit(self):
        name = 'cmprovision-test-fpms-' + secrets.token_hex(8)
        unit = Path('/run/systemd/system') / (name + '.service')
        with tempfile.TemporaryDirectory(prefix='cmp-fpms-service-') as tmp:
            root = Path(tmp)
            config, runtime = root / 'settings.json', root / 'runtime'
            unit.write_text('[Unit]\nConditionPathExists=' + str(runtime / 'enabled') +
                            '\n[Service]\nExecStart=/usr/bin/sleep 120\n')
            def control(action):
                return subprocess.run(['systemctl', action, name + '.service'],
                                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
            try:
                subprocess.run(['systemctl', 'daemon-reload'], check=True)
                config.write_text(json.dumps({'enabled': True, 'open_on_start': False}))
                fpms.sync(config, runtime, control)
                self.assertTrue(control('is-active'))
                config.write_text(json.dumps({'enabled': False, 'open_on_start': False}))
                fpms.sync(config, runtime, control)
                self.assertFalse(control('is-active'))
                self.assertFalse((runtime / 'enabled').exists())
            finally:
                subprocess.run(['systemctl', 'stop', name + '.service'], check=False,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                unit.unlink(missing_ok=True)
                subprocess.run(['systemctl', 'daemon-reload'], check=False,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


if __name__ == '__main__':
    unittest.main()
