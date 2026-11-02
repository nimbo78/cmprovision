#!/usr/bin/env python3
"""Test a uniquely named transient timer on the disposable WSL test system, not a provisioner."""
import importlib.machinery
import os
import secrets
import sys
import tempfile
import time

module = importlib.machinery.SourceFileLoader('network_watchdog', sys.argv[1]).load_module()
with tempfile.TemporaryDirectory(prefix='cmp-watchdog-') as directory:
    marker = os.path.join(directory, 'fired')
    unit = 'cmprov-test-' + secrets.token_hex(6)
    identifier = secrets.token_hex(16)
    watchdog = module.Watchdog(unit=unit, command=['/usr/bin/sh', '-c', 'touch "$1"', 'watchdog-test', marker], delay=2)
    try:
        watchdog.schedule(identifier)
        deadline = time.monotonic() + 15
        while not os.path.isfile(marker) and time.monotonic() < deadline:
            time.sleep(.2)
        if not os.path.isfile(marker):
            raise RuntimeError('The independent systemd timer did not run.')
        print('PASS: independent transient systemd recovery timer fired')
    finally:
        watchdog.cancel(identifier)
with tempfile.TemporaryDirectory(prefix='cmp-watchdog-cancel-') as directory:
    marker = os.path.join(directory, 'unexpected')
    unit = 'cmprov-test-' + secrets.token_hex(6)
    identifier = secrets.token_hex(16)
    watchdog = module.Watchdog(unit=unit, command=['/usr/bin/sh', '-c', 'touch "$1"', 'watchdog-test', marker], delay=3)
    watchdog.schedule(identifier)
    watchdog.cancel(identifier)
    time.sleep(5)
    if os.path.exists(marker):
        raise RuntimeError('The cancelled watchdog still fired.')
    print('PASS: cancelled transient systemd timer did not fire')
with tempfile.TemporaryDirectory(prefix='cmp-watchdog-retry-') as directory:
    first = os.path.join(directory, 'first-attempt')
    recovered = os.path.join(directory, 'recovered')
    unit = 'cmprov-test-' + secrets.token_hex(6)
    identifier = secrets.token_hex(16)
    command = ['/usr/bin/sh', '-c', 'if [ ! -e "$1" ]; then touch "$1"; exit 1; fi; touch "$2"',
               'watchdog-test', first, recovered]
    watchdog = module.Watchdog(unit=unit, command=command, delay=1)
    try:
        watchdog.schedule(identifier)
        deadline = time.monotonic() + 25
        while not os.path.isfile(recovered) and time.monotonic() < deadline:
            time.sleep(.2)
        if not os.path.isfile(recovered):
            raise RuntimeError('A failed watchdog was not retried by systemd.')
        print('PASS: systemd retried a failed recovery')
    finally:
        watchdog.cancel(identifier)
