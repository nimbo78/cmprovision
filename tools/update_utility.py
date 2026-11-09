"""Independent root-owned Utility OS releases; bounded extraction, exact boot inventory."""
import base64
import gzip
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import sys
import tarfile
import tempfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from update_manifest import UOS_VERSION_RE, UpdateError, canonical_bytes, verify_manifest, compatibility_error

ROOT = Path('/var/lib/cmprovision-utility-os')
KEY = Path('/etc/cmprovision-updates/publisher.pub')
REQUIRED = frozenset(('kernel8.img', 'scriptexecute.img', 'config.txt', 'cmdline.txt', 'start4.elf', 'fixup4.dat',
    'bcm2711-rpi-cm4.dtb', 'bcm2711-rpi-4-b.dtb', 'bcm2711-rpi-400.dtb', 'overlays/dwc2.dtbo',
    'overlays/spi-gpio40-45.dtbo', 'usb/bootfiles.bin', 'usb/boot.img', 'usb/config.txt'))
MAX_TOTAL, MAX_FILE, MAX_FILES = 1024**3, 256*1024**2, 4096


def safe_name(name):
    return (type(name) is str and 0 < len(name) <= 240 and '\\' not in name and ':' not in name
        and not name.startswith('/') and all(part not in ('', '.', '..') for part in name.split('/'))
        and not re.search(r'[\x00-\x1f\x7f]', name))


def protected(path, owner=0, directory=False):
    info = path.lstat()
    if info.st_uid != owner or info.st_mode & 0o022 or not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode)):
        raise UpdateError('Utility OS storage ownership or permissions are unsafe.')
    return info


def verify_tree(base, value=None, owner=0):
    protected(base, owner, directory=True)
    manifest = base/'MANIFEST'
    if protected(manifest, owner).st_size > 1024**2: raise UpdateError('Utility OS inventory is too large.')
    values, files = {}, {}
    for line in manifest.read_text().splitlines():
        if not line or line.startswith('#'): continue
        if '=' not in line: raise UpdateError('Utility OS inventory is malformed.')
        key, content = line.split('=', 1)
        if key == 'file':
            match = re.fullmatch(r'([0-9a-f]{64}) ([0-9]{1,10}) (.+)', content)
            if not match or not safe_name(match[3]) or match[3] in files or match[3] == 'MANIFEST':
                raise UpdateError('Utility OS inventory has an unsafe or duplicate file.')
            files[match[3]] = (match[1], int(match[2]))
        else:
            if key in values: raise UpdateError('Duplicate Utility OS inventory metadata.')
            values[key] = content
    if not REQUIRED <= set(files) or len(files) > MAX_FILES: raise UpdateError('Required Ethernet/USB boot files are missing.')
    if value:
        if (values.get('version') != value['version'] or values.get('bits') != '64'
                or not re.search(r'(?:^|[ (])'+re.escape(value['utility']['kernel'])+r'(?:$|[ )])', values.get('kernel', ''))):
            raise UpdateError('Utility OS inventory does not match the signed release.')
    actual, total = set(), 0
    for current, directories, names in os.walk(base, followlinks=False):
        for name in directories: protected(Path(current)/name, owner, directory=True)
        for name in names:
            path = Path(current)/name
            info = protected(path, owner)
            relative = path.relative_to(base).as_posix()
            if relative == 'MANIFEST': continue
            total += info.st_size
            expected = files.get(relative)
            if not expected or info.st_size != expected[1] or info.st_size > MAX_FILE or total > MAX_TOTAL:
                raise UpdateError('Utility OS file inventory or size verification failed.')
            with path.open('rb') as stream:
                if hashlib.file_digest(stream, 'sha256').hexdigest() != expected[0]:
                    raise UpdateError('Utility OS file checksum verification failed.')
            actual.add(relative)
    if actual != set(files): raise UpdateError('Utility OS inventory is incomplete.')
    return values


class Limited(io.RawIOBase):
    def __init__(self, source): self.source, self.total = source, 0
    def read(self, size=-1):
        data = self.source.read(min(size if size >= 0 else MAX_TOTAL+1, MAX_TOTAL+1-self.total))
        self.total += len(data)
        if self.total > MAX_TOTAL: raise UpdateError('Expanded Utility OS archive exceeds its size limit.')
        return data


class ManagedUtility:
    def __init__(self, store=None, root=ROOT, key=KEY):
        self.store, self.root, self.key = store, Path(root), Path(key)
        self.owner = store.owner if store else 0

    def _value(self, envelope):
        raw = base64.b64decode(envelope['raw'], validate=True)
        signature = base64.b64decode(envelope['signature'], validate=True)
        publisher = envelope['manifest']['repository']
        value = verify_manifest(raw, signature, self.key, publisher)
        if value['component'] != 'utility-os' or value != envelope['manifest'] or hashlib.sha256(raw).hexdigest() != envelope['id']:
            raise UpdateError('Utility OS receipt does not match its signed release.')
        return value

    def validate(self, selector, context=None):
        if type(selector) is not str or not re.fullmatch('managed:'+UOS_VERSION_RE, selector):
            raise UpdateError('Invalid managed Utility OS selection.')
        version = selector.split(':', 1)[1]
        directory = self.root/'releases'/version
        for path in (self.root, self.root/'releases', directory): protected(path, self.owner, directory=True)
        receipt = directory/'receipt.json'
        if protected(receipt, self.owner).st_size > 128*1024: raise UpdateError('Utility OS receipt is too large.')
        envelope = json.loads(receipt.read_bytes())
        value = self._value(envelope)
        if context:
            error = compatibility_error(value, context)
            if error: raise UpdateError(error)
        if version != value['version']: raise UpdateError('Utility OS directory and version do not match.')
        return verify_tree(directory/'boot', value, self.owner)

    def prepare(self, envelope, archive):
        value = self._value(envelope)
        asset = value['assets'][0]
        with archive.open('rb') as stream: digest = hashlib.file_digest(stream, 'sha256').hexdigest()
        if archive.stat().st_size != asset['size'] or digest != asset['sha256']:
            raise UpdateError('Utility OS archive does not match its signed checksum.')
        releases = self.root/'releases'
        for path in (self.root, releases): self.store._directory(path, 0o755)
        target = releases/value['version']
        selector = 'managed:'+value['version']
        if target.exists() or target.is_symlink():
            self.validate(selector)
            if json.loads((target/'receipt.json').read_bytes())['id'] != envelope['id']:
                raise UpdateError('An existing immutable Utility OS version has different release contents.')
            return selector
        stage = Path(tempfile.mkdtemp(prefix='.prepare-', dir=releases))
        try:
            seen, total = set(), 0
            with gzip.open(archive, 'rb') as compressed:
                bounded = Limited(compressed)
                with tarfile.open(fileobj=bounded, mode='r|') as source:
                    for member in source:
                        name = member.name.rstrip('/') if member.isdir() else member.name
                        if not safe_name(name) or name.split('/')[0] != 'boot' or name in seen or len(seen) >= MAX_FILES+256:
                            raise UpdateError('Utility OS archive has unsafe paths or duplicate/excessive entries.')
                        seen.add(name)
                        path = stage/name
                        if member.isdir():
                            path.mkdir(mode=0o755, parents=True, exist_ok=True)
                            path.chmod(0o755)
                            continue
                        if not member.isreg() or member.size > MAX_FILE or member.size < 0:
                            raise UpdateError('Utility OS archive contains links, special files or oversized files.')
                        total += member.size
                        if total > MAX_TOTAL or shutil.disk_usage(stage).free < member.size+64*1024**2:
                            raise UpdateError('Utility OS archive exceeds available space or size limits.')
                        path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
                        with source.extractfile(member) as stream, path.open('xb') as output:
                            shutil.copyfileobj(stream, output, length=1024**2)
                            output.flush()
                            os.fchmod(output.fileno(), 0o644)
                            os.fsync(output.fileno())
                while bounded.read(1024**2): pass # Verify gzip footer and bound trailing expanded data too.
            verify_tree(stage/'boot', value, self.owner)
            self.store._atomic(stage/'receipt.json', envelope, 0o644)
            for current, directories, unused in os.walk(stage):
                for name in directories: (Path(current)/name).chmod(0o755)
                fd = os.open(current, os.O_RDONLY | os.O_DIRECTORY)
                try: os.fsync(fd)
                finally: os.close(fd)
            stage.chmod(0o755)
            os.rename(stage, target)
            fd = os.open(releases, os.O_RDONLY | os.O_DIRECTORY)
            try: os.fsync(fd)
            finally: os.close(fd)
            return selector
        except (OSError, ValueError, tarfile.TarError, EOFError) as error:
            if isinstance(error, UpdateError): raise
            raise UpdateError('Utility OS archive could not be safely prepared.') from error
        finally:
            if stage.exists(): shutil.rmtree(stage)


if __name__ == '__main__':
    try:
        if len(sys.argv) != 3 or sys.argv[1] != '--validate': raise UpdateError('Expected a managed Utility OS selection.')
        import subprocess
        def command(*args): return subprocess.check_output(args, text=True).strip()
        context = {'debian': Path('/etc/debian_version').read_text().split('.')[0].strip(),
            'architecture': command('/usr/bin/dpkg', '--print-architecture'),
            'php': command('/usr/bin/php', '-r', 'echo PHP_VERSION;'),
            'cmprovision': command('/usr/bin/dpkg-query', '-W', '-f=${Version}', 'cmprovision4'), 'updater_protocol': 1}
        print(json.dumps(ManagedUtility().validate(sys.argv[2], context)))
    except Exception:
        print('{"error":"Managed Utility OS verification failed"}')
        raise SystemExit(1)
