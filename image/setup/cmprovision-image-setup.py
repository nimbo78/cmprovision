#!/usr/bin/env python3
"""Restricted offline setup endpoint, reachable only during a verified image write."""
import base64
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import uuid


RUNTIME = Path('/run/cmprovision-station-setup')
STATE = 'var/lib/cmprovision-image'


def regular(path, limit=32768):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_size > limit:
            raise ValueError('Invalid setup file')
        return os.read(fd, limit + 1).decode('utf-8')
    finally:
        os.close(fd)


def validate(settings):
    if not isinstance(settings, dict) or set(settings) != {'schema', 'hostname', 'username', 'password_hash', 'keys', 'ssh', 'wifi'}:
        raise ValueError('Invalid settings')
    if settings['schema'] != 1 or type(settings['ssh']) is not bool:
        raise ValueError('Invalid schema')
    for field, pattern in (('hostname', r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?'), ('username', r'[a-z][a-z0-9-]{0,31}')):
        if not isinstance(settings[field], str) or not re.fullmatch(pattern, settings[field]):
            raise ValueError('Invalid name')
    if settings['username'] in ('root', 'nobody'):
        raise ValueError('Reserved account')
    password = settings['password_hash']
    if password is not None and (not isinstance(password, str) or not re.fullmatch(r'\$6\$rounds=100000\$[a-f0-9]{16}\$[./A-Za-z0-9]{86}', password)):
        raise ValueError('Invalid password hash')
    keys = settings['keys']
    if not isinstance(keys, list) or len(keys) > 10:
        raise ValueError('Invalid keys')
    for key in keys:
        if not isinstance(key, str) or len(key) > 4096 or not re.fullmatch(r'(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+/=]+', key):
            raise ValueError('Invalid public key')
        kind, encoded = key.split(' ')
        blob = base64.b64decode(encoded, validate=True)
        if len(blob) < 16 or int.from_bytes(blob[:4], 'big') != len(kind) or blob[4:4+len(kind)].decode() != kind:
            raise ValueError('Invalid public key data')
    if not password and (not keys or not settings['ssh']):
        raise ValueError('No access method')
    wifi = settings['wifi']
    if wifi is not None:
        if not isinstance(wifi, dict) or set(wifi) != {'ssid', 'password', 'security', 'country', 'hidden'}:
            raise ValueError('Invalid Wi-Fi settings')
        if not isinstance(wifi['ssid'], str) or not 1 <= len(wifi['ssid'].encode()) <= 32 or re.search(r'[\x00-\x1f\x7f]', wifi['ssid']):
            raise ValueError('Invalid SSID')
        psk = wifi['password']
        if wifi['security'] not in ('wpa2', 'mixed', 'wpa3') or type(wifi['hidden']) is not bool:
            raise ValueError('Invalid Wi-Fi security')
        if not isinstance(psk, str) or re.search(r'[\x00-\x1f\x7f]', psk) or not (
                8 <= len(psk.encode()) <= 63 or (wifi['security'] != 'wpa3' and re.fullmatch('[a-fA-F0-9]{64}', psk))):
            raise ValueError('Invalid Wi-Fi password')
        if not isinstance(wifi['country'], str) or not re.fullmatch('[A-Z]{2}', wifi['country']):
            raise ValueError('Invalid Wi-Fi country')
    return settings


def parent(root, relative):
    path = root
    for part in Path(relative).parts[:-1]:
        path /= part
        if path.is_symlink():
            raise ValueError('Setup path contains a symlink')
        path.mkdir(exist_ok=True)
    path = root / relative
    if path.is_symlink():
        raise ValueError('Setup destination is a symlink')
    return path


def write(root, relative, content, mode=0o600):
    path = parent(root, relative)
    tmp = path.with_name(path.name + '.setup-' + uuid.uuid4().hex)
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    try:
        with os.fdopen(fd, 'w') as output:
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
        os.replace(tmp, path)
        fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try: os.fsync(fd)
        finally: os.close(fd)
    finally:
        tmp.unlink(missing_ok=True)


def escape(value):
    # GLib keyfile strings: preserve spaces and literal backslashes.
    return value.replace('\\', '\\\\').replace(' ', '\\s')


def execute(args, data=None):
    result = subprocess.run(args, input=data, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                            timeout=20, env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C.UTF-8'})
    if result.returncode:
        raise RuntimeError('Station configuration command failed')


def configure(root, identity, settings, run=execute):
    settings = validate(settings)
    if (root / STATE / 'firstboot-ready').exists():
        raise ValueError('This is not an unbooted station image')
    state = root / STATE
    parent(root, STATE + '/project-setup.json')
    state.chmod(0o700)
    digest = hashlib.sha256(json.dumps(settings, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    receipt = dict(identity, digest=digest)
    marker = state / 'project-setup.json'
    pending = state / 'project-setup-pending.json'
    for path in (marker, pending):
        if path.exists():
            if json.loads(regular(path)) != receipt:
                raise ValueError('A different setup has already been staged')
            if path == marker:
                if pending.exists() and json.loads(regular(pending)) == receipt:
                    pending.unlink()
                return
    # All validation precedes writes. The marker makes retries idempotent but refuses new settings.
    accounts = [line.split(':') for line in regular(root / 'etc/passwd').splitlines()]
    first = [row for row in accounts if len(row) >= 7 and row[2] == '1000']
    user = settings['username']
    if len(first) != 1 or first[0][0] not in ('pi', user) or any(row[0] == user and row[2] != '1000' for row in accounts):
        raise ValueError('The image does not have the expected first-user account')
    write(root, STATE + '/project-setup-pending.json', json.dumps(receipt))
    # The OS tool also disables its rename wizard and updates the inherited sudo policy.
    # No password/hash is placed in argv; chpasswd receives the hash through stdin.
    run(['/usr/lib/userconf-pi/userconf', user, ''])
    run(['chpasswd', '-e'], (user + ':' + (settings['password_hash'] or '*') + '\n').encode())
    if not settings['password_hash']:
        write(root, 'etc/sudoers.d/090-cmprovision-first-user', user + ' ALL=(ALL:ALL) NOPASSWD: ALL\n', 0o440)
    write(root, 'etc/hostname', settings['hostname'] + '\n', 0o644)
    hosts = regular(root / 'etc/hosts')
    lines = [line for line in hosts.splitlines() if not re.match(r'^\s*127\.0\.1\.1\s', line)]
    write(root, 'etc/hosts', '\n'.join(lines) + '\n127.0.1.1\t' + settings['hostname'] + '\n', 0o644)
    home = 'home/' + user
    write(root, home + '/.ssh/authorized_keys', '\n'.join(settings['keys']) + '\n')
    (root / home / '.ssh').chmod(0o700)
    run(['chown', '-R', user + ':' + user, '/' + home + '/.ssh'])
    write(root, 'etc/ssh/sshd_config.d/00-cmprovision-access.conf',
          'PasswordAuthentication ' + ('yes' if settings['password_hash'] else 'no') + '\nKbdInteractiveAuthentication no\n', 0o644)
    run(['systemctl', 'enable' if settings['ssh'] else 'disable', 'ssh.service'])
    wifi = settings['wifi']
    if wifi:
        key_mgmt = 'sae' if wifi['security'] == 'wpa3' else 'wpa-psk'
        pmf = {'wpa2': 1, 'mixed': 2, 'wpa3': 3}[wifi['security']]
        # Byte-array SSID syntax avoids ambiguity for names resembling encoded byte arrays.
        ssid = ''.join(str(value) + ';' for value in wifi['ssid'].encode())
        write(root, 'etc/NetworkManager/system-connections/cmprovision-management.nmconnection',
              '[connection]\nid=cmprovision-management\nuuid=' + str(uuid.uuid4()) + '\ntype=wifi\n'
              'autoconnect=true\nmulti-connect=3\n\n[wifi]\nmode=infrastructure\nssid=' + ssid + '\n'
              'hidden=' + ('true' if wifi['hidden'] else 'false') + '\n\n[wifi-security]\nkey-mgmt=' + key_mgmt +
              '\npmf=' + str(pmf) + '\npsk-flags=0\npsk=' + escape(wifi['password']) +
              '\n\n[ipv4]\nmethod=auto\n\n[ipv6]\nmethod=auto\n')
        write(root, 'etc/systemd/system/cmprovision-image-country.service',
              '[Unit]\nDescription=Set station Wi-Fi country\nBefore=NetworkManager.service\n'
              '\n[Service]\nType=oneshot\nExecStart=/usr/sbin/iw reg set ' + wifi['country'] +
              '\nExecStart=/usr/sbin/rfkill unblock wifi\nRemainAfterExit=yes\n\n[Install]\nWantedBy=multi-user.target\n', 0o644)
        run(['systemctl', 'enable', 'cmprovision-image-country.service'])
        # NetworkManager can reapply an image's saved radio-off state after rfkill.
        # Enable Wi-Fi through its API once, preserving later operator choices.
        write(root, 'etc/systemd/system/cmprovision-image-wifi.service',
              '[Unit]\nDescription=Enable configured station Wi-Fi on its first boot\n'
              'Wants=NetworkManager.service\nAfter=NetworkManager.service\nBefore=network-online.target\n'
              'ConditionPathExists=!/var/lib/cmprovision-image/wifi-radio-ready\n'
              '\n[Service]\nType=oneshot\nExecStart=/usr/bin/nmcli radio wifi on\n'
              'ExecStartPost=/usr/bin/touch /var/lib/cmprovision-image/wifi-radio-ready\n'
              'RemainAfterExit=yes\nUMask=0077\n\n[Install]\nWantedBy=multi-user.target\n', 0o644)
        run(['systemctl', 'enable', 'cmprovision-image-wifi.service'])
    # The supported CM4 image uses eth0 for provisioning. never-default is a
    # profile property, not a NetworkManager.conf connection-default option.
    if wifi:
        write(root, 'etc/NetworkManager/system-connections/cmprovision-stand-uplink.nmconnection',
              '[connection]\nid=cmprovision-stand-uplink\nuuid=' + str(uuid.uuid4()) +
              '\ntype=ethernet\ninterface-name=eth0\nautoconnect=true\nautoconnect-priority=100\n'
              '\n[ethernet]\n\n[ipv4]\nmethod=auto\nnever-default=true\nignore-auto-dns=true\n'
              '\n[ipv6]\nmethod=auto\nnever-default=true\nignore-auto-dns=true\n')
    write(root, STATE + '/project-setup.json', json.dumps(receipt))
    pending.unlink(missing_ok=True)


def identity():
    expected = json.loads(regular(RUNTIME / 'identity.json'))
    disk = regular(RUNTIME / 'storage').strip()
    if not re.fullmatch('/dev/mmcblk[0-9]+', disk):
        raise ValueError('Invalid target storage')
    serial = Path('/proc/device-tree/serial-number').read_bytes().rstrip(b'\0').decode().lower()
    cid = Path('/sys/class/block/' + disk[5:] + '/device/cid').read_text().strip().lower()
    actual = {'schema': 1, 'serial': serial, 'cid': cid, 'run': expected.get('run')}
    if actual != expected or not re.fullmatch('[A-Za-z0-9]{40}', str(actual['run'])):
        raise ValueError('Target identity changed')
    return actual


def main():
    if os.geteuid() != 0 or len(sys.argv) != 1:
        raise ValueError('Invalid invocation')
    command = os.environ.get('SSH_ORIGINAL_COMMAND')
    if command not in ('identity', 'apply'):
        raise ValueError('Unsupported operation')
    expected = identity()
    if command == 'identity':
        print(json.dumps(expected))
        return
    raw = sys.stdin.buffer.read(32769)
    if len(raw) > 32768:
        raise ValueError('Request too large')
    request = json.loads(raw)
    if not isinstance(request, dict) or set(request) != {'identity', 'settings'} or request['identity'] != expected:
        raise ValueError('Request belongs to another run')
    fd = os.open(RUNTIME / 'lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        configure(Path('/'), expected, request['settings'])
    finally:
        os.close(fd)
    print('applied')


if __name__ == '__main__':
    try:
        main()
    except Exception:
        # Neither library exceptions nor command output may disclose credentials.
        print('Station setup failed; the image may require a fresh write.', file=sys.stderr)
        sys.exit(1)
