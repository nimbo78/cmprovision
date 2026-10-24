#!/usr/bin/python3
"""Fixed settings writer and optional FPMS data consumer. Never touches SPI/GPIO.

Runs as root, so it opens nothing that the web user may have prepared: the shared snapshot is judged by its modification
time alone (lstat, never opened: no link followed, no pipe waited for), and the small files that are read (the settings, the
capability of the FPMS package, its heartbeat) must be regular files of root, opened without following a link.
"""
import json
import os
from pathlib import Path
import signal
import stat
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
SNAPSHOT_FRESH = 20     # seconds: the HDMI producer writes a new snapshot every two
HEARTBEAT_FRESH = 15
IDLE_LOOK = 30          # seconds between looks at the data unit while FPMS is off and nothing runs
STOP = False


def valid_config(data):
    if not isinstance(data, dict) or set(data) != set(DEFAULTS) or any(type(v) is not bool for v in data.values()):
        raise ValueError('Expected enabled and open_on_start booleans')
    return data


def read_json(path, limit=4096, owners=(0,)):
    """A small JSON object from a regular file of one of the owners; anything else (a link, a pipe, a device, a file of
    somebody else, too big, not JSON) is an empty answer."""
    fd = None
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_size > limit or info.st_uid not in owners:
            return {}
        with os.fdopen(fd, 'rb') as source:
            fd = None
            raw = source.read(limit + 1)
        if len(raw) > limit:
            return {}
        data = json.loads(raw)
        return data if isinstance(data, dict) else {}
    except (OSError, ValueError, TypeError, RecursionError):
        return {}
    finally:
        if fd is not None:
            os.close(fd)


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
    if type(value) not in (int, float):
        return False
    try:
        return 0 <= now - value <= age
    except OverflowError:   # an integer beyond the range of a float
        return False


def snapshot_age(now):
    """Seconds since the shared snapshot was written, from its modification time alone; None when it is not a regular file."""
    try:
        info = os.lstat(SNAPSHOT)
    except OSError:
        return None
    return now - info.st_mtime if stat.S_ISREG(info.st_mode) else None


def desired(settings, capability, heartbeat, now):
    if not settings['enabled']:
        return False, 'off'
    if not capability:
        return False, 'not installed'
    if type(capability.get('protocol')) is not int or capability['protocol'] != 1:
        return False, 'incompatible'
    if not fresh(heartbeat.get('at'), now, HEARTBEAT_FRESH):
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


class Watcher:
    """The data consumer is running exactly while FPMS is enabled and its display process is alive with a display."""

    def __init__(self):
        self.retry_at = 0.0     # a start that failed is tried again after half a minute, not every cycle
        self.next_look = 0.0
        self.active = False

    def step(self, now, monotonic):
        wanted, state = desired(config(), read_json(CAPABILITY), read_json(HEARTBEAT), now)
        # With FPMS off and nothing running the unit is looked at seldom: no process every three seconds for nothing.
        if wanted or self.active or monotonic >= self.next_look:
            self.active = systemctl('is-active')
            self.next_look = monotonic + IDLE_LOOK
        if wanted and not self.active and monotonic >= self.retry_at:
            self.active = systemctl('start')
            self.retry_at = monotonic + 30
        elif not wanted and self.active:
            if systemctl('stop'):
                self.active = False
            else:
                state = 'error'
        if wanted:
            if not self.active:
                state = 'error'
            else:
                age = snapshot_age(now)
                if age is None or age > SNAPSHOT_FRESH:
                    state = 'stale'
        return state


def watch():
    STATUS.parent.mkdir(mode=0o755, exist_ok=True)
    watcher = Watcher()
    try:
        while not STOP:
            now = time.time()
            try:
                state = watcher.step(now, time.monotonic())
            except Exception as error:   # whatever it was, the watcher goes on and says so
                print('cmprovision-fpms: %s: %s' % (type(error).__name__, error), file=sys.stderr, flush=True)
                state = 'error'
            try:
                atomic_json(STATUS, dict(state=state, checked_at=int(now)))
            except OSError as error:
                print('cmprovision-fpms: status not written: %s' % error, file=sys.stderr, flush=True)
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
        except (OSError, ValueError, TypeError, RecursionError):
            print('Invalid or unavailable FPMS settings.', file=sys.stderr)
            return 1
    for sig in (signal.SIGINT, signal.SIGTERM):
        signal.signal(sig, lambda *_: globals().__setitem__('STOP', True))
    watch()
    return 0


if __name__ == '__main__':
    sys.exit(main())
