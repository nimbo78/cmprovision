"""Immutable, operation-bound station access requests. Issuance is a separate root CLI."""
import base64
from contextlib import contextmanager
import fcntl
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import secrets
import stat
import sys
import time

STATE = Path('/run/cmprovision-access')


class Controller:
    def __init__(self, directory, backend, clock=time.time):
        self.root = Path(directory)
        self.backend = backend
        self.clock = clock

    @contextmanager
    def locked(self):
        self.root.mkdir(mode=0o700, exist_ok=True)
        info = self.root.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
            raise ValueError('Unsafe station access state directory.')
        fd = os.open(self.root / 'lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_nlink != 1 or info.st_mode & 0o077:
                raise ValueError('Unsafe station access lock.')
            fcntl.flock(fd, fcntl.LOCK_EX)
            yield
        finally:
            os.close(fd)

    def read(self, name):
        try:
            fd = os.open(self.root / name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        except FileNotFoundError:
            return None
        with os.fdopen(fd) as handle:
            info = os.fstat(handle.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_nlink != 1 or info.st_mode & 0o077 or info.st_size > 32768:
                raise ValueError('Unsafe station access state file.')
            value = json.load(handle)
            if not isinstance(value, dict):
                raise ValueError('Invalid station access state.')
            return value

    def write(self, name, value):
        # Never follow an existing destination, even when renaming would itself be safe.
        self.read(name)
        temporary = self.root / ('.' + secrets.token_hex(12))
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            with os.fdopen(fd, 'w') as handle:
                json.dump(value, handle)
            os.replace(temporary, self.root / name)
        finally:
            temporary.unlink(missing_ok=True)

    def pending(self):
        pending = self.read('pending.json')
        if pending and (self.clock() >= pending['expires_at'] or self.clock() < pending['created_at']):
            (self.root / 'pending.json').unlink()
            return None
        return pending

    @staticmethod
    def summary(plan):
        return {key: plan[key] for key in ['id', 'user', 'changes', 'created_at', 'expires_at', 'state']}

    @staticmethod
    def schema(data, keys):
        if not isinstance(data, dict) or set(data) != set(keys):
            raise ValueError('Invalid station access request.')

    def require_plan(self, operation):
        if not isinstance(operation, str) or not re.fullmatch('[0-9a-f]{32}', operation):
            raise ValueError('Invalid operation reference.')
        plan = self.pending()
        if not plan or not hmac.compare_digest(plan['id'], operation):
            raise ValueError('This access request has expired or is no longer available.')
        return plan

    def request(self, data):
        with self.locked():
            action = data.get('action') if isinstance(data, dict) else None
            if action == 'status':
                self.schema(data, ['action'])
                plan = self.pending()
                return self.backend.status() | {'pending': self.summary(plan) if plan else None}
            if action == 'prepare':
                self.schema(data, ['action', 'user', 'password', 'keys', 'enable_ssh'])
                if self.pending():
                    raise ValueError('Finish or cancel the existing access request first.')
                user, password, keys, enable = (data[k] for k in ['user', 'password', 'keys', 'enable_ssh'])
                if not isinstance(user, str) or not re.fullmatch('[a-z_][a-z0-9_-]{0,31}', user) or user == 'root':
                    raise ValueError('Choose an existing ordinary Linux administrator.')
                if not isinstance(password, str) or len(password) > 256 or any(c in password for c in '\r\n\x00') or (password and len(password) < 8):
                    raise ValueError('Use a Linux password of 8–256 characters without line breaks.')
                if not isinstance(keys, str) or len(keys) > 16384 or type(enable) is not bool:
                    raise ValueError('Invalid keys or SSH service choice.')
                if not password and not keys.strip() and not enable:
                    raise ValueError('Choose at least one SSH access change.')
                identity = self.backend.inspect(user)
                keys = self.backend.validate_keys(keys)
                changes = {'password_hash': self.backend.hash_password(password) if password else '',
                           'keys': keys, 'enable_ssh': enable}
                fingerprints = ['SHA256:' + base64.b64encode(hashlib.sha256(base64.b64decode(key.split()[1])).digest()).decode().rstrip('=') for key in keys.splitlines()]
                labels = (['Change Linux password'] if password else []) + ['Add SSH key ' + value + '; preserve existing keys' for value in fingerprints] + (['Enable SSH service'] if enable else [])
                now = int(self.clock())
                plan = {'id': secrets.token_hex(16), 'user': user, 'identity': identity, 'payload': changes,
                        'changes': labels, 'created_at': now, 'expires_at': now + 300, 'state': 'awaiting_approval', 'attempts': 0}
                self.write('pending.json', plan)
                return self.summary(plan)
            if action == 'cancel':
                self.schema(data, ['action', 'id'])
                plan = self.require_plan(data['id'])
                (self.root / 'pending.json').unlink()
                return {'id': plan['id'], 'state': 'cancelled'}
            if action == 'apply':
                self.schema(data, ['action', 'id', 'code'])
                plan = self.require_plan(data['id'])
                code = data['code']
                if not isinstance(code, str) or len(code) > 64:
                    raise ValueError('Invalid authorization code.')
                normalized = code.replace('-', '').replace(' ', '').upper()
                digest = hashlib.sha256((plan['id'] + ':' + normalized).encode()).hexdigest()
                if plan['state'] != 'approved' or not hmac.compare_digest(plan.get('code_hash', ''), digest):
                    plan['attempts'] += 1
                    if plan['attempts'] >= 5:
                        (self.root / 'pending.json').unlink()
                    else:
                        self.write('pending.json', plan)
                    raise ValueError('The code is incorrect, expired or not approved. Request a new code if necessary.')
                # Consume before the privileged side effect, including partial backend failures.
                (self.root / 'pending.json').unlink()
                receipt = self.summary(plan) | {'state': 'failed', 'finished_at': int(self.clock())}
                self.write('receipt.json', receipt)
                if self.backend.inspect(plan['user']) != plan['identity']:
                    raise ValueError('The Linux account changed after preparation. Prepare and approve a new request.')
                applied = self.backend.apply(plan['identity'], plan['payload'])
                receipt.update(state='applied', applied=applied)
                self.write('receipt.json', receipt)
                return receipt
            raise ValueError('Unknown station access action.')

    def review(self, operation):
        with self.locked():
            return self.summary(self.require_plan(operation))

    def approve(self, operation):
        """Local privileged entrypoint only; never reachable through request()."""
        with self.locked():
            plan = self.require_plan(operation)
            code = base64.b32encode(secrets.token_bytes(8)).decode()[:12]
            plan.update(state='approved', attempts=0, expires_at=min(plan['expires_at'], int(self.clock()) + 120),
                        code_hash=hashlib.sha256((plan['id'] + ':' + code).encode()).hexdigest())
            self.write('pending.json', plan)
            return self.summary(plan) | {'code': '-'.join(code[n:n+4] for n in range(0, 12, 4))}


def main():
    sys.path.insert(0, os.path.dirname(os.path.realpath(__file__)))
    from station_access_linux import Linux
    os.umask(0o077)
    if os.geteuid() != 0 or len(sys.argv) != 1:
        raise SystemExit(1)
    try:
        raw = sys.stdin.buffer.read(32769)
        if len(raw) > 32768:
            raise ValueError('Station access request is too large.')
        result = Controller(STATE, Linux()).request(json.loads(raw))
        print(json.dumps({'ok': True, 'result': result}))
    except ValueError as error:
        print(json.dumps({'ok': False, 'error': str(error)}))
        raise SystemExit(1)
    except Exception:
        print(json.dumps({'ok': False, 'error': 'Station access failed. Check the Linux account and SSH service using the existing console.'}))
        raise SystemExit(1)


if __name__ == '__main__':
    main()
