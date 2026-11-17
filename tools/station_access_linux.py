"""Fixed Linux operations. User-owned home files are touched only after dropping privileges."""
import base64
import grp
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess


class Linux:
    @staticmethod
    def run(argv, data=None):
        result = subprocess.run(argv, input=data, text=True, capture_output=True, timeout=10,
                                env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL': 'C'})
        if result.returncode:
            raise RuntimeError('The system account operation failed.')
        return result.stdout.strip()

    def accounts(self):
        try:
            group = grp.getgrnam('sudo')
        except KeyError:
            return []
        return [p for p in pwd.getpwall() if 1000 <= p.pw_uid < 60000 and
                (p.pw_name in group.gr_mem or p.pw_gid == group.gr_gid) and
                p.pw_shell not in ['/bin/false', '/usr/sbin/nologin', '/sbin/nologin'] and
                re.fullmatch('[a-z_][a-z0-9_-]{0,31}', p.pw_name)]

    def status(self):
        active = subprocess.run(['/bin/systemctl', 'is-active', '--quiet', 'ssh.service'], timeout=5).returncode == 0
        return {'users': [{'user': p.pw_name, 'uid': p.pw_uid} for p in self.accounts()], 'ssh_active': active}

    def inspect(self, user):
        account = next((p for p in self.accounts() if p.pw_name == user), None)
        if account is None:
            raise ValueError('Choose an existing ordinary Linux administrator in the sudo group.')
        shadow = self.run(['/usr/bin/getent', 'shadow', user])
        if not shadow.startswith(user + ':'):
            raise ValueError('The local Linux password record is unavailable.')
        return {'user': user, 'uid': account.pw_uid, 'gid': account.pw_gid, 'home': account.pw_dir,
                'shell': account.pw_shell, 'credential_revision': hashlib.sha256(shadow.encode()).hexdigest()}

    def validate_keys(self, keys):
        normalized = []
        for line in keys.splitlines():
            if not line.strip():
                continue
            fields = line.strip().split()
            if len(fields) < 2 or fields[0] not in ['ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521']:
                raise ValueError('Paste SSH public keys without command options or private-key data.')
            try:
                blob = base64.b64decode(fields[1], validate=True)
                length = int.from_bytes(blob[:4], 'big')
                if blob[4:4+length].decode('ascii') != fields[0]:
                    raise ValueError()
                self.run(['/usr/bin/ssh-keygen', '-l', '-f', '/dev/stdin'], fields[0] + ' ' + fields[1] + '\n')
            except (ValueError, UnicodeError, RuntimeError):
                raise ValueError('Invalid SSH public key.') from None
            # Discard untrusted comments. No authorized_keys options are accepted.
            key = fields[0] + ' ' + fields[1]
            if key not in normalized:
                normalized.append(key)
        if len(normalized) > 8:
            raise ValueError('Add at most eight SSH public keys in one request.')
        return '\n'.join(normalized)

    def hash_password(self, password):
        value = self.run(['/usr/bin/openssl', 'passwd', '-6', '-stdin'], password + '\n')
        if not re.fullmatch(r'\$6\$[./A-Za-z0-9]{1,16}\$[./A-Za-z0-9]{86}', value):
            raise RuntimeError('Could not hash the Linux password.')
        return value

    def policy(self):
        data = self.run(['/usr/sbin/sshd', '-T'])
        return dict(line.split(' ', 1) for line in data.splitlines() if ' ' in line)

    def apply(self, identity, changes):
        policy = self.policy()  # Validate configuration before any account changes.
        if changes['keys']:
            files = policy.get('authorizedkeysfile', '').split()
            if '.ssh/authorized_keys' not in files or policy.get('authorizedkeyscommand', 'none') != 'none':
                raise ValueError('This SSH server uses a custom key location or command. Configure keys using the OS tools.')
        applied = []
        if changes['keys']:
            self.add_keys(identity, changes['keys'])
            applied.append('keys')
        if changes['password_hash']:
            self.run(['/usr/sbin/chpasswd', '-e'], identity['user'] + ':' + changes['password_hash'] + '\n')
            applied.append('password')
        if changes['enable_ssh']:
            self.run(['/bin/systemctl', 'enable', '--now', 'ssh.service'])
            applied.append('ssh service')
        if changes['password_hash'] and policy.get('passwordauthentication') != 'yes':
            applied.append('Linux password changed; SSH password authentication remains disabled by OS policy')
        applied.append('Existing keys and SSH server policy retained; OS Match/AllowUsers rules still apply')
        return applied

    @staticmethod
    def add_keys(identity, keys):
        child = os.fork()
        if child == 0:
            try:
                os.setgroups([])
                os.setgid(identity['gid'])
                os.setuid(identity['uid'])
                Linux.write_user_keys(identity['home'], keys)
                os._exit(0)
            except Exception:
                os._exit(1)
        _, status = os.waitpid(child, 0)
        if status != 0:
            raise ValueError('Could not add keys safely. Check the home/.ssh owner, permissions and symlinks using the existing SSH session.')

    @staticmethod
    def write_user_keys(home, keys):
        directory = os.open(home, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        ssh = None
        try:
            if os.fstat(directory).st_uid != os.geteuid():
                raise ValueError('The home directory must belong to the Linux user.')
            try:
                os.mkdir('.ssh', 0o700, dir_fd=directory)
            except FileExistsError:
                pass
            ssh = os.open('.ssh', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=directory)
            info = os.fstat(ssh)
            if info.st_uid != os.geteuid() or info.st_mode & 0o022:
                raise ValueError('Unsafe SSH directory.')
            fd = os.open('authorized_keys', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600, dir_fd=ssh)
            with os.fdopen(fd, 'r+') as handle:
                import fcntl
                fcntl.flock(handle, fcntl.LOCK_EX)
                info = os.fstat(handle.fileno())
                if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_nlink != 1 or info.st_mode & 0o022 or info.st_size > 262144:
                    raise ValueError('Unsafe SSH key file.')
                original = handle.read()
                existing = {tuple(line.split()[:2]) for line in original.splitlines()}
                additions = [line for line in keys.splitlines() if tuple(line.split()[:2]) not in existing]
                if additions:
                    handle.seek(0, os.SEEK_END)
                    handle.write(('\n' if original and not original.endswith('\n') else '') +
                                 '# Added by cmprovision; existing keys retained\n' + '\n'.join(additions) + '\n')
        finally:
            if ssh is not None:
                os.close(ssh)
            os.close(directory)
