#!/usr/bin/env python3
"""Generate a per-station application key before image services may start."""

import fcntl
import base64
import binascii
import os
from pathlib import Path
import pwd
import stat
import subprocess
import sys


APP = Path('/var/lib/cmprovision')
STATE = Path('/var/lib/cmprovision-image')


def _secure_directory(path):
    path.mkdir(mode=0o700, parents=False, exist_ok=True)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077:
        raise RuntimeError('The image first-boot state directory is not secure.')


def _regular_owned(fd):
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077:
        raise RuntimeError('The image first-boot state file is not secure.')


def _verify_application_key(app):
    fd = os.open(app / '.env', os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != pwd.getpwnam('www-data').pw_uid or
                info.st_mode & 0o077 or info.st_size > 65536):
            raise RuntimeError('The image application environment is not secure.')
        try:
            content = os.read(fd, 65537).decode('utf-8')
        except UnicodeError as error:
            raise RuntimeError('The image application environment is invalid.') from error
        values = [line[8:] for line in content.splitlines() if line.startswith('APP_KEY=')]
        try:
            valid = len(values) == 1 and values[0].startswith('base64:') and len(base64.b64decode(values[0][7:], validate=True)) == 32
        except (ValueError, binascii.Error):
            valid = False
        if not valid:
            raise RuntimeError('The image application key was not generated correctly.')
        os.fsync(fd)
    finally:
        os.close(fd)


def initialize(app, state, run):
    """Run key generation once, writing the root-only marker only on success."""
    _secure_directory(state)
    lock = os.open(state / 'firstboot.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        _regular_owned(lock)
        fcntl.flock(lock, fcntl.LOCK_EX)
        marker = state / 'firstboot-ready'
        try:
            existing = os.open(marker, os.O_RDONLY | os.O_NOFOLLOW)
        except FileNotFoundError:
            existing = None
        if existing is not None:
            try:
                _regular_owned(existing)
            finally:
                os.close(existing)
            return False
        run(app, 'key:generate')
        run(app, 'ssh-host-keys')
        run(app, 'config:clear')
        _verify_application_key(app)
        fd = os.open(marker, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            os.write(fd, b'ready\n')
            os.fsync(fd)
        finally:
            os.close(fd)
        directory = os.open(state, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
        return True
    finally:
        os.close(lock)


def artisan(app, command):
    if command == 'ssh-host-keys':
        args = ['/usr/bin/ssh-keygen', '-A']
    else:
        args = ['runuser', '-u', 'www-data', '--', 'php', str(app / 'artisan'), command]
        if command == 'key:generate':
            args.append('--force')
    result = subprocess.run(
        args,
        cwd=app, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        timeout=120, check=False,
        env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/var/www', 'LANG': 'C.UTF-8'},
    )
    if result.returncode:
        raise RuntimeError('Image first-boot application setup failed: ' + command)


def main():
    if os.geteuid() != 0 or len(sys.argv) != 1:
        raise RuntimeError('Image first-boot setup requires root and takes no arguments.')
    initialize(APP, STATE, artisan)


if __name__ == '__main__':
    try:
        main()
    except (OSError, RuntimeError, subprocess.SubprocessError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
