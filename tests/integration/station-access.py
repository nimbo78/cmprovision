#!/usr/bin/env python3
"""Real shadow, privilege-drop, OpenSSH and sudo checks in a disposable container."""
import json
import os
from pathlib import Path
import pwd
import shutil
import signal
import socket
import subprocess
import sys
import tempfile

def in_volatile_nspawn():
    marker = Path('/run/host/container-manager')
    return (marker.is_file() and marker.read_text().strip() == 'systemd-nspawn'
            and subprocess.check_output(['findmnt', '-n', '-o', 'FSTYPE', '/'], text=True).strip() == 'overlay')


assert os.geteuid() == 0 and (Path('/.dockerenv').is_file() or in_volatile_nspawn()), 'Disposable container required'
assert os.environ.get('CMPROVISION_ACCESS_ACCEPTANCE') == '1', 'Explicit acceptance opt-in required'
source = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(source / 'tools'))
from station_access import Controller
from station_access_linux import Linux


def run(argv, data=None):
    result = subprocess.run(argv, input=data, text=True, capture_output=True, timeout=15)
    assert result.returncode == 0, (argv[0], result.stderr[-500:])
    return result.stdout.strip()


def check_systemd_rollback():
    unit = 'ssh.service'
    runs = 0
    for enabled in ('disabled', 'enabled', 'enabled-runtime'):
        for active in (False, True):
            for failure in ('links', 'later'):
                run(['systemctl', 'disable', '--now', unit])
                run(['systemctl', '--runtime', 'disable', unit])
                if enabled != 'disabled':
                    runtime = ['--runtime'] if enabled == 'enabled-runtime' else []
                    run(['systemctl', *runtime, 'enable', unit])
                if active:
                    run(['systemctl', 'start', unit])

                class PartialFailure(Linux):
                    def run(self, argv, data=None):
                        if failure == 'links' and argv[1:] == ['enable', '--now', unit]:
                            super().run(['/bin/systemctl', 'enable', unit])
                            raise RuntimeError('fixture')
                        return super().run(argv, data)

                backend = PartialFailure()
                assert backend.service_state() == (enabled, active)
                try:
                    with backend.service_activation() as enable:
                        enable()
                        assert backend.service_state() == ('enabled', True)
                        raise RuntimeError('fixture')
                except RuntimeError as error:
                    assert str(error) == 'fixture'
                assert backend.service_state() == (enabled, active)
                runs += 1
    print('PASS', runs, 'real systemd SSH service rollback scenarios')


user = 'cmp-access-test'
run(['useradd', '-m', '-G', 'sudo', '-s', '/bin/bash', user])
run(['ssh-keygen', '-A'])
# A stopped Debian ssh.service removes its RuntimeDirectory.
if Path('/run/sshd').is_dir():
    Path('/run/sshd').rmdir()
with tempfile.TemporaryDirectory(prefix='cmp-access-') as temporary:
    root = Path(temporary)
    for label in ['original', 'added', 'uncommitted']:
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
    dropins = root / 'sshd_config.d'
    dropins.mkdir()
    (dropins / '00-cmprovision-access.conf').write_text('PasswordAuthentication no\nKbdInteractiveAuthentication no\n')
    config.write_text(f'Include {dropins}/*.conf\nListenAddress 127.0.0.1\nPort {port}\nHostKey /etc/ssh/ssh_host_ed25519_key\nPidFile {root}/sshd.pid\nUsePAM no\nAuthorizedKeysFile .ssh/authorized_keys\nLogLevel ERROR\n')
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

        class TestDaemon(Linux):
            sshd_config = config
            fail_activation = False
            fail_reload = False
            change_expiry = None

            def reload_ssh(self):
                server.send_signal(signal.SIGHUP)
                if self.change_expiry:
                    run(['chage', '--expiredate', '2', '--', self.change_expiry])
                    self.change_expiry = None
                if self.fail_reload:
                    self.fail_reload = False
                    raise RuntimeError('Fixture rejected SSH reload')

            def run(self, argv, data=None):
                if self.fail_activation and argv[0] == '/usr/bin/chage':
                    assert run(['getent', 'shadow', argv[-1]]).split(':')[7] == '1'
                    raise RuntimeError('Fixture rejected final activation')
                return super().run(argv, data)

        test_daemon = TestDaemon()
        control = Controller(root / 'new-requests', test_daemon)
        def approve_create(name, password='', keys='', allow=False):
            plan = control.request({'action': 'prepare', 'user': name, 'password': password, 'keys': keys,
                                    'enable_ssh': False, 'create_user': True, 'allow_password_login': allow})
            try:
                pwd.getpwnam(name)
            except KeyError:
                pass
            else:
                raise AssertionError('An unapproved account was created')
            code = control.approve(plan['id'])['code']
            return control.request({'action': 'apply', 'id': plan['id'], 'code': code})

        new_user = 'cmp-new-admin'
        new_password = 'Fixture-new-admin-20'
        assert approve_create(new_user, password=new_password, allow=True)['state'] == 'applied'
        assert 'sudo' in run(['id', '-Gn', new_user]).split()
        as_user = ['runuser', '-u', new_user, '--', 'sudo', '-k']
        assert subprocess.run(as_user + ['-n', 'id', '-u'], capture_output=True).returncode != 0
        assert run(as_user + ['-S', 'id', '-u'], new_password + '\n') == '0'
        assert run(['sudo', '-l', '-U', user]) == before_sudo
        askpass = root / 'ssh-askpass'
        askpass.write_text('#!/bin/sh\nprintf "%s\\n" "$FIXTURE_PASSWORD"\n')
        askpass.chmod(0o700)
        ssh = ['ssh', '-p', str(port), '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=no',
               '-o', 'UserKnownHostsFile=/dev/null']
        def password_login(name, password):
            return subprocess.run(ssh + ['-o', 'PreferredAuthentications=password', '-o', 'NumberOfPasswordPrompts=1',
                                         name+'@127.0.0.1', 'id -un'], stdin=subprocess.DEVNULL, text=True,
                                  capture_output=True, timeout=10, env=os.environ | {
                                      'SSH_ASKPASS': str(askpass), 'SSH_ASKPASS_REQUIRE': 'force', 'FIXTURE_PASSWORD': password,
                                  })
        assert password_login(new_user, new_password).stdout.strip() == new_user
        assert password_login(user, 'Fixture-access-password-123').returncode != 0

        key_user = 'cmp-keys-admin'
        assert approve_create(key_user, keys=(root / 'added.pub').read_text())['state'] == 'applied'
        assert run(ssh + ['-i', str(root / 'added'), '-o', 'BatchMode=yes', key_user+'@127.0.0.1', 'id -un']) == key_user
        assert run(['getent', 'shadow', key_user]).split(':')[1] == '*'
        assert 'NOPASSWD:' not in run(['sudo', '-l', '-U', key_user])

        run(['chage', '--lastday', '20000', '--', user])
        before_shadow = run(['getent', 'shadow', user])
        before_keys = keys.read_bytes()
        test_daemon.fail_reload = True
        change = control.request({'action': 'prepare', 'user': user, 'password': new_password,
                                  'keys': (root / 'uncommitted.pub').read_text(), 'enable_ssh': False,
                                  'allow_password_login': True})
        code = control.approve(change['id'])['code']
        try:
            control.request({'action': 'apply', 'id': change['id'], 'code': code})
            raise AssertionError('Fixture expected the SSH reload to fail')
        except RuntimeError as error:
            assert str(error) == 'Fixture rejected SSH reload'
        assert run(['getent', 'shadow', user]) == before_shadow, 'Original password and its age must survive a rejected reload'
        assert keys.read_bytes() == before_keys, 'An unsuccessful request added a key'
        assert password_login(user, new_password).returncode != 0

        before_config = config.read_bytes()
        test_daemon.fail_activation = True
        failed_user = 'cmp-failed-admin'
        try:
            approve_create(failed_user, password=new_password, allow=True)
            raise AssertionError('Fixture expected activation to fail')
        except RuntimeError as error:
            assert str(error) == 'Fixture rejected final activation'
        assert config.read_bytes() == before_config
        assert subprocess.run(['getent', 'passwd', failed_user], capture_output=True).returncode != 0
        assert subprocess.run(['getent', 'group', failed_user], capture_output=True).returncode != 0
        assert not Path('/home', failed_user).exists()
        assert password_login(new_user, new_password).stdout.strip() == new_user

        # The console operator suspends the staged account between SSH reload and its first activation.
        test_daemon.fail_activation = False
        test_daemon.change_expiry = 'cmp-edited-admin'
        try:
            approve_create('cmp-edited-admin', password=new_password, allow=True)
            raise AssertionError('External account suspension was cleared')
        except ValueError:
            pass
        assert run(['getent', 'shadow', 'cmp-edited-admin']).split(':')[7] == '2'
        assert config.read_bytes() == before_config

        run(['chage', '--expiredate', '1', '--', user])
        before_expired = run(['getent', 'shadow', user])
        try:
            control.request({'action': 'prepare', 'user': user, 'password': new_password, 'keys': '',
                             'enable_ssh': False, 'allow_password_login': True})
            raise AssertionError('Account expiration was ignored')
        except ValueError:
            pass
        assert run(['getent', 'shadow', user]) == before_expired
        run(['chage', '--expiredate', '-1', '--', user])
    finally:
        server.terminate(); server.wait(timeout=5)
        if sys.exc_info()[0] is not None:
            print(server.stderr.read().decode(errors='replace')[-1500:], file=sys.stderr)
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
if os.environ.get('CMPROVISION_ACCESS_SYSTEMD_ACCEPTANCE') == '1':
    check_systemd_rollback()
print('PASS real existing/new administrators, per-user SSH password and key logins, password-required sudo, failed creation rollback, safe keys and local-only approval')
