#!/usr/bin/env python3
"""Fail image assembly if the prepared root filesystem contains unsafe defaults."""

import os
from pathlib import Path
import stat
import sys


def read_regular(path, limit=131072):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_size > limit:
            raise ValueError('An image configuration file is not a regular, bounded file.')
        return os.read(fd, limit + 1).decode('utf-8')
    finally:
        os.close(fd)


def audit(root):
    shadow = read_regular(root / 'etc/shadow')
    entries = {}
    for line in shadow.splitlines():
        fields = line.split(':')
        if len(fields) >= 2:
            if fields[0] in entries:
                raise ValueError('The image contains duplicate account records.')
            entries[fields[0]] = fields[1]
    passwd = read_regular(root / 'etc/passwd')
    accounts = {}
    for line in passwd.splitlines():
        fields = line.split(':')
        if len(fields) < 7 or not fields[2].isdigit() or fields[0] in accounts:
            raise ValueError('The image account database is invalid.')
        accounts[fields[0]] = int(fields[2])
    if accounts.get('root') != 0 or 'pi' not in accounts:
        raise ValueError('The expected build-time accounts are missing.')
    users = {name for name, uid in accounts.items() if uid == 0 or uid >= 1000}
    for user in users:
        if user not in entries or not entries[user].startswith(('!', '*')):
            raise ValueError('The image contains an unlocked build-time account: ' + user)
    env = read_regular(root / 'var/lib/cmprovision/.env')
    if [line for line in env.splitlines() if line.startswith('APP_KEY=')] != ['APP_KEY=']:
        raise ValueError('The image must not contain a shared application key.')
    ssh = root / 'etc/ssh'
    if list(ssh.glob('ssh_host_*_key')) or list(ssh.glob('ssh_host_*_key.pub')):
        raise ValueError('The image must not contain generated SSH host keys.')
    for path in (root / 'root/.ssh/authorized_keys', root / 'home/pi/.ssh/authorized_keys'):
        if os.path.lexists(path):
            raise ValueError('The image must not include a build-host SSH authorized key.')
    if os.path.lexists(root / 'var/lib/cmprovision-image/firstboot-ready'):
        raise ValueError('The image must not contain a first-boot completion marker.')
    units = root / 'etc/systemd/system'
    for name in ('cmprovision-dnsmasq', 'cmprovision-rpiboot'):
        if not (units / (name + '.service')).is_symlink() or os.readlink(units / (name + '.service')) != '/dev/null':
            raise ValueError('The provisioning service must be masked: ' + name)
    for name in ('ssh', 'nginx', 'cmprovision-queue', 'cmprovision-queue-images',
                 'cmprovision-wallboard-data', 'cmprovision-fpms-data'):
        content = read_regular(units / (name + '.service.d/image-firstboot.conf'))
        if 'Requires=cmprovision-image-firstboot.service' not in content.splitlines() or \
                'After=cmprovision-image-firstboot.service' not in content.splitlines():
            raise ValueError('The first-boot service dependency is missing: ' + name)
    enabled = units / 'multi-user.target.wants/cmprovision-image-firstboot.service'
    valid_targets = {'/etc/systemd/system/cmprovision-image-firstboot.service',
                     '../cmprovision-image-firstboot.service'}
    if not enabled.is_symlink() or os.readlink(enabled) not in valid_targets:
        raise ValueError('The image first-boot unit is not enabled.')
    firstboot = read_regular(units / 'cmprovision-image-firstboot.service')
    if 'ExecStart=/usr/lib/cmprovision/image-firstboot' not in firstboot.splitlines():
        raise ValueError('The image first-boot unit has an unexpected command.')
    after = [line.partition('=')[2].split() for line in firstboot.splitlines() if line.startswith('After=')]
    if not any('sshd-keygen.service' in names for names in after):
        raise ValueError('SSH host key generation is not ordered before image first boot.')
    helper = root / 'usr/lib/cmprovision/image-firstboot'
    info = helper.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or not (info.st_mode & 0o100):
        raise ValueError('The image first-boot helper is missing or not executable by root.')
    if os.path.lexists(root / 'usr/lib/systemd/system/fpms-lite.service'):
        dropin = read_regular(units / 'fpms-lite.service.d/cmprovision-image.conf')
        if 'ConditionPathExists=/run/cmprovision-image-fpms/enabled' not in dropin.splitlines():
            raise ValueError('The optional FPMS service is not guarded by its setting.')
        controller = read_regular(units / 'cmprovision-image-fpms.service')
        if 'ExecStart=/usr/lib/cmprovision/image-fpms' not in controller.splitlines():
            raise ValueError('The optional FPMS controller has an unexpected command.')
        helper = (root / 'usr/lib/cmprovision/image-fpms').lstat()
        if not stat.S_ISREG(helper.st_mode) or helper.st_uid != 0 or not (helper.st_mode & 0o100):
            raise ValueError('The optional FPMS controller is missing or not executable by root.')
        if os.path.lexists(units / 'multi-user.target.wants/fpms-lite.service'):
            raise ValueError('The optional FPMS service must not be enabled by default.')
        for target, suffix in (('multi-user.target.wants', 'path'), ('timers.target.wants', 'timer')):
            read_regular(units / ('cmprovision-image-fpms.' + suffix))
            link = units / target / ('cmprovision-image-fpms.' + suffix)
            if not link.is_symlink() or os.readlink(link) not in {
                '/etc/systemd/system/cmprovision-image-fpms.' + suffix,
                '../cmprovision-image-fpms.' + suffix,
            }:
                raise ValueError('The optional FPMS setting watcher is not enabled.')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('usage: audit-rootfs.py ROOTFS')
    try:
        audit(Path(sys.argv[1]))
    except (OSError, ValueError, UnicodeError) as error:
        print('Image safety check failed: ' + str(error), file=sys.stderr)
        sys.exit(1)
    print('Image root filesystem passed first-boot safety checks.')
