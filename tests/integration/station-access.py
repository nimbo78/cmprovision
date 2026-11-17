#!/usr/bin/env python3
"""Real shadow, privilege-drop, OpenSSH and sudo checks; disposable Docker container only."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile

assert os.geteuid() == 0 and Path('/.dockerenv').is_file(), 'Disposable Docker container required'
assert os.environ.get('CMPROVISION_ACCESS_ACCEPTANCE') == '1', 'Explicit acceptance opt-in required'
source = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(source / 'tools'))
from station_access import Controller
from station_access_linux import Linux


def run(argv, data=None):
    result = subprocess.run(argv, input=data, text=True, capture_output=True, timeout=15)
    assert result.returncode == 0, (argv[0], result.stderr[-500:])
    return result.stdout.strip()


user = 'cmp-access-test'
run(['useradd', '-m', '-G', 'sudo', '-s', '/bin/bash', user])
run(['ssh-keygen', '-A'])
Path('/run/sshd').mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(prefix='cmp-access-') as temporary:
    root = Path(temporary)
    for label in ['original', 'added']:
        run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(root / label)])
    linux = Linux()
    identity = linux.inspect(user)
    original = (root / 'original.pub').read_text().strip()
    linux.add_keys(identity, original)
    before_sudo = run(['sudo', '-l', '-U', user])
    control = Controller(root / 'state', linux)
    plan = control.request({'action': 'prepare', 'user': user, 'password': 'Fixture-access-password-123',
                            'keys': (root / 'added.pub').read_text(), 'enable_ssh': False})
    assert 'Fixture-access-password-123' not in (root / 'state/pending.json').read_text()
    assert any('SHA256:' in change for change in plan['changes']), 'Local approval must identify the exact key'
    code = control.approve(plan['id'])['code']
    result = control.request({'action': 'apply', 'id': plan['id'], 'code': code})
    assert result['state'] == 'applied'
    shadow = run(['getent', 'shadow', user]).split(':')[1]
    salt = shadow.split('$')[2]
    assert run(['openssl', 'passwd', '-6', '-salt', salt, '-stdin'], 'Fixture-access-password-123\n') == shadow
    assert run(['sudo', '-l', '-U', user]) == before_sudo, 'Sudo policy changed'
    keys = Path(identity['home']) / '.ssh/authorized_keys'
    assert original in keys.read_text() and keys.stat().st_uid == identity['uid']
    # A real sshd proves both old and added keys work after changing the password.
    with socket.socket() as channel:
        channel.bind(('127.0.0.1', 0))
        port = channel.getsockname()[1]
    config = root / 'sshd_config'
    config.write_text(f'ListenAddress 127.0.0.1\nPort {port}\nHostKey /etc/ssh/ssh_host_ed25519_key\nPidFile {root}/sshd.pid\nPasswordAuthentication no\nUsePAM no\nAuthorizedKeysFile .ssh/authorized_keys\nLogLevel ERROR\n')
    server = subprocess.Popen(['/usr/sbin/sshd', '-D', '-e', '-f', str(config)], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    try:
        import time
        for _ in range(40):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1): break
            except OSError: time.sleep(.1)
        for label in ['original', 'added']:
            assert run(['ssh', '-p', str(port), '-i', str(root / label), '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=no',
                        '-o', 'UserKnownHostsFile=/dev/null', '-o', 'BatchMode=yes', user+'@127.0.0.1', 'id -un']) == user
    finally:
        server.terminate(); server.wait(timeout=5)
    keys.unlink()
    sentinel = root / 'root-only'
    sentinel.write_text('preserve')
    sentinel.chmod(0o600)
    keys.symlink_to(sentinel)
    try:
        linux.add_keys(identity, (root / 'added.pub').read_text())
        raise AssertionError('Symlink accepted')
    except ValueError:
        pass
    assert sentinel.read_text() == 'preserve'

    installed = Path('/usr/lib/cmprovision')
    installed.mkdir(exist_ok=True)
    for name in ['station_access.py', 'station_access_linux.py', 'station-access']:
        destination = installed / name
        assert not destination.exists(), 'Refusing to replace a pre-existing installation'
        shutil.copyfile(source / 'tools' / name, destination)
        destination.chmod(0o755 if name == 'station-access' else 0o644)
    destination = Path('/usr/sbin/cmprovision-access')
    assert not destination.exists()
    shutil.copyfile(source / 'tools/station-access-admin', destination); destination.chmod(0o755)
    shutil.copyfile(source / 'debian/023_cmprovision-access', '/etc/sudoers.d/023_cmprovision-access')
    Path('/etc/sudoers.d/023_cmprovision-access').chmod(0o440)
    run(['visudo', '-cf', '/etc/sudoers.d/023_cmprovision-access'])
    status = json.loads(run(['runuser', '-u', 'www-data', '--', 'sudo', '-n', str(installed / 'station-access')], '{"action":"status"}'))
    assert status['ok'] and any(u['user'] == user for u in status['result']['users'])
    denied = subprocess.run(['runuser', '-u', 'www-data', '--', 'sudo', '-n', '/usr/sbin/cmprovision-access', 'approve', 'a'*32], capture_output=True)
    assert denied.returncode != 0, 'www-data could issue its own approval'
    denied = subprocess.run(['runuser', '-u', 'www-data', '--', 'sudo', '-n', str(installed / 'station-access'), 'approve'], capture_output=True)
    assert denied.returncode != 0, 'Extra helper arguments accepted by sudoers'
print('PASS real Linux password/key update, both SSH logins, preserved sudo, user-owned symlink refusal and web issuance denial')
