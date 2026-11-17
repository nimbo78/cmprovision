"""One-time station claim. Only the local administrator entrypoint can arm or reveal it."""
import base64
from contextlib import contextmanager
import fcntl
import grp
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import secrets
import select
import signal
import socket
import stat
import subprocess
import sys
import tempfile
import time


def generated():
    return base64.b32encode(secrets.token_bytes(10)).decode('ascii')


def durable_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def private_json(path, uid):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != uid
                or stat.S_IMODE(info.st_mode) != 0o600 or info.st_size > 8192):
            raise ValueError('Unsafe bootstrap file')
        return json.loads(stream.read(8193))


def save(path, value, group=None):
    fd, name = tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            if group is not None:
                os.fchown(fd, -1, group)
                os.fchmod(fd, 0o640)
            json.dump(value, stream, ensure_ascii=True)
            stream.flush()
            os.fsync(fd)
        os.replace(name, path)
        durable_directory(path.parent)
    finally:
        Path(name).unlink(missing_ok=True)


def artisan(payload, app=Path('/var/lib/cmprovision')):
    return bounded_reply(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/php', str(app / 'artisan'),
                          'auth:first-admin-receipt', '--no-interaction'], payload)


def bounded_reply(args, payload, timeout=15):
    # The unprivileged command may keep the pipe open after writing its reply.
    deadline = time.monotonic() + timeout
    with os.fdopen(os.memfd_create('first-admin-request'), 'w+b') as request:
        request.write(json.dumps(payload).encode())
        request.seek(0)
        with subprocess.Popen(args, cwd='/', stdin=request, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                              start_new_session=True, env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin',
                              'HOME': '/var/www', 'LANG': 'C.UTF-8'}) as proc:
            output = bytearray()
            try:
                while len(output) <= 256:
                    if not select.select([proc.stdout], [], [], max(0, deadline - time.monotonic()))[0]:
                        raise TimeoutError('Database response timed out')
                    chunk = os.read(proc.stdout.fileno(), 257 - len(output))
                    if not chunk:
                        if proc.wait(timeout=max(0, deadline - time.monotonic())) != 0:
                            raise ValueError('Database command failed')
                        reply = json.loads(output)
                        if not isinstance(reply, dict):
                            raise ValueError('Invalid database response')
                        return reply
                    output.extend(chunk)
                raise ValueError('Invalid database response')
            finally:
                if proc.returncode is None:
                    try:
                        os.killpg(proc.pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass


class Bootstrap:
    def __init__(self, root, display, database, uid, gid, clock, boot):
        self.root, self.display = root, display
        self.database, self.uid, self.gid = database, uid, gid
        self.clock, self.boot = clock, boot

    @contextmanager
    def locked(self):
        self.root.mkdir(mode=0o700, exist_ok=True)
        info = self.root.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != self.uid or stat.S_IMODE(info.st_mode) != 0o700:
            raise ValueError('Unsafe bootstrap directory')
        fd = os.open(self.root / 'lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != self.uid or info.st_nlink != 1 or info.st_mode & 0o077:
                raise ValueError('Unsafe bootstrap lock')
            fcntl.flock(fd, fcntl.LOCK_EX)
            try:
                state = private_json(self.root / 'state.json', self.uid)
            except FileNotFoundError:
                state = {'state': 'missing'}
            else:
                if not isinstance(state, dict) or state.get('state') not in ('installing', 'armed', 'claiming', 'closed'):
                    raise ValueError('Invalid bootstrap state')
                if state['state'] == 'armed' and not re.fullmatch('[A-Z2-7]{16}', state.get('code', '')):
                    raise ValueError('Invalid bootstrap code')
            yield state
        finally:
            os.close(fd)

    def project(self, code=None):
        if not os.path.lexists(self.display) and code is None:
            return
        self.display.mkdir(mode=0o750, exist_ok=True)
        info = self.display.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != self.uid or info.st_mode & 0o027:
            raise ValueError('Unsafe display directory')
        path = self.display / 'display.json'
        if code is None:
            path.unlink(missing_ok=True)
        else:
            os.chown(self.display, -1, self.gid)
            os.chmod(self.display, 0o750)
            host = socket.gethostname().split('.')[0]
            if not re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9-]{0,62}', host):
                host = 'cmprovision'
            save(path, {'code': code, 'host': host+'.local'}, self.gid)

    def close(self):
        self.project()
        save(self.root / 'state.json', {'state': 'closed'})
        return {'state': 'closed'}

    def finish(self, claim, authenticate=False):
        result = self.database(claim)
        if result == {'result': 'occupied'}:
            return self.close()
        if (result.get('result') != 'committed' or result.get('claim') != claim['claim']
                or type(result.get('id')) is not int or result['id'] <= 0):
            raise ValueError('No matching database receipt')
        closed = self.close()
        return {'state': 'claimed', 'id': result['id']} if authenticate else closed

    def image_fresh(self, directory):
        marker = directory / 'project-setup.json'
        seed = directory / 'web-setup.json'
        receipt = private_json(marker, self.uid) if os.path.lexists(marker) else {}
        expected = receipt.get('web_setup_sha256')
        if expected is not None and not re.fullmatch('[0-9a-f]{64}', expected):
            raise ValueError('Invalid image receipt')
        value = private_json(seed, self.uid) if os.path.lexists(seed) else None
        if value is not None and expected is None:
            raise ValueError('Setup code has no image receipt')
        result = self.local('fresh', value, expected)
        if value is not None:
            seed.unlink()
            durable_directory(directory)
        return result

    def local(self, action, seed=None, expected=None):
        if action not in ('begin-install', 'fresh', 'upgrade', 'resume', 'close', 'show', 'rotate'):
            raise ValueError('Unsupported operation')
        with self.locked() as state:
            phase = state['state']
            if action == 'begin-install':
                if phase == 'missing':
                    save(self.root / 'state.json', {'state': 'installing'})
                return {'state': 'closed'}
            if phase == 'installing' and action != 'close':
                if action not in ('fresh', 'upgrade'):
                    return {'state': 'closed'}
                phase, action = 'missing', 'fresh'
            if action == 'close' or (phase == 'missing' and action == 'upgrade'):
                return self.close()
            if phase == 'claiming':
                return self.finish(state['request'])
            if phase == 'closed' or (phase == 'missing' and action != 'fresh'):
                self.project()
                return {'state': 'closed'}
            occupied = self.database({})
            if occupied == {'result': 'occupied'}:
                return self.close()
            if occupied != {'result': 'empty'}:
                raise ValueError('Database unavailable')
            if expected is not None:
                code = state.get('code') if phase == 'armed' else seed
                if not isinstance(code, str) or not hmac.compare_digest(hashlib.sha256(code.encode()).hexdigest(), expected):
                    raise ValueError('Image setup code does not match the committed receipt')
            if phase == 'missing' or action == 'rotate':
                code = generated() if seed is None else seed
                if not isinstance(code, str) or not re.fullmatch('[A-Z2-7]{16}', code):
                    raise ValueError('Invalid image setup code')
                state = {'state': 'armed', 'code': code, 'boot': self.boot, 'attempts': []}
                save(self.root / 'state.json', state)
            self.project(state['code'])
            return {'state': 'armed', 'code': state['code']} if action == 'show' else {'state': 'armed'}

    def web(self, data):
        if not isinstance(data, dict):
            raise ValueError('Invalid request')
        claim = set(data) == {'action', 'name', 'email', 'password_hash', 'code'} and data['action'] == 'claim'
        if not claim and data != {'action': 'status'}:
            raise ValueError('Invalid request')
        if claim:
            for key, limit in [('code', 64), ('name', 1020), ('email', 1020), ('password_hash', 255)]:
                if not isinstance(data[key], str) or not 0 < len(data[key].encode()) <= limit:
                    raise ValueError('Invalid claim')
            if not re.fullmatch(r'\$2[ayb]\$\d{2}\$[./A-Za-z0-9]{53}', data['password_hash']):
                raise ValueError('Invalid password hash')
        with self.locked() as state:
            if state['state'] == 'claiming':
                return self.finish(state['request'])
            if state['state'] != 'armed':
                return {'state': 'closed'}
            if not claim:
                availability = self.database({})
                if availability == {'result': 'occupied'}:
                    return self.close()
                if availability != {'result': 'empty'}:
                    raise ValueError('Database unavailable')
                return {'state': 'armed'}
            now = int(self.clock())
            attempts = [t for t in state['attempts'] if now - 60 < t] if state['boot'] == self.boot else []
            if len(attempts) >= 5:
                return {'state': 'limited'}
            typed = data['code'].replace(' ', '').replace('-', '').upper()
            if not typed.isascii() or not hmac.compare_digest(typed, state['code']):
                state.update(attempts=[*attempts, now], boot=self.boot)
                save(self.root / 'state.json', state)
                return {'state': 'invalid'}
            request = {k: data[k] for k in ('name', 'email', 'password_hash')}
            request['claim'] = secrets.token_hex(16)
            save(self.root / 'state.json', {'state': 'claiming', 'request': request})
            self.project()
            return self.finish(request, authenticate=True)


def main(local=False):
    if os.geteuid() != 0 or len(sys.argv) != (2 if local else 1):
        raise ValueError('Invalid invocation')
    # On the first dpkg configure the display group is created after recording install intent.
    display_gid = None if local and sys.argv[1] == 'begin-install' else grp.getgrnam('cmprovision-wallboard').gr_gid
    broker = Bootstrap(Path('/var/lib/cmprovision-bootstrap'), Path('/run/cmprovision-bootstrap'),
                       artisan, 0, display_gid, time.monotonic,
                       Path('/proc/sys/kernel/random/boot_id').read_text().strip())
    if local:
        action = sys.argv[1]
        if action == 'image-fresh':
            result = broker.image_fresh(Path('/var/lib/cmprovision-image'))
        else:
            result = broker.local(action)
        if action == 'show' and 'code' in result:
            print(' '.join(result['code'][i:i+4] for i in range(0, 16, 4)))
        else:
            print(result['state'])
    else:
        raw = sys.stdin.buffer.read(8193)
        if len(raw) > 8192:
            raise ValueError('Request too large')
        print(json.dumps(broker.web(json.loads(raw))))


def entry(local=False):
    try:
        main(local)
    except (OSError, ValueError, KeyError, TypeError, RecursionError, subprocess.SubprocessError):
        print('Station setup unavailable.', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(entry())
