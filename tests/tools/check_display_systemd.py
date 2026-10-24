#!/usr/bin/python3
"""Root integration test in a disposable systemd VM. No real app/services started.

Copies the real dependency sections under unique test unit names; replaces
payloads with sleep/true. This verifies StopWhenUnneeded, not a Python model.
"""
import configparser
import os
from pathlib import Path
import subprocess
import time


def main():
    if os.geteuid() != 0 or not Path('/run/systemd/system').is_dir():
        raise SystemExit('Run as root in a disposable systemd VM')
    prefix = f'cmprovision-display-test-{os.getpid()}-'
    names = ['wallboard-data', 'wallboard-display', 'fpms-data']
    units = [prefix + name + '.service' for name in names]
    files = [Path('/run/systemd/system') / unit for unit in units]
    root = Path(__file__).resolve().parents[2] / 'debian'

    def ctl(*args, check=True):
        return subprocess.run(['systemctl', *args], check=check, capture_output=True, text=True, timeout=15)

    def expect(unit, active):
        for _ in range(30):
            state = ctl('is-active', unit, check=False).returncode == 0
            if state == active:
                return
            time.sleep(.1)
        raise AssertionError(f'{unit}: expected active={active}')

    try:
        for name, path in zip(names, files):
            config = configparser.ConfigParser(interpolation=None, strict=False)
            config.optionxform = str
            config.read(root / ('cmprovision-' + name + '.service'))
            unit = '\n'.join(f'{key}={value.replace("cmprovision-", prefix)}' for key, value in config['Unit'].items())
            payload = 'Type=simple\nExecStart=/usr/bin/sleep 360' if name == 'wallboard-data' else 'Type=oneshot\nExecStart=/usr/bin/true\nRemainAfterExit=yes'
            with path.open('x') as out:
                out.write('[Unit]\n' + unit + '\n[Service]\n' + payload + '\n')
        ctl('daemon-reload')
        data, hdmi, fpms = units
        for first, second in [(hdmi, fpms), (fpms, hdmi)]:
            ctl('start', first)
            expect(data, True)
            ctl('start', second)
            ctl('stop', first)
            expect(data, True)
            ctl('restart', second)
            expect(data, True)
            ctl('stop', second)
            expect(data, False)
        print('PASS: HDMI/FPMS independently retain data; last consumer stops it, in both orders')
    finally:
        ctl('stop', *units, check=False)
        for path in files:
            path.unlink(missing_ok=True)
        ctl('daemon-reload')
        ctl('reset-failed', *units, check=False)


if __name__ == '__main__':
    main()
