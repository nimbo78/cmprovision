"""Read-only recovery space estimate. Only measure() runs under the admission lock."""
from collections import Counter
from contextlib import ExitStack
from dataclasses import dataclass
import os
from pathlib import Path
import re
import stat
import subprocess

from update_manifest import UpdateError
from update_store import ID_RE, MAX_STATE, TERMINAL, matches

ROOT = Path('/')
RECOVERY = '/var/lib/cmprovision-updates/private/recovery'
JOURNAL = '/var/lib/cmprovision-updates/private'
RESERVE = 128*1024**2
DIR = os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC

# Inputs to the system archive in cmprovision-backup. Missing optional files match
# nothing. Symlinks and nested mounts have the same meaning here as for ordinary tar.
SYSTEM_CONFIG = (
    'etc/nginx/sites-available/cmprovision', 'etc/php/*/fpm/php.ini', 'etc/sudoers.d/010_cmprovision',
    'etc/dhcpcd.conf', 'etc/NetworkManager/system-connections', 'var/lib/cmprovision-network/vlans',
    'etc/systemd/system/cmprovision-dnsmasq.service', 'etc/systemd/system/cmprovision-rpiboot.service',
    'etc/systemd/system/multi-user.target.wants/cmprovision-dnsmasq.service',
    'etc/systemd/system/multi-user.target.wants/cmprovision-rpiboot.service',
    'lib/systemd/system/cmprovision-*.service', 'lib/systemd/system/cmprovision-*.timer',
    'etc/default/cmprovision-uplink', 'etc/default/cmprovision-wallboard.json', 'etc/default/cmprovision-fpms.json',
    'etc/localtime', 'etc/timezone', 'etc/systemd/timesyncd.conf.d/90-cmprovision.conf',
    'etc/default/cmprovision-stand-interface',
)


@dataclass(frozen=True)
class _Volume:
    device: int
    destinations: tuple
    required: int
    reclaimable: int


def _round(size, block):
    return (size+block-1)//block*block


def _packed(size):
    # GNU tar pads to records. Allow an incompressible gzip/xz stream and its framing.
    raw = _round(size+1024, 10240)
    return raw + raw//100 + 4096


def _tar_entry(name, info, hardlinks=None):
    length = len(os.fsencode(name))
    size = 512 + (512+_round(length+1, 512) if length >= 100 else 0)
    if stat.S_ISREG(info.st_mode):
        if hardlinks is not None and info.st_nlink > 1:
            key = (info.st_dev, info.st_ino)
            if key in hardlinks:
                target = hardlinks[key]
                return size + (512+_round(target+1, 512) if target >= 100 else 0)
            hardlinks[key] = length
        return size+_round(info.st_size, 512)
    if stat.S_ISLNK(info.st_mode) and info.st_size >= 100:
        size += 512+_round(info.st_size+1, 512)
    return size


def _raise(error):
    raise error


def _archive_bytes(path, *, app=False):
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode):
        if app: raise UpdateError('The installed application directory is unavailable.')
        return _tar_entry(path, info)
    total = 0
    hardlinks = {}
    for base, dirs, files, fd in os.fwalk(path, onerror=_raise, follow_symlinks=False):
        base = Path(base)
        total += _tar_entry(base, os.fstat(fd))
        # Filtering the exact tar excludes also avoids traversing excluded directories.
        framework = app and base == path/'storage/framework'
        database = app and base == path/'database'
        if framework and 'configuration-transfer' in dirs:
            dirs.remove('configuration-transfer')
        if database:
            dirs[:] = [name for name in dirs if not name.startswith('database.sqlite')]
        for name in (*dirs, *files):
            if (database and name.startswith('database.sqlite')) or (framework and name == 'configuration-transfer'):
                continue
            info = os.stat(name, dir_fd=fd, follow_symlinks=False)
            if not stat.S_ISDIR(info.st_mode):
                total += _tar_entry(base/name, info, hardlinks)
    return total


def _database_bytes(app):
    # Only stat the live www-data database. Its WAL can contain newly allocated pages
    # which have not reached the main file. The temporary copy may also journal redaction.
    fd = os.open(app/'database', DIR | os.O_NOFOLLOW)
    try:
        total = 0
        for name in ('database.sqlite', 'database.sqlite-wal'):
            try: info = os.stat(name, dir_fd=fd, follow_symlinks=False)
            except FileNotFoundError:
                if name.endswith('-wal'): continue
                raise
            if not stat.S_ISREG(info.st_mode):
                raise UpdateError('Cannot estimate a missing or linked application database.')
            total += info.st_size
        return total
    finally:
        os.close(fd)


def _query(name, option):
    return subprocess.run(['/usr/bin/dpkg-query', '--root='+str(ROOT), option, name],
                          capture_output=True, text=True, errors='surrogateescape', timeout=60,
                          env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL': 'C'})


def _package(name, block):
    status = _query(name, '--status')
    if status.returncode:
        absent = "dpkg-query: package 'fpms-lite' is not installed and no information is available"
        if name == 'fpms-lite' and status.returncode == 1 and status.stderr.splitlines()[:1] == [absent]:
            return None
        raise UpdateError('Cannot read the installed package status for '+name+'.')
    state = re.search(r'^Status: \S+ (\S+) (\S+)$', status.stdout, re.MULTILINE)
    if name == 'fpms-lite' and state and state[1] == 'ok' and state[2] in ('not-installed', 'config-files'):
        return None
    if not state or state.groups() != ('ok', 'installed'):
        raise UpdateError('Cannot estimate recovery space until '+name+' is fully installed.')
    inventory = _query(name, '--listfiles')
    controls = _query(name, '--control-path')
    if inventory.returncode or controls.returncode or not inventory.stdout.strip():
        raise UpdateError('Cannot read the installed file inventory for '+name+'.')
    # dpkg-repack copies individual registered leaves, not directory contents. Use
    # their current lengths: Installed-Size is optional and can predate local changes.
    files = []
    for line in inventory.stdout.splitlines():
        diversion = re.fullmatch(r'(?:locally diverted|diverted by \S+) to: (.+)', line)
        if diversion and files:
            files[-1] = diversion[1]
        elif not line.startswith('package diverts others to: '):
            files.append(line)
    registered = []
    for line in files:
        path = Path(line)
        if not path.is_absolute() or '..' in path.parts:
            raise UpdateError('Cannot size the installed inventory for '+name+'.')
        registered.append(ROOT/str(path).lstrip('/'))
    # --control-path returns paths in the selected dpkg root (maintainer scripts,
    # md5sums, ...). dpkg-repack also generates a control file from this status stanza.
    registered.extend(Path(line) for line in controls.stdout.splitlines())
    metadata = len(os.fsencode(status.stdout))+4096
    staged, archive = _round(metadata, block), _round(metadata, 512)+512
    for path in registered:
        try: info = path.lstat()
        except FileNotFoundError:
            continue  # dpkg-repack warns and skips locally absent registered files.
        staged += max(block, _round(info.st_size, block))
        archive += _tar_entry(path, info)
    return staged, _packed(archive)


def _disk(label, path):
    try:
        try: fd = os.open(path, DIR | (os.O_NOFOLLOW if label in (RECOVERY, JOURNAL) else 0))
        except FileNotFoundError:
            # A first preview must not create recovery/. Its eventual directory
            # inherits the parent's filesystem unless another mount appears first.
            if label != RECOVERY: raise
            fd = os.open(path.parent, DIR | os.O_NOFOLLOW)
        try:
            info, usage = os.fstat(fd), os.fstatvfs(fd)
        finally:
            os.close(fd)
    except OSError as error:
        raise UpdateError('Cannot measure available space for '+label+'.') from error
    if usage.f_flag & os.ST_RDONLY:
        raise UpdateError('The filesystem for '+label+' is read-only.')
    block = usage.f_frsize or usage.f_bsize
    return info.st_dev, block, max(0, usage.f_bavail)*block


def _credits(store, selected):
    # Take only retention references under control.lock. Record lookup and observation
    # do not retire a recovery or clean up anything. Unindexed trees give no credit.
    with store.lock('control'):
        state = store._load()
        protected = {selected}
        if state['critical']: protected.add(state['critical']['id'])
        current = state['operations'].get(state['current'])
        if current and (current['state'] not in TERMINAL or current['id'] in protected):
            protected.update((current['id'], current['request'].get('operation')))
        eligible = [(key, row['backup']) for key, row in state['recoveries'].items()
                    if key not in protected and matches(ID_RE, key) and isinstance(row.get('backup'), dict)]
    credits = Counter()
    if not eligible: return credits

    from update_retention import mount_points
    mounts = mount_points()
    recovery = store.private/'recovery'
    for identity, backup in eligible:
        directory = recovery/identity
        if backup.get('identity') != identity or not isinstance(backup.get('directory'), str): continue
        snapshot = Path(backup['directory'])
        if (snapshot.parent != directory
                or not re.fullmatch(r'[0-9]{8}-[0-9]{6}-[a-zA-Z0-9][a-zA-Z0-9.+:~_-]*', snapshot.name)
                or any(mount == directory or directory in mount.parents for mount in mounts)):
            continue
        try:
            with ExitStack() as stack:
                parent = None
                for part in (recovery, identity, snapshot.name):
                    parent = os.open(part, DIR | os.O_NOFOLLOW, dir_fd=parent)
                    stack.callback(os.close, parent)
                    info = os.fstat(parent)
                    if (info.st_uid != store.owner or info.st_mode & 0o022
                            or part == identity and info.st_mtime > store.clock()):
                        break
                else:
                    # Ordinary user backups and other unrecorded files never contribute.
                    # The three data members have fixed names in every supported snapshot.
                    packages = [name for name in backup.get('hashes', {})
                                if re.fullmatch(r'(?:cmprovision4|fpms-lite)_[a-zA-Z0-9.+:~_-]+\.deb', name)]
                    for name in ('app.tar.gz', 'database.sqlite', 'system-config.tar.gz', *packages):
                        try: info = os.stat(name, dir_fd=parent, follow_symlinks=False)
                        except FileNotFoundError: continue
                        if (stat.S_ISREG(info.st_mode) and info.st_uid == store.owner
                                and not info.st_mode & 0o022 and info.st_nlink == 1):
                            credits[info.st_dev] += info.st_blocks*512
        except OSError:
            continue  # Missing, changed or unsafe optional evidence gives no extra credit.
    return credits


def estimate(store, *, selected=None):
    """Build a read-only plan. A selected rollback source already occupies space."""
    if selected is not None and not matches(ID_RE, selected):
        raise UpdateError('Invalid recovery operation identifier.')
    destinations = ((RECOVERY, store.private/'recovery'), ('/var/tmp', ROOT/'var/tmp'), ('/tmp', ROOT/'tmp'),
                    (JOURNAL, store.private))
    disks = [_disk(*destination) for destination in destinations]
    devices = [disk[0] for disk in disks]
    peak = Counter()

    def phase(recovery, database=0, repack=0):
        concurrent = Counter()
        for device, amount in zip(devices[:3], (recovery, database, repack)):
            concurrent[device] += amount
        for device, amount in concurrent.items():
            peak[device] = max(peak[device], amount)

    try:
        app = ROOT/'var/lib/cmprovision'
        database = _database_bytes(app)
        # SQLite may journal every changed page while removing temporary setup codes.
        phase(0, database*2+database//16)
        phase(database, database)
        saved = database + _packed(_archive_bytes(app, app=True))
        saved += _packed(sum(_archive_bytes(path) for pattern in SYSTEM_CONFIG for path in ROOT.glob(pattern)))
        for name in ('cmprovision4', 'fpms-lite'):
            package = _package(name, disks[0][1])
            if package is None: continue
            staged, packed = package
            # Build tree + output in cwd. dpkg-deb holds its compressed member in
            # /tmp until it writes the .deb. A finished .deb stays for the next repack.
            phase(saved+staged+packed, repack=packed)
            saved += packed
        credits = _credits(store, selected)
    except (OSError, subprocess.TimeoutExpired, RecursionError) as error:
        raise UpdateError('Cannot measure the files needed for the application recovery snapshot.') from error
    for device in set(devices[:3]):
        peak[device] += RESERVE
    # On a separate journal filesystem allow two maximum-size atomic state writes
    # plus emergency recovery headroom. A shared volume already carries the larger reserve.
    peak[devices[3]] = max(peak[devices[3]], 2*MAX_STATE+4*1024**2)
    return tuple(_Volume(device, tuple(path for path, dev in zip(destinations, devices) if dev == device),
                         peak[device], credits[device]) for device in dict.fromkeys(devices))


def measure(plan, *, reclaimable=True):
    """Fresh capacity from fixed destinations. The final gate never adds predicted frees."""
    rows = []
    for volume in plan:
        remaining = []
        for label, path in volume.destinations:
            device, _, free = _disk(label, path)
            if device != volume.device:
                raise UpdateError('The filesystem for '+label+' changed. Check available space again.')
            remaining.append(free)
        free = min(remaining)
        credit = volume.reclaimable if reclaimable else 0
        rows.append({'location': volume.destinations[0][0], 'required': volume.required, 'free': free,
                     'reclaimable': credit, 'shortfall': max(0, volume.required-free-credit)})
    return {'volumes': rows, 'sufficient': all(row['shortfall'] == 0 for row in rows)}
