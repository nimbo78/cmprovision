#!/usr/bin/env python3
"""Real shadow/OpenSSH/sudo checks on a disposable arm64 rootfs, never a live disk.

Run under unshare --mount --pid --fork; ROOT must be <cmp-image-access-rootfs-*>/rootfs.
The fixture's sync command is replaced to prevent a global WSL/Windows flush.
"""
import importlib.machinery
import json
import os
import platform
from pathlib import Path
import subprocess
import sys

root = Path(sys.argv[1]).resolve()
assert root.parent.name.startswith('cmp-image-access-rootfs-') and root.name == 'rootfs'
assert os.geteuid() == 0 and root.is_dir() and not root.is_symlink()
assert os.readlink('/proc/self/ns/pid') != os.readlink('/proc/1/ns/pid'), 'Private PID namespace required'
mode = sys.argv[3] if len(sys.argv) > 3 else 'pristine'
assert mode in {'pristine', 'existing', 'keys', 'blocked', 'conflict'}
helper = importlib.machinery.SourceFileLoader('image_access', sys.argv[2]).load_module()
mounts = []
commands = []


def run(args, data=None):
    # WSL distributions share binfmt registrations; another distro's startup can remove it.
    if platform.machine() == 'x86_64' and not Path('/proc/sys/fs/binfmt_misc/qemu-aarch64').exists():
        rules = Path('/usr/lib/binfmt.d/qemu-aarch64.conf').read_text().splitlines()
        rule = next(line for line in rules if line.startswith(':qemu-aarch64:'))
        Path('/proc/sys/fs/binfmt_misc/register').write_text(rule)
        print('Restored missing qemu-aarch64 test registration', flush=True)
    commands.append(args)
    result = subprocess.run(['chroot', str(root), *args], input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=90)
    if result.returncode:
        raise RuntimeError('Image command failed: ' + args[0] + ', code ' + str(result.returncode) + ': ' + result.stderr.decode(errors='replace')[-1200:])
    return result.stdout


def ssh_options(user):
    raw = run(['/usr/sbin/sshd', '-T', '-h', '/run/cmprovision-station-setup/host', '-C',
               'user=' + user + ',host=localhost,addr=127.0.0.1']).decode()
    options = dict(line.split(' ', 1) for line in raw.splitlines() if ' ' in line)
    return {key: options[key] for key in ['passwordauthentication', 'pubkeyauthentication', 'kbdinteractiveauthentication', 'authorizedkeysfile']}


try:
    subprocess.run(['mount', '--make-rprivate', '/'], check=True)
    for arguments, member in [(['-t', 'proc', 'proc'], 'proc'), (['--bind', '/dev'], 'dev'),
                              (['-t', 'sysfs', '-o', 'ro', 'sysfs'], 'sys'), (['-t', 'tmpfs', 'tmpfs'], 'run')]:
        subprocess.run(['mount', *arguments, str(root / member)], check=True)
        mounts.append(root / member)
    sync = root / 'usr/bin/sync'
    sync.unlink(missing_ok=True)
    sync.write_text('#!/bin/sh\n# Disposable test: never flush WSL globally.\nexit 0\n')
    sync.chmod(0o755)
    runtime = root / 'run/cmprovision-station-setup'
    runtime.mkdir(mode=0o700)
    (root / 'run/sshd').mkdir(mode=0o755)
    subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(runtime / 'host')], check=True)
    password = subprocess.check_output(['php', '-r', "echo crypt('Fixture-Rootfs-Password', '$6$rounds=100000$0123456789abcdef$');"]).decode()
    identity = {'schema': 1, 'serial': '1000000012345678', 'cid': 'a' * 32, 'run': 'r' * 40}
    settings = {'schema': 2, 'profile': 'raspios', 'hostname': '', 'username': 'operator', 'source_username': '',
                'password_hash': password, 'keys': [], 'ssh': True, 'wifi': None, 'sudo_passwordless': False}
    if mode in {'existing', 'blocked', 'conflict'}:
        run(['/usr/lib/userconf-pi/userconf', 'wlanpi', ''])
        run(['useradd', '--create-home', '--shell', '/bin/bash', 'observer'])
        settings['username'] = 'wlanpi'
    selected = 'wlanpi' if mode in {'existing', 'blocked', 'conflict'} else 'pi'
    before = run(['getent', 'passwd', selected]).decode().strip().split(':')
    groups_before = set(run(['id', '-G', selected]).decode().split())
    marker = root / before[5].lstrip('/') / 'keep-this-file'
    marker.write_text('preserved')
    os.chown(marker, int(before[2]), int(before[3]))
    if mode == 'blocked':
        settings['username'] = 'operator'
        (root / 'etc/systemd/system/vendor-bound.service').write_text('[Service]\nUser=wlanpi\nExecStart=/usr/bin/true\n')
    if mode == 'conflict':
        with (root / 'etc/ssh/sshd_config').open('a') as file:
            file.write('\nMatch User wlanpi\n    PasswordAuthentication no\n')
    if mode == 'keys':
        key = (runtime / 'host.pub').read_text().split()
        settings.update(password_hash=None, keys=[' '.join(key[:2])], sudo_passwordless=True)
    other_before = ssh_options('observer') if mode in {'existing', 'conflict'} else None
    passwd_before = (root / 'etc/passwd').read_text()
    if mode in {'blocked', 'conflict'}:
        try:
            helper.configure(root, identity, settings, run)
            raise AssertionError('Unsafe rename or conflicting authentication was accepted')
        except helper.Failure as error:
            assert error.code == ('rename_references' if mode == 'blocked' else 'ssh_policy'), error.code
        assert (root / 'etc/passwd').read_text() == passwd_before
        assert not (root / helper.STATE / 'receipt.json').exists()
    else:
        helper.configure(root, identity, settings, run)
        after = run(['getent', 'passwd', settings['username']]).decode().strip().split(':')
        assert after[2:4] == before[2:4], 'UID/GID changed'
        assert after[5] == before[5] and after[6] == '/bin/bash', 'Home or shell incorrect'
        assert marker.read_text() == 'preserved' and marker.stat().st_uid == int(before[2])
        assert groups_before.issubset(set(run(['id', '-G', settings['username']]).decode().split()))
        shadow = run(['getent', 'shadow', settings['username']]).decode().split(':')[1]
        assert shadow == (settings['password_hash'] or '*')
        policy = ssh_options(settings['username'])
        assert policy['passwordauthentication'] == ('no' if mode == 'keys' else 'yes')
        assert policy['pubkeyauthentication'] == 'yes' and policy['kbdinteractiveauthentication'] == 'no'
        run(['visudo', '-c'])
        listing = run(['sudo', '-l', '-U', settings['username']]).decode()
        assert 'NOPASSWD: ALL' in listing if mode == 'keys' else 'PASSWD: ALL' in listing
        assert (root / 'etc/systemd/system/multi-user.target.wants/ssh.service').is_symlink()
        assert not os.path.lexists(root / 'etc/systemd/system/multi-user.target.wants/userconfig.service')
        if mode == 'keys':
            authorized = root / after[5].lstrip('/') / '.ssh/authorized_keys'
            assert authorized.read_text() == settings['keys'][0] + '\n'
            assert authorized.stat().st_uid == int(before[2]) and authorized.stat().st_mode & 0o777 == 0o600
        if other_before is not None:
            assert ssh_options('observer') == other_before, 'Another account authentication policy changed'
        commands.clear()
        helper.configure(root, identity, settings, run)
        assert not commands, 'Retry changed an already-applied image'
    print('PASS real arm64 rootfs:', mode)
finally:
    for target in reversed(mounts):
        subprocess.run(['umount', str(target)], check=True)
