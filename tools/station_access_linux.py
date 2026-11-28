"""Fixed Linux operations. User-owned home files are touched only after dropping privileges."""
import base64
from contextlib import contextmanager, nullcontext
import grp
import hashlib
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import tempfile
import time


class AccountChanged(ValueError):
    """The local operator now owns the disposition of this staged account."""


class Linux:
    home_root = Path('/home')
    sshd_config = Path('/etc/ssh/sshd_config')

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

    def inspect(self, user, create_user=False, require_password=False, allow_password_login=False):
        if create_user:
            try:
                pwd.getpwnam(user)
            except KeyError:
                pass
            else:
                raise ValueError('That Linux username already exists.')
            try:
                grp.getgrnam(user)
            except KeyError:
                pass
            else:
                raise ValueError('A Linux group already uses that name.')
            if any(user in group.gr_mem for group in grp.getgrall()):
                raise ValueError('That username already has Linux group memberships.')
            info = self.home_root.lstat()
            if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o022:
                raise ValueError('The Linux home directory parent is unsafe.')
            if os.path.lexists(self.home_root / user):
                raise ValueError('A home directory entry already uses that name.')
            return {'user': user}
        account = next((p for p in self.accounts() if p.pw_name == user), None)
        if account is None:
            raise ValueError('Choose an existing ordinary Linux administrator in the sudo group.')
        fields = self.shadow_record(user)
        if require_password or allow_password_login:
            today = int(time.time()) // 86400
            if 0 <= int(fields[7] or '-1') <= today:
                raise ValueError('This Linux account has expired. Review its expiration using the existing console.')
        if require_password:
            valid_hash = re.fullmatch(r'\$[A-Za-z0-9]+\$[^:!*\s]+\$[./A-Za-z0-9]{10,}|[./A-Za-z0-9]{13}', fields[1])
            last, maximum = (int(fields[i] or '-1') for i in (2, 4))
            if not valid_hash or last == 0 or (maximum >= 0 and last >= 0 and last + maximum < today):
                raise ValueError('Set a usable Linux password before enabling SSH password login.')
        return {'user': user, 'uid': account.pw_uid, 'gid': account.pw_gid, 'home': account.pw_dir,
                'shell': account.pw_shell, 'credential_revision': hashlib.sha256(':'.join(fields).encode()).hexdigest()}

    def shadow_record(self, user):
        fields = self.run(['/usr/bin/getent', 'shadow', user]).split(':')
        if len(fields) != 9 or fields[0] != user:
            raise ValueError('The local Linux password record is unavailable.')
        return fields

    @contextmanager
    def preserve_password_on_failure(self, user, new_hash):
        before = self.shadow_record(user)
        try:
            yield
        except BaseException:
            # A concurrent password rotation supersedes our change. Never overwrite it.
            if self.shadow_record(user)[1] == new_hash:
                self.run(['/usr/sbin/chpasswd', '-e'], user + ':' + before[1] + '\n')
                self.run(['/usr/bin/chage', '--lastday', before[2] or '-1', '--', user])
            raise

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

    @staticmethod
    def prepare_sshd_runtime():
        # Debian removes this directory when ssh.service stops. Even sshd -t/-T need it.
        path = '/run/sshd'
        try:
            os.mkdir(path, 0o755)
            created = True
        except FileExistsError:
            created = False
        fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            info = os.fstat(fd)
            if info.st_uid != 0 or info.st_mode & 0o022:
                raise ValueError('Unsafe SSH runtime directory.')
            if created:
                os.fchmod(fd, 0o755)
        finally:
            os.close(fd)

    def policy(self, user, config):
        data = self.run(['/usr/sbin/sshd', '-T', '-f', str(config), '-C', f'user={user},host=localhost,addr=127.0.0.1'])
        return dict(line.split(' ', 1) for line in data.splitlines() if ' ' in line)

    def reload_ssh(self):
        state = self.run(['/bin/systemctl', 'show', '--property=ActiveState', '--value', 'ssh.service'])
        if state in ('active', 'activating', 'reloading'):
            self.run(['/bin/systemctl', 'reload', 'ssh.service'])

    def service_state(self):
        output = self.run(['/bin/systemctl', 'show', '--property=UnitFileState', '--property=ActiveState', 'ssh.service'])
        state = dict(line.split('=', 1) for line in output.splitlines() if '=' in line)
        enabled = state.get('UnitFileState')
        active = state.get('ActiveState')
        if enabled not in ('enabled', 'enabled-runtime', 'disabled') or active not in ('active', 'inactive', 'failed', 'activating', 'deactivating', 'reloading', 'refreshing'):
            raise ValueError('The SSH service has no manageable enabled/running state. Inspect ssh.service using the existing console.')
        return enabled, active in ('active', 'activating', 'reloading', 'refreshing')

    @contextmanager
    def service_activation(self):
        was_enabled, was_active = self.service_state()
        attempted = False

        def enable():
            nonlocal attempted
            attempted = True  # systemctl can create unit links even when starting the daemon then fails.
            self.run(['/bin/systemctl', 'enable', '--now', 'ssh.service'])

        try:
            yield enable
        except BaseException:
            if attempted:
                enabled, active = self.service_state()
                commands = []
                if active and not was_active:
                    commands.append(['stop'])
                if enabled != was_enabled:
                    if enabled == 'enabled':
                        commands.append(['disable'])
                    if enabled == 'enabled-runtime' and was_enabled == 'disabled':
                        commands.append(['disable', '--runtime'])
                    if was_enabled == 'enabled-runtime':
                        commands.append(['enable', '--runtime'])
                    if was_enabled == 'enabled':
                        commands.append(['enable'])
                if was_active and not active:
                    commands.append(['start'])
                for command in commands:
                    try:
                        self.run(['/bin/systemctl', *command, 'ssh.service'])
                    except (OSError, RuntimeError, subprocess.TimeoutExpired):
                        pass  # Restore the independent state too, then verify both from systemd.
                if self.service_state() != (was_enabled, was_active):
                    raise ValueError('Could not restore the previous SSH service state. Inspect ssh.service using the existing console.') from None
            raise

    @contextmanager
    def config_copy(self, content, info):
        fd, filename = tempfile.mkstemp(prefix='.cmprovision-sshd-', dir=self.sshd_config.parent)
        path = Path(filename)
        try:
            with os.fdopen(fd, 'wb') as output:
                output.write(content)
                os.fchown(fd, info.st_uid, info.st_gid)
                os.fchmod(fd, stat.S_IMODE(info.st_mode))
            yield path
        finally:
            path.unlink(missing_ok=True)

    @contextmanager
    def password_login(self, user):
        # Appending a literal Match at EOF preserves global-only directives following the vendor Include.
        # An earlier matching PasswordAuthentication or AuthenticationMethods keeps precedence.
        fd = os.open(self.sshd_config, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(fd, 'rb') as source:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o022 or info.st_nlink != 1 or info.st_size > 1048576:
                raise ValueError('Unsafe main SSH configuration. Check it using the existing console.')
            original = source.read()
        block = f'\n# cmprovision station access\nMatch User {user}\n    PasswordAuthentication yes\n'.encode()
        content = original if block in original else original + block
        with self.config_copy(content, info) as candidate, self.config_copy(original, info) as backup:
            try:
                self.run(['/usr/sbin/sshd', '-t', '-f', str(candidate)])
                policy = self.policy(user, candidate)
            except RuntimeError:
                raise ValueError('OpenSSH rejected the candidate configuration. Check SSH settings in the existing console.') from None
            if policy.get('passwordauthentication') != 'yes' or not {'any', 'password'}.intersection(policy['authenticationmethods'].split()):
                raise ValueError('Existing SSH rules prevent password login for this user. Review them using the existing console.')
            installed = None

            def install():
                nonlocal installed
                current = self.sshd_config.lstat()
                if not os.path.samestat(current, info) or current.st_ctime_ns != info.st_ctime_ns:
                    raise ValueError('The SSH configuration changed during this request. Prepare a new request.')
                if content != original:
                    os.replace(candidate, self.sshd_config)
                    installed = self.sshd_config.lstat()
                self.reload_ssh()

            try:
                yield policy, install
            except BaseException:
                if installed is not None:
                    current = self.sshd_config.lstat()
                    if not os.path.samestat(current, installed) or current.st_ctime_ns != installed.st_ctime_ns:
                        raise ValueError('SSH configuration changed again. Inspect it using the existing console.') from None
                    os.replace(backup, self.sshd_config)
                    self.reload_ssh()
                raise

    @contextmanager
    def new_admin(self, user):
        self.inspect(user, create_user=True)
        home = str(self.home_root / user)
        # The past expiry prevents login by *any* method until credentials and daemon settings are ready.
        self.run(['/usr/sbin/useradd', '--create-home', '--home-dir', home, '--user-group', '--groups', 'sudo',
                  '--shell', '/bin/bash', '--expiredate', '1970-01-02', '-K', 'UID_MIN=1000', '-K', 'UID_MAX=59999', '--', user])
        account = pwd.getpwnam(user)
        if (account.pw_dir != home or not 1000 <= account.pw_uid < 60000 or account.pw_shell != '/bin/bash'
                or grp.getgrgid(account.pw_gid).gr_name != user):
            raise ValueError('The new administrator has an unexpected identity. Inspect it using the existing console.')
        try:
            identity = self.inspect(user)
            if identity['uid'] != account.pw_uid or identity['gid'] != account.pw_gid or identity['home'] != home:
                raise AccountChanged('The new account changed during preparation.')
            sudo = self.run(['/usr/bin/sudo', '-n', '-l', '-U', user])
            if 'NOPASSWD:' in sudo or '!authenticate' in sudo:
                raise ValueError('Existing sudo rules grant this name passwordless access. Choose another name or review sudoers.')
            yield identity
        except AccountChanged:
            raise
        except BaseException:
            # Remove only the account and fresh home created by this request.
            try:
                current = pwd.getpwnam(user)
            except KeyError:
                current = None
            if current == account and self.shadow_record(user)[7] == '1':
                self.run(['/usr/sbin/userdel', '--remove', '--', user])
            raise

    def activate_admin(self, identity, expected_hash):
        user = identity['user']
        try:
            current = pwd.getpwnam(user)
        except KeyError:
            raise AccountChanged('The new account disappeared before activation.') from None
        if (current.pw_uid, current.pw_gid, current.pw_dir, current.pw_shell) != (
                identity['uid'], identity['gid'], identity['home'], identity['shell']):
            raise AccountChanged('The new account identity changed before activation.')
        shadow = self.shadow_record(user)
        if shadow[7] != '1' or shadow[1] != expected_hash:
            raise AccountChanged('The new account credentials or expiration changed before activation.')
        self.run(['/usr/bin/chage', '--expiredate', '-1', '--', user])

    def apply(self, identity, changes):
        user = identity['user']
        create = changes.get('create_user', False)
        allow_password = changes.get('allow_password_login', False)
        applied = ['New Linux administrator in the sudo group'] if create else []
        self.prepare_sshd_runtime()
        target = self.new_admin(user) if create else nullcontext(identity)
        service = self.service_activation() if changes['enable_ssh'] else nullcontext(None)
        with service as enable_service, target as account:
            config = self.password_login(user) if allow_password else nullcontext((self.policy(user, self.sshd_config), None))
            password_guard = nullcontext()
            if allow_password and not create and changes['password_hash']:
                password_guard = self.preserve_password_on_failure(user, changes['password_hash'])
            with password_guard, config as (policy, install):
                if changes['keys']:
                    files = policy.get('authorizedkeysfile', '').split()
                    if '.ssh/authorized_keys' not in files or policy.get('authorizedkeyscommand', 'none') != 'none':
                        raise ValueError('This SSH server uses a custom key location or command. Configure keys using the OS tools.')
                    if create and not changes['password_hash'] and policy.get('pubkeyauthentication') != 'yes':
                        raise ValueError('Public-key authentication is disabled for the new administrator.')
                    if not install:
                        self.add_keys(account, changes['keys'])
                    applied.append('keys')
                if create or changes['password_hash']:
                    # '*' has no matching password and still permits SSH public keys with UsePAM no.
                    self.run(['/usr/sbin/chpasswd', '-e'], user + ':' + (changes['password_hash'] or '*') + '\n')
                    if changes['password_hash']:
                        applied.append('password')
                if install:
                    install()
                    applied.append('SSH password login allowed for this user')
                if enable_service:
                    enable_service()
                    applied.append('ssh service')
                if install and changes['keys']:
                    self.add_keys(account, changes['keys'])
                if create:
                    self.activate_admin(account, changes['password_hash'] or '*')
                if changes['password_hash'] and policy.get('passwordauthentication') != 'yes':
                    applied.append('Linux password changed; SSH password authentication remains disabled by OS policy')
        applied.append('Existing SSH keys retained; OS Match/AllowUsers rules still apply')
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
