#!/usr/bin/env python3
"""Prove deferred recovery does not wait on its own Before= service ordering.

Run as root on a disposable systemd test host. Creates uniquely named harmless test units;
never starts or stops a real cmprovision service.
"""
import os
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile
import time
import uuid

assert os.geteuid() == 0
source = str(Path(sys.argv[1]).resolve())
prefix = 'cmp-network-order-test-' + uuid.uuid4().hex
recovery, daemon = prefix + '.service', prefix + '-daemon.service'
unit_dir = Path('/run/systemd/system')


def run(*args):
    return subprocess.check_output(args, stderr=subprocess.STDOUT, text=True, timeout=15).strip()


with tempfile.TemporaryDirectory(prefix=prefix) as directory:
    directory = Path(directory)
    helper = directory / 'recover.py'
    marker = directory / 'started'
    helper.write_text('''import importlib.machinery, subprocess, sys
module = importlib.machinery.SourceFileLoader('stand_binding', sys.argv[1]).load_module()
def run(*args):
    args = [sys.argv[2] if a == 'cmprovision-dnsmasq.service' else a for a in args]
    return subprocess.check_output(args, stderr=subprocess.STDOUT, text=True, timeout=10).strip()
module.DnsmasqService(run=run, defer_start=True).reconcile(True)
''')
    try:
        (unit_dir / recovery).write_text('[Unit]\nWants=' + daemon + '\nBefore=' + daemon +
            '\n[Service]\nType=oneshot\nRemainAfterExit=yes\nTimeoutStartSec=8\nExecStart=' +
            shlex.join(['/usr/bin/python3', str(helper), source, daemon]) + '\n')
        (unit_dir / daemon).write_text('[Service]\nType=oneshot\nRemainAfterExit=yes\nExecStart=' +
            shlex.join(['/usr/bin/touch', str(marker)]) + '\n')
        run('systemctl', 'daemon-reload')
        run('systemctl', 'start', recovery)
        deadline = time.monotonic() + 5
        while not marker.exists() and time.monotonic() < deadline:
            time.sleep(.1)
        assert marker.exists(), 'the dependent service was never started'
        assert run('systemctl', 'show', '-p', 'Result', '--value', recovery) == 'success'
        assert run('systemctl', 'show', '-p', 'Result', '--value', daemon) == 'success'
        print('PASS: boot recovery completes before the dependent service starts; no ordering deadlock')
    finally:
        subprocess.run(['systemctl', 'stop', recovery, daemon], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        for name in (recovery, daemon):
            (unit_dir / name).unlink(missing_ok=True)
        run('systemctl', 'daemon-reload')
        subprocess.run(['systemctl', 'reset-failed', recovery, daemon], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
