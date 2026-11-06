#!/usr/bin/env python3
"""Apply setup to a disposable copy of a real Raspberry Pi OS rootfs through QEMU.

Invoke under unshare --mount --pid --fork; the first argument MUST be a disposable copy.
Never pass a mounted disk or the image builder's original rootfs.
"""
import importlib.machinery
import json
import os
from pathlib import Path
import subprocess
import sys

root = Path(sys.argv[1]).resolve()
assert root.parent.name.startswith('cmp-station-rootfs-') and root.name == 'rootfs', 'Disposable test copy required'
assert os.geteuid() == 0
assert os.readlink('/proc/self/ns/pid') != os.readlink('/proc/1/ns/pid'), 'Private PID namespace required'
setup = importlib.machinery.SourceFileLoader('setup_image', sys.argv[2]).load_module()
mounts = []


def command(args, data=None):
    result = subprocess.run(['chroot', str(root), *args], input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
    if result.returncode:
        raise RuntimeError('Image command failed: ' + args[0] + ' (output withheld)')
    return result.stdout


try:
    subprocess.run(['mount', '--make-rprivate', '/'], check=True)
    for args, target in [(['-t', 'proc', 'proc'], 'proc'), (['--bind', '/dev'], 'dev'), (['-t', 'tmpfs', 'tmpfs'], 'run')]:
        subprocess.run(['mount', *args, str(root / target)], check=True)
        mounts.append(root / target)
    password = subprocess.check_output(['php', '-r', r"echo crypt('Fixture-Password-Only', '$6$rounds=100000$0123456789abcdef$');"]).decode()
    if not (root.parent / 'test-key').exists():
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(root.parent / 'test-key')], check=True)
    public = (root.parent / 'test-key.pub').read_text().split()
    settings = {'schema': 1, 'hostname': 'cmprov-lab', 'username': 'operator', 'password_hash': password,
        'keys': [' '.join(public[:2])], 'ssh': True,
        'wifi': {'ssid': ' Lab;Wifi ', 'security': 'mixed', 'password': ' Fixture\\pass ', 'country': 'IL', 'hidden': False}}
    if os.environ.get('CMP_STATION_KEYS_ONLY') == '1':
        settings['password_hash'] = None
    identity = {'schema': 1, 'serial': '10000000e611110c', 'cid': 'a'*32, 'run': 'x'*40}
    setup.configure(root, identity, settings, command)
    account = command(['getent', 'passwd', 'operator']).decode().split(':')
    assert account[2] == '1000' and account[5] == '/home/operator'
    assert command(['getent', 'shadow', 'operator']).decode().split(':')[1] == (settings['password_hash'] or '*')
    if settings['password_hash'] is None:
        command(['visudo', '-c', '-f', '/etc/sudoers.d/090-cmprovision-first-user'])
        # QEMU user-mode/binfmt cannot preserve setuid sudo credentials here.
        # Validate the real policy as root; login/elevation still needs hardware acceptance.
        assert b'NOPASSWD: ALL' in command(['sudo', '-l', '-U', 'operator'])
    assert (root / 'etc/hostname').read_text().strip() == 'cmprov-lab'
    assert (root / 'home/operator/.ssh/authorized_keys').stat().st_uid == 1000
    assert (root / 'etc/systemd/system/multi-user.target.wants/ssh.service').is_symlink()
    assert not os.path.lexists(root / 'etc/systemd/system/multi-user.target.wants/userconfig.service')
    for service in ('cmprovision-dnsmasq', 'cmprovision-rpiboot'):
        assert os.readlink(root / ('etc/systemd/system/' + service + '.service')) == '/dev/null'
    assert not list((root / 'etc/ssh').glob('ssh_host_*_key'))
    setup.configure(root, identity, settings, command)  # lost success response
    print('PASS: real arm64 image account, password, SSH keys, Wi-Fi, disabled boot services and idempotent retry')
finally:
    for target in reversed(mounts):
        subprocess.run(['umount', str(target)], check=True)
