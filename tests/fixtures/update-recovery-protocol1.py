"""Stable stdlib-only boot dispatcher, retained outside dpkg's replaceable files."""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile

NAMES = ('station-updates.py', 'update_manifest.py', 'update_store.py', 'update_transport.py',
         'update_worker.py', 'update_system.py', 'update_utility.py', 'update_broker.py',
         'update_recovery.py', 'update_retention.py', 'publisher.json', 'publisher.pub')
TRUST = ('publisher.json', 'publisher.pub')


def protected(path, owner, directory=False):
    info = path.lstat()
    if (info.st_uid != owner or info.st_mode & 0o022 or
            not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))):
        raise RuntimeError('Recovery storage is not protected.')
    return info


def write(path, raw, mode):
    fd, name = tempfile.mkstemp(prefix='.recovery-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            os.fchmod(stream.fileno(), mode)
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
        fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try: os.fsync(fd)
        finally: os.close(fd)
    finally:
        if os.path.exists(name): os.unlink(name)


def install_dispatcher(store):
    target = store.root/'recover.py'
    if target.exists() or target.is_symlink():
        protected(target, store.owner)
        return # Protocol1 dispatcher is deliberately not replaced during self-update.
    source = Path(__file__)
    protected(source, store.owner)
    write(target, source.read_bytes(), 0o600)


def retain_runner(store, identity, installed=Path('/usr/lib/cmprovision'), names=NAMES,
                  trust=Path('/etc/cmprovision-updates')):
    if not re.fullmatch('[0-9a-f]{32}', identity): raise RuntimeError('Invalid recovery operation.')
    protected(installed, store.owner, directory=True)
    protected(trust, store.owner, directory=True)
    target = store.private/'runners'/identity
    store._directory(target, 0o700)
    hashes = {}
    for name in names:
        if name not in NAMES: raise RuntimeError('Unexpected recovery module.')
        source = (trust if name in TRUST else installed)/name
        if protected(source, store.owner).st_size > 1024**2: raise RuntimeError('Recovery module exceeds its size limit.')
        raw = source.read_bytes()
        digest = hashlib.sha256(raw).hexdigest()
        destination = target/name
        if destination.exists() or destination.is_symlink():
            protected(destination, store.owner)
            if hashlib.sha256(destination.read_bytes()).hexdigest() != digest:
                raise RuntimeError('Existing retained worker differs.')
        else: write(destination, raw, 0o600)
        hashes[name] = digest
    return {'directory': str(target), 'hashes': hashes}


def restore_trust(store, identity, target=Path('/etc/cmprovision-updates')):
    operation = store.operation(identity)
    runner = operation.get('runner', {})
    directory = store.private/'runners'/identity
    if runner.get('directory') != str(directory): raise RuntimeError('The retained issuer path does not match.')
    for path in (directory.parent, directory, target): protected(path, store.owner, directory=True)
    for name in TRUST:
        source = directory/name
        if protected(source, store.owner).st_size > 1024**2: raise RuntimeError('Retained issuer configuration is oversized.')
        raw = source.read_bytes()
        if hashlib.sha256(raw).hexdigest() != runner.get('hashes', {}).get(name):
            raise RuntimeError('Retained issuer configuration verification failed.')
        destination = target/name
        if destination.exists() or destination.is_symlink(): protected(destination, store.owner)
        write(destination, raw, 0o644)


def dispatch(root=Path('/var/lib/cmprovision-updates'), runtime=Path('/run/cmprovision-updates'), run=subprocess.run, owner=0):
    root, runtime = Path(root), Path(runtime)
    for path in (root, root/'private'): protected(path, owner, directory=True)
    state_path = root/'private/state.json'
    if protected(state_path, owner).st_size > 4*1024**2: raise RuntimeError('Recovery state is too large.')
    state = json.loads(state_path.read_bytes())
    if state.get('schema') != 1: raise RuntimeError('Unsupported recovery state.')
    pending = state.get('critical')
    if not pending:
        return # Read-only status polling reconciles interrupted noncritical operations.
    identity = pending.get('id')
    if not isinstance(identity, str) or not re.fullmatch('[0-9a-f]{32}', identity): raise RuntimeError('Invalid recovery identity.')
    runtime.mkdir(mode=0o755, exist_ok=True)
    protected(runtime, owner, directory=True)
    marker = runtime/'pending.json'
    if marker.exists() or marker.is_symlink(): protected(marker, owner)
    write(marker, (json.dumps(pending)+'\n').encode(), 0o644) # Gate precedes runner validation: failure remains closed.
    operation = state['operations'].get(identity)
    if not operation: raise RuntimeError('The interrupted operation is missing.')
    if operation['state'] == 'recovery-required' and not operation.get('committed'): return
    runner = operation.get('runner', {})
    directory = root/'private/runners'/identity
    if runner.get('directory') != str(directory): raise RuntimeError('The retained recovery path does not match.')
    for path in (directory.parent, directory): protected(path, owner, directory=True)
    hashes = runner.get('hashes')
    if not isinstance(hashes, dict) or set(hashes) != set(NAMES):
        raise RuntimeError('The retained recovery inventory is invalid.')
    for name, digest in hashes.items():
        path = directory/name
        if protected(path, owner).st_size > 1024**2 or hashlib.sha256(path.read_bytes()).hexdigest() != digest:
            raise RuntimeError('Retained recovery module verification failed.')
    result = run(['/usr/bin/systemd-run', '--quiet', '--collect', '--unit=cmprovision-update-recovery', '--',
        '/usr/bin/python3', '-B', '-I', str(directory/'station-updates.py'), '--recover'],
        capture_output=True, timeout=8, check=False)
    if result.returncode: raise RuntimeError('Recovery could not be scheduled; maintenance remains enabled.')


if __name__ == '__main__':
    try:
        if os.geteuid() != 0 or sys.argv[1:]: raise RuntimeError('Expected the fixed root recovery dispatcher.')
        dispatch()
    except Exception:
        print('Update recovery needs SSH/local-console attention; maintenance remains enabled.', file=sys.stderr)
        raise SystemExit(1)
