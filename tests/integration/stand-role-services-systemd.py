#!/usr/bin/env python3
"""Exercise role service enable/mask restoration with uniquely named harmless systemd units.

Root on a disposable systemd test host only. No cmprovision service is changed.
"""
import importlib.machinery
from pathlib import Path
import os
import subprocess
import sys
import uuid

assert os.geteuid() == 0
module = importlib.machinery.SourceFileLoader('tested_stand_role', sys.argv[1]).load_module()
prefix = 'cmp-role-test-' + uuid.uuid4().hex
units = {key: prefix + '-' + key + '.service' for key in ('dhcp', 'usb')}
unit_dir = Path('/run/systemd/system')


def run(*args):
    return subprocess.check_output(args, stderr=subprocess.STDOUT, text=True, timeout=15).strip()


services = module.Services(run)
services.UNITS = units
try:
    for unit in units.values():
        (unit_dir / unit).write_text('[Service]\nType=simple\nExecStart=/usr/bin/sleep infinity\n[Install]\nWantedBy=multi-user.target\n')
    run('systemctl', 'daemon-reload')
    run('systemctl', 'enable', units['dhcp'])
    run('systemctl', 'mask', *units.values())
    original = services.snapshot()
    assert original['dhcp'] == {'active': False, 'enabled': 'masked', 'boot_enabled': True}
    assert original['usb'] == {'active': False, 'enabled': 'masked', 'boot_enabled': False}
    temporary, final = services.plan(original, 'primary', True)
    services.stop()
    services.apply(temporary)
    assert services.snapshot() == temporary
    services.persistence(final)
    assert services.snapshot() == final
    services.stop()
    services.apply(original)
    assert services.snapshot() == original, 'cancel did not preserve the masked units boot links'
    services.stop()
    services.apply(final)
    secondary, secondary_final = services.plan(final, 'secondary', False)
    services.stop()
    services.apply(secondary)
    services.persistence(secondary_final)
    assert services.snapshot() == secondary_final
    services.apply(final)
    assert services.snapshot() == final
    print('PASS: real systemd role states and hidden WantedBy links restore exactly')
finally:
    subprocess.run(['systemctl', 'unmask', *units.values()], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
    subprocess.run(['systemctl', 'disable', '--now', *units.values()], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
    for unit in units.values():
        for directory in (unit_dir, Path('/etc/systemd/system'), Path('/etc/systemd/system/multi-user.target.wants')):
            (directory / unit).unlink(missing_ok=True)
    run('systemctl', 'daemon-reload')
    subprocess.run(['systemctl', 'reset-failed', *units.values()], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
