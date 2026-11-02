"""Root-only durable state and cross-process guard for network transactions.

Paths passed by production callers must be fixed constants, never input from the web application.
"""
from contextlib import contextmanager
import fcntl
import json
import os
import secrets
import stat
import time


class UnsafeStore(RuntimeError):
    pass


class Store:
    def __init__(self, state_dir, runtime_dir, verify_root=True):
        self.state_dir = state_dir
        self.runtime_dir = runtime_dir
        self.verify_root = verify_root
        self.state_path = os.path.join(state_dir, 'pending.json')
        self.marker_path = os.path.join(runtime_dir, 'pending')
        self.lock_path = os.path.join(runtime_dir, 'provisioning.lock')

    def _dir(self, path, mode):
        try:
            os.mkdir(path, mode)
            os.chmod(path, mode, follow_symlinks=False)
        except FileExistsError:
            pass
        info = os.lstat(path)
        if not stat.S_ISDIR(info.st_mode) or (self.verify_root and info.st_uid != 0) or info.st_mode & 0o022:
            raise UnsafeStore('The network transaction directory is not secure.')
        if (mode == 0o700 and info.st_mode & 0o077) or (mode == 0o755 and info.st_mode & 0o555 != 0o555):
            raise UnsafeStore('The network transaction directory has incorrect access permissions.')

    def _open(self, path, flags, mode=0o600, private=False):
        fd = os.open(path, flags | os.O_NOFOLLOW, mode)
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or (self.verify_root and info.st_uid != 0) or info.st_mode & (0o077 if private else 0o022):
            os.close(fd)
            raise UnsafeStore('The network transaction file is not secure.')
        return fd

    @staticmethod
    def _sync_dir(directory):
        fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)

    def initialize(self):
        # The caller creates the parent of state_dir as root before invoking this method.
        self._dir(self.state_dir, 0o700)
        self._dir(self.runtime_dir, 0o755)
        # Publish the recovery marker before creating the lock a web worker could acquire.
        if os.path.lexists(self.state_path):
            self.set_pending()
            self.load()  # malformed state still leaves the gate closed
        try:
            fd = self._open(self.lock_path, os.O_RDONLY | os.O_CREAT | os.O_EXCL, 0o644)
            os.fchmod(fd, 0o644)
        except FileExistsError:
            fd = self._open(self.lock_path, os.O_RDONLY)
            if os.fstat(fd).st_mode & 0o444 != 0o444:
                os.close(fd)
                raise UnsafeStore('The provisioning lock is not readable by the web worker.')
        os.close(fd)

    @contextmanager
    def guard(self, timeout=10):
        fd = self._open(self.lock_path, os.O_RDONLY)
        try:
            end = time.monotonic() + timeout
            while True:
                try:
                    fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    break
                except BlockingIOError:
                    if time.monotonic() >= end:
                        raise UnsafeStore('Another provisioning or network operation is starting.')
                    time.sleep(.05)
            try:
                yield
            finally:
                fcntl.flock(fd, fcntl.LOCK_UN)
        finally:
            os.close(fd)

    def load(self):
        try:
            fd = self._open(self.state_path, os.O_RDONLY, private=True)
        except FileNotFoundError:
            return None
        try:
            if os.fstat(fd).st_size > 131072:
                raise UnsafeStore('The network transaction state is too large.')
            with os.fdopen(fd, encoding='utf-8', closefd=False) as source:
                value = json.load(source)
        except (ValueError, UnicodeError) as exc:
            raise UnsafeStore('The network transaction state is invalid.') from exc
        finally:
            os.close(fd)
        if not isinstance(value, dict) or value.get('version') != 1 or not isinstance(value.get('token'), str):
            raise UnsafeStore('The network transaction state is invalid.')
        return value

    def store(self, value):
        data = json.dumps(value, separators=(',', ':'), ensure_ascii=True).encode('ascii')
        if len(data) > 131072:
            raise UnsafeStore('The network transaction state is too large.')
        temporary = os.path.join(self.state_dir, '.pending-' + secrets.token_hex(16))
        fd = self._open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600, private=True)
        try:
            with os.fdopen(fd, 'wb', closefd=False) as target:
                target.write(data)
                target.flush()
                os.fsync(fd)
            os.replace(temporary, self.state_path)
            self._sync_dir(self.state_dir)
        finally:
            os.close(fd)
            try:
                os.unlink(temporary)
            except FileNotFoundError:
                pass

    def pending(self):
        return os.path.lexists(self.marker_path)

    def set_pending(self):
        try:
            fd = self._open(self.marker_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o644)
        except FileExistsError:
            fd = self._open(self.marker_path, os.O_RDONLY)
        os.close(fd)
        self._sync_dir(self.runtime_dir)

    def _clear(self, path, parent):
        try:
            os.unlink(path)
        except FileNotFoundError:
            return
        self._sync_dir(parent)

    def clear_state(self): self._clear(self.state_path, self.state_dir)
    def clear_pending(self): self._clear(self.marker_path, self.runtime_dir)
