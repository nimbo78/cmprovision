#!/usr/bin/python3
"""Fixed settings writer and optional FPMS data consumer. Never touches SPI/GPIO."""
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time

CONFIG = Path('/etc/default/cmprovision-fpms.json')
STATUS = Path('/run/cmprovision-fpms/status.json')
CAPABILITY = Path('/usr/share/fpms/cmprovision.json')
HEARTBEAT = Path('/run/fpms/cmprovision.json')
SNAPSHOT = Path('/run/cmprovision-wallboard-data/snapshot.json')
UNIT = 'cmprovision-fpms-data.service'
DEFAULTS = dict(enabled=False, open_on_start=False)
STOP = False


def valid_config(data):
    if not isinstance(data, dict) or set(data) != set(DEFAULTS) or any(type(v) is not bool for v in data.values()):
        raise ValueError('Expected enabled and open_on_start booleans')
    return data


def read_json(path, limit=4096):
    try:
        with path.open('rb') as source:
            raw = source.read(limit + 1)
        if len(raw) > limit:
            return {}
        data = json.loads(raw)
        return data if isinstance(data, dict) else {}
    except (OSError, ValueError, TypeError):
        return {}


def config():
    try:
        return valid_config(read_json(CONFIG))
    except ValueError:
        return dict(DEFAULTS)


def atomic_json(path, data):
    # Only fixed root-owned directories; no app-provided filenames.
    fd, name = tempfile.mkstemp(prefix='.fpms-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as out:
            json.dump(data, out)
            out.write('\n')
            out.flush()
            os.fsync(out.fileno())
            os.fchmod(out.fileno(), 0o644)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def fresh(value, now, age):
    return type(value) in (int, float) and 0 <= now - value <= age


def desired(settings, capability, heartbeat, now):
    if not settings['enabled']:
        return False, 'off'
    if not capability:
        return False, 'not installed'
    if type(capability.get('protocol')) is not int or capability['protocol'] != 1:
        return False, 'incompatible'
    if not fresh(heartbeat.get('at'), now, 15):
        return False, 'waiting for FPMS'
    if heartbeat.get('protocol') != 1:
        return False, 'incompatible'
    if heartbeat.get('hardware') is not True:
        return False, 'no hardware'
    return True, 'running'


def systemctl(action):
    try:
        return subprocess.run(['/usr/bin/systemctl', action, UNIT], timeout=10,
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
    except (OSError, subprocess.TimeoutExpired):
        return False


def watch():
    STATUS.parent.mkdir(mode=0o755, exist_ok=True)
    retry_at = 0
    try:
        while not STOP:
            now = time.time()
            wanted, state = desired(config(), read_json(CAPABILITY), read_json(HEARTBEAT), now)
            active = systemctl('is-active')
            if wanted and not active and time.monotonic() >= retry_at:
                active = systemctl('start')
                retry_at = time.monotonic() + 30
            elif not wanted and active:
                if not systemctl('stop'):
                    state = 'error'
            if wanted:
                if not active:
                    state = 'error'
                else:
                    data = read_json(SNAPSHOT, 2097152)
                    if data.get('version') != 1 or not fresh(data.get('generated_at'), now, 20):
                        state = 'stale'
            atomic_json(STATUS, dict(state=state, checked_at=int(now)))
            time.sleep(3)
    finally:
        systemctl('stop')


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ('control', 'watch') or os.geteuid() != 0:
        return 1
    if sys.argv[1] == 'control':
        try:
            raw = sys.stdin.buffer.read(4097)
            if len(raw) > 4096:
                raise ValueError('Too much input')
            atomic_json(CONFIG, valid_config(json.loads(raw)))
            return 0
        except (OSError, ValueError, TypeError):
            print('Invalid or unavailable FPMS settings.', file=sys.stderr)
            return 1
    for sig in (signal.SIGINT, signal.SIGTERM):
        signal.signal(sig, lambda *_: globals().__setitem__('STOP', True))
    watch()
    return 0


if __name__ == '__main__':
    sys.exit(main())
