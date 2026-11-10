"""Streaming encrypted archives and authenticated, read-only inspection before restore."""
from contextlib import contextmanager
from dataclasses import dataclass
import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tarfile
import tempfile

from update_manifest import UpdateError, canonical_bytes
from backup_policy import safe_open, valid_member

MAX_MEMBERS = 100000
MAX_MANIFEST = 16*1024**2
MAX_TOTAL = 2**44
RESERVE = 256*1024**2
CODEC = Path(__file__).with_name('backup-crypto.php')


@dataclass
class Entry:
    name: str
    root: Path | None = None
    source: str | None = None
    data: bytes | None = None
    group: str = 'application'
    mode: int = 0o600

    @contextmanager
    def opened(self):
        if self.data is not None:
            if type(self.data) is not bytes or len(self.data) > MAX_MANIFEST: raise UpdateError('Backup metadata is too large.')
            yield io.BytesIO(self.data)
        else:
            if self.root is None or self.source is None: raise UpdateError('Backup source is missing.')
            with safe_open(self.root, self.source) as stream: yield stream

    def size(self):
        with self.opened() as stream:
            if self.data is not None: return len(self.data)
            return os.fstat(stream.fileno()).st_size


def _codec(mode, password, source, destination):
    secret = tempfile.TemporaryFile()
    try:
        secret.write(canonical_bytes({'password': password})); secret.seek(0)
        process = subprocess.Popen(['/usr/bin/php', str(CODEC), mode, str(secret.fileno())],
            stdin=source, stdout=destination, stderr=subprocess.DEVNULL, pass_fds=(secret.fileno(),))
    finally: secret.close()
    return process


class _Reader:
    def __init__(self, stream, progress): self.stream, self.progress, self.hash = stream, progress, hashlib.sha256()
    def read(self, size):
        value = self.stream.read(size)
        self.hash.update(value)
        self.progress(len(value))
        return value


def create(entries, destination, password, metadata, progress=None, canceled=None, reserve=True):
    """Destination is a new private path or a trusted binary pipe. No full plaintext staging."""
    entries = list(entries)
    names = [entry.name for entry in entries]
    if not 0 < len(entries) <= MAX_MEMBERS or len(set(names)) != len(names) or not all(valid_member(name) for name in names):
        raise UpdateError('Backup inventory contains unsafe or duplicate members.')
    total = sum(entry.size() for entry in entries)
    if total > MAX_TOTAL: raise UpdateError('Backup contents exceed the supported size.')
    path = Path(destination) if isinstance(destination, (str, Path)) else None
    if path and reserve and shutil.disk_usage(path.parent).free < int(total*1.02)+len(entries)*2048+MAX_MANIFEST+RESERVE:
        raise UpdateError('Not enough space for the encrypted backup and the 256 MiB reserve.')
    output = None
    process = None
    complete = False
    done = checked = 0
    members = []
    def advanced(count):
        nonlocal done, checked
        done += count
        if canceled and canceled(): raise UpdateError('Backup canceled.')
        if path and reserve and done-checked >= 1024**2:
            checked = done
            if shutil.disk_usage(path.parent).free < RESERVE: raise UpdateError('Backup stopped to preserve the 256 MiB free-space reserve.')
        if progress: progress(done, total)
    try:
        if path:
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
            output = os.fdopen(fd, 'wb')
        else: output = destination
        process = _codec('encrypt', password, subprocess.PIPE, output)
        try:
            with tarfile.open(fileobj=process.stdin, mode='w|gz', format=tarfile.PAX_FORMAT) as archive:
                for entry in entries:
                    advanced(0)
                    with entry.opened() as source:
                        before = os.fstat(source.fileno()) if entry.data is None else None
                        size = before.st_size if before else len(entry.data)
                        info = tarfile.TarInfo(entry.name)
                        info.size, info.mode, info.mtime = size, entry.mode & 0o777, 0
                        reader = _Reader(source, advanced)
                        archive.addfile(info, reader)
                        if before:
                            after = os.fstat(source.fileno())
                            if (before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
                                raise UpdateError('A backup source changed while it was being archived. Retry when the station is idle.')
                        members.append({'name': entry.name, 'size': size, 'sha256': reader.hash.hexdigest(), 'mode': info.mode, 'group': entry.group})
                manifest = dict(metadata, format='cmprovision-full-backup', schema=2, members=members)
                raw = canonical_bytes(manifest)
                if len(raw) > MAX_MANIFEST: raise UpdateError('Backup manifest exceeds its size limit.')
                info = tarfile.TarInfo('manifest.json'); info.size = len(raw); info.mode = 0o600
                archive.addfile(info, io.BytesIO(raw))
        finally:
            process.stdin.close()
        if process.wait(timeout=60): raise UpdateError('Full backup encryption did not complete.')
        if path:
            output.flush(); os.fsync(output.fileno())
        complete = True
        return manifest
    except (OSError, tarfile.TarError, subprocess.SubprocessError) as error:
        raise UpdateError('The full backup stream could not be completed.') from error
    finally:
        if process and process.poll() is None:
            process.kill(); process.wait(timeout=10)
        if path and output: output.close()
        if path and output and not complete: path.unlink(missing_ok=True)


class VerifiedArchive:
    def __init__(self, path, manifest): self.path, self.manifest = path, manifest
    def read(self, name, limit=MAX_MANIFEST):
        item = next((item for item in self.manifest['members'] if item['name'] == name), None)
        if item is None or item['size'] > limit: raise UpdateError('The requested backup metadata is absent or too large.')
        with tarfile.open(self.path, mode='r:gz') as archive:
            return archive.extractfile(name).read(limit+1)
    def copy(self, name, output):
        with tarfile.open(self.path, mode='r:gz') as archive:
            stream = archive.extractfile(name)
            if stream is None: raise UpdateError('The requested backup member is missing.')
            shutil.copyfileobj(stream, output, length=1024**2)


@contextmanager
def inspect_archive(source, password, work='/var/lib/cmprovision-backups/private'):
    """Authenticate the complete stream before decompressing or allowing any destination mutation."""
    directory = Path(tempfile.mkdtemp(prefix='inspect-', dir=work))
    directory.chmod(0o700)
    path = directory/'archive.tar.gz'
    process = None
    try:
        with safe_open(Path(source).parent, Path(source).name) as encrypted, path.open('xb') as output:
            path.chmod(0o600)
            process = _codec('decrypt', password, encrypted, output)
            if process.wait(timeout=7200): raise UpdateError('Backup authentication failed: wrong password, damaged file or unsupported format.')
            output.flush(); os.fsync(output.fileno())
        observed, manifest, total = {}, None, 0
        with tarfile.open(path, mode='r|gz') as archive:
            for item in archive:
                if manifest is not None or not item.isfile() or item.name in observed or item.size < 0:
                    raise UpdateError('Backup contains duplicate, non-regular or misplaced members.')
                if item.name == 'manifest.json':
                    if item.size > MAX_MANIFEST: raise UpdateError('Backup manifest exceeds its size limit.')
                    manifest = json.loads(archive.extractfile(item).read(MAX_MANIFEST+1))
                    continue
                if not valid_member(item.name) or len(observed) >= MAX_MEMBERS or item.size > MAX_TOTAL:
                    raise UpdateError('Backup contains unsafe or oversized members.')
                total += item.size
                if total > MAX_TOTAL: raise UpdateError('Backup exceeds its supported size.')
                digest = hashlib.sha256()
                stream = archive.extractfile(item)
                read = 0
                while chunk := stream.read(1024**2):
                    read += len(chunk); digest.update(chunk)
                if read != item.size: raise UpdateError('Backup member was truncated.')
                observed[item.name] = {'size': item.size, 'sha256': digest.hexdigest(), 'mode': item.mode & 0o777}
        if (type(manifest) is not dict or manifest.get('format') != 'cmprovision-full-backup' or manifest.get('schema') != 2
                or type(manifest.get('members')) is not list or len(manifest['members']) != len(observed)):
            raise UpdateError('Backup manifest is missing or invalid.')
        named = set()
        for member in manifest['members']:
            if (type(member) is not dict or member.get('name') in named or member.get('name') not in observed
                    or any(member.get(key) != observed[member['name']][key] for key in ('size', 'sha256', 'mode'))):
                raise UpdateError('Backup contents do not match the manifest.')
            named.add(member['name'])
        yield VerifiedArchive(path, manifest)
    except (OSError, ValueError, TypeError, tarfile.TarError, subprocess.SubprocessError) as error:
        if isinstance(error, UpdateError): raise
        raise UpdateError('The full backup is unreadable or invalid.') from error
    finally:
        if process and process.poll() is None: process.kill(); process.wait(timeout=10)
        shutil.rmtree(directory)
