"""Bounded read-only measurements of fixed station directories. Never opens SQLite."""
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import time

APP = Path('/var/lib/cmprovision')
PATHS = {
    'application': APP,
    'uploads': APP/'public/uploads',
    'package_backups': Path('/var/backups'),
    'update_recovery': Path('/var/lib/cmprovision-updates/private/recovery'),
    'update_downloads': Path('/var/lib/cmprovision-updates/private/payloads'),
    'full_backups': Path('/var/lib/cmprovision-backups/private'),
}


def path_state(path):
    # Reject every symlink component, including a parent of a missing directory.
    for part in [*reversed(path.parents), path]:
        try:
            mode = part.lstat().st_mode
        except FileNotFoundError:
            return 'missing'
        except OSError:
            return 'unknown'
        if not stat.S_ISDIR(mode):
            return 'unknown'
    return 'measured'


def measure(path, runner=subprocess.run, exclude=None, timeout=2):
    path = Path(path)
    state = path_state(path)
    if state != 'measured':
        return {'bytes': 0 if state == 'missing' else None, 'state': state}
    argv = ['/usr/bin/du', '-sx', '--block-size=1']
    if exclude is not None:
        argv.append('--exclude='+str(exclude))
    try:
        result = runner([*argv, '--', str(path)], capture_output=True, text=True, timeout=timeout)
        value = result.stdout.split()[0] if result.returncode == 0 else ''
        if value.isdigit():
            return {'bytes': int(value), 'state': 'measured'}
    except (OSError, subprocess.TimeoutExpired):
        pass
    return {'bytes': None, 'state': 'unknown'}


def filesystem(path):
    path = Path(path)
    if path_state(path) == 'unknown':
        return None
    while not path.exists():
        path = path.parent
    try:
        usage = shutil.disk_usage(path)
        return {'total': usage.total, 'used': usage.used, 'free': usage.free}
    except OSError:
        return None


def collect(paths=None, app=APP, runner=subprocess.run):
    paths = PATHS if paths is None else paths
    started = time.monotonic()
    categories = {}
    for key, path in paths.items():
        remaining = 12-(time.monotonic()-started)
        categories[key] = (measure(path, runner, exclude=paths.get('uploads') if key == 'application' else None,
                                   timeout=min(2, remaining)) if remaining > 0 else {'bytes': None, 'state': 'unknown'})
    remaining = 12-(time.monotonic()-started)
    app_size = measure(app, runner, timeout=min(2, remaining))['bytes'] if remaining > 0 else None
    space = filesystem(paths.get('update_recovery', app))
    required = app_size*2+128*1024**2 if app_size is not None else None
    return {'schema': 1, 'measured_at': int(time.time()), 'filesystem': filesystem(app), 'categories': categories,
            'snapshot': {'required': required, 'free': space['free'] if space else None,
                         'sufficient': space['free'] >= required if space and required is not None else None}}


if __name__ == '__main__':
    if len(sys.argv) != 1 or os.geteuid() != 0:
        raise SystemExit(2)
    print(json.dumps(collect(), separators=(',', ':')))
