#!/usr/bin/env python3
"""Keep the optional image-provided FPMS service in step with its web setting."""

import json
import os
from pathlib import Path
import stat
import subprocess
import sys


CONFIG = Path('/etc/default/cmprovision-fpms.json')
RUNTIME = Path('/run/cmprovision-image-fpms')


def enabled(path):
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        return False
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022 or info.st_size > 4096:
            return False
        with os.fdopen(fd, 'rb', closefd=False) as source:
            data = json.load(source)
    except (ValueError, UnicodeError, RecursionError):
        return False
    finally:
        os.close(fd)
    return (isinstance(data, dict) and set(data) == {'enabled', 'open_on_start'} and
            type(data['enabled']) is bool and type(data['open_on_start']) is bool and data['enabled'])


def sync(config, runtime, systemctl):
    runtime.mkdir(mode=0o755, parents=False, exist_ok=True)
    info = runtime.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise RuntimeError('The FPMS runtime directory is not secure.')
    marker = runtime / 'enabled'
    if enabled(config):
        fd = os.open(marker, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o644)
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
                raise RuntimeError('The FPMS runtime marker is not secure.')
        finally:
            os.close(fd)
        if not systemctl('is-active') and not systemctl('start'):
            raise RuntimeError('The optional FPMS service could not be started.')
    else:
        try:
            marker.unlink()
        except FileNotFoundError:
            pass
        if systemctl('is-active') and not systemctl('stop'):
            raise RuntimeError('The optional FPMS service could not be stopped.')


def systemctl(action):
    command = ['/usr/bin/systemctl', action]
    if action == 'is-active':
        command.append('--quiet')
    command.append('fpms-lite.service')
    return subprocess.run(command, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                          stderr=subprocess.DEVNULL, timeout=15, check=False).returncode == 0


def main():
    if os.geteuid() != 0 or len(sys.argv) != 1:
        raise RuntimeError('The image FPMS controller requires root and takes no arguments.')
    sync(CONFIG, RUNTIME, systemctl)


if __name__ == '__main__':
    try:
        main()
    except (OSError, RuntimeError, subprocess.SubprocessError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
