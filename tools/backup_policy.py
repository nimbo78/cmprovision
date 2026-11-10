"""Version-two fixed member policy. System recovery never means arbitrary /etc extraction."""
from contextlib import contextmanager
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat

from update_manifest import UpdateError, canonical_bytes

APP = 'var/lib/cmprovision'
FIXED = ('etc/hostname', 'etc/hosts', 'etc/default/locale', 'etc/default/keyboard', 'etc/locale.gen',
    'etc/timezone', 'etc/localtime', 'etc/systemd/timesyncd.conf', 'etc/dhcpcd.conf',
    'etc/NetworkManager/NetworkManager.conf', 'etc/ssh/sshd_config', 'etc/sudoers',
    'etc/nginx/sites-available/cmprovision', 'etc/default/cmprovision-stand-interface',
    'etc/default/cmprovision-uplink', 'etc/default/cmprovision-wallboard.json', 'etc/default/cmprovision-fpms.json',
    'etc/cmprovision-updates/publisher.json', 'etc/cmprovision-updates/publisher.pub')
TREES = ('etc/NetworkManager/system-connections', 'etc/NetworkManager/conf.d', 'etc/NetworkManager/certs',
    'etc/ssh/sshd_config.d', 'etc/sudoers.d', 'etc/systemd/timesyncd.conf.d', 'var/lib/cmprovision-network/vlans')
GENERATED = {'system/accounts.json', 'system/service-state.json', 'system/backup-config.json',
    'system/time.json', 'system/software.json', 'system/payloads.json', 'system/os.json'}
EXCLUDED_STORAGE = {'upload-tmp', 'configuration-transfer', 'backup', 'backups'}


def relative(value):
    if (type(value) is not str or not value or len(value) > 4096 or value.startswith('/')
            or any(char in value for char in ('\\', '\x00', '\r', '\n'))
            or any(part in ('', '.', '..') or len(part) > 255 for part in value.split('/'))): return False
    return True


def system_member(name):
    if name in FIXED: return True
    if any(name.startswith(prefix+'/') for prefix in TREES): return True
    if re.fullmatch(r'etc/ssh/ssh_host_(?:rsa|ecdsa|ed25519)_key(?:\.pub)?', name): return True
    return bool(re.fullmatch(r'etc/php/8\.[345]/fpm/(?:php\.ini|pool\.d/cmprovision\.conf)', name))


def valid_member(name):
    if not relative(name): return False
    if name in ('application/.env', 'application/etc/dnsmasq.conf', 'application/database.sqlite'): return True
    if name.startswith('application/storage/app/'):
        return name.split('/')[3] not in EXCLUDED_STORAGE
    if name.startswith('application/public/uploads/'): return True
    if name in GENERATED: return True
    if name.startswith('system/'): return system_member(name[7:])
    if re.fullmatch(r'software/(?:cmprovision4|fpms-lite)_[0-9][A-Za-z0-9.+~:-]{0,127}_(?:all|arm64|armhf|amd64)\.deb', name): return True
    if re.fullmatch(r'software/utility-os-[0-9]{4}\.[0-9]{2}(?:\.[0-9]+)?\.tar\.gz', name): return True
    return bool(re.fullmatch(r'utility-os/(?:legacy|bundled-modern|managed-[0-9]{4}\.[0-9]{2}(?:\.[0-9]+)?)/.+', name))


@contextmanager
def safe_open(root, name):
    """Open every parent relative to an fd without following links, including the leaf."""
    if not relative(name): raise UpdateError('Unsafe backup source path.')
    descriptors = []
    try:
        parent = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
        descriptors.append(parent)
        parts = name.split('/')
        for part in parts[:-1]:
            parent = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
            descriptors.append(parent)
        fd = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK, dir_fd=parent)
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode):
            os.close(fd); raise UpdateError('A required backup source is not a regular file.')
        with os.fdopen(fd, 'rb') as stream: yield stream
    except OSError as error: raise UpdateError('A required backup source is missing, linked or unreadable.') from error
    finally:
        for fd in reversed(descriptors): os.close(fd)


def read(root, name, limit=1024**2):
    with safe_open(root, name) as stream: raw = stream.read(limit+1)
    if len(raw) > limit: raise UpdateError('A system configuration file exceeds its size limit.')
    return raw


def _files(root, directory, excluded=()):
    base = Path(root)/directory
    if not base.exists() and not base.is_symlink(): return
    if base.is_symlink() or not base.is_dir(): raise UpdateError('A backup source directory is linked or invalid.')
    for current, directories, files in os.walk(base, followlinks=False):
        if Path(current) == base: directories[:] = [name for name in directories if name not in excluded]
        for name in directories:
            if (Path(current)/name).is_symlink(): raise UpdateError('A backup source contains a linked directory.')
        for name in sorted(files):
            path = Path(current)/name
            value = path.relative_to(root).as_posix()
            with safe_open(root, value): pass
            yield value


def accounts(root):
    passwd = read(root, 'etc/passwd').decode('utf-8').splitlines()
    shadow = {line.split(':')[0]: line.split(':') for line in read(root, 'etc/shadow').decode('utf-8').splitlines()}
    groups = [line.split(':') for line in read(root, 'etc/group').decode('utf-8').splitlines()]
    result = []
    for line in passwd:
        values = line.split(':')
        if len(values) != 7 or not values[2].isdigit() or not values[3].isdigit(): raise UpdateError('Local account metadata is invalid.')
        name, _, uid, gid, gecos, home, shell = values
        if (int(uid) != 0 and not 1000 <= int(uid) < 65534) or shell.endswith(('/nologin', '/false')): continue
        if not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', name) or home not in ('/root', '/home/'+name):
            raise UpdateError('An interactive account has an unsupported name or home. Review it over SSH before backing up.')
        if name not in shadow or len(shadow[name]) != 9: raise UpdateError('A local account password record is missing.')
        authorized = home.lstrip('/')+'/.ssh/authorized_keys'
        path = Path(root)/authorized
        keys = read(root, authorized).decode('utf-8') if path.exists() or path.is_symlink() else ''
        result.append({'name': name, 'uid': int(uid), 'gid': int(gid), 'gecos': gecos, 'home': home, 'shell': shell,
            'password_hash': shadow[name][1], 'aging': shadow[name][2:], 'authorized_keys': keys,
            'primary_group': next((group[0] for group in groups if len(group) == 4 and group[2] == gid), None),
            'groups': [group[0] for group in groups if len(group) == 4 and name in group[3].split(',')]})
    return result


def inventory(root, images=False, packages=False):
    from backup_archive import Entry
    root = Path(root)
    entries, absent = [], []
    def add(name, source=None, group='application', data=None):
        if source:
            with safe_open(root, source) as stream: mode = os.fstat(stream.fileno()).st_mode & 0o777
        else: mode = 0o600
        entries.append(Entry(name, root=root, source=source, data=data, group=group, mode=mode))
    for path in ('.env', 'etc/dnsmasq.conf'):
        add('application/'+path, APP+'/'+path)
    storage = root/APP/'storage/app'
    if storage.exists():
        for item in _files(root, APP+'/storage/app', EXCLUDED_STORAGE):
            add('application/'+item[len(APP)+1:], item)
    if images or packages:
        for item in _files(root, APP+'/public/uploads'):
            is_package = item.startswith(APP+'/public/uploads/packages/')
            if (is_package and packages) or (not is_package and images):
                if '/upload-tmp/' not in item and '/.upload-' not in item:
                    add('application/'+item[len(APP)+1:], item, 'packages' if is_package else 'images')
    for item in FIXED:
        path = root/item
        if not path.exists() and not path.is_symlink(): absent.append(item); continue
        source = item
        if item == 'etc/localtime' and path.is_symlink():
            target = os.readlink(path)
            resolved = (path.parent/target).resolve() if not target.startswith('/') else root/target.lstrip('/')
            if not resolved.is_relative_to(root/'usr/share/zoneinfo'): raise UpdateError('The timezone link points outside supported zoneinfo files.')
            source = resolved.relative_to(root).as_posix()
        add('system/'+item, source, 'system')
    for directory in TREES:
        for item in _files(root, directory): add('system/'+item, item, 'system')
    ssh = root/'etc/ssh'
    if ssh.is_dir():
        for item in ssh.iterdir():
            relative_name = item.relative_to(root).as_posix()
            if re.fullmatch(r'etc/ssh/ssh_host_(?:rsa|ecdsa|ed25519)_key(?:\.pub)?', relative_name):
                add('system/'+relative_name, relative_name, 'ssh')
    php = root/'etc/php'
    if php.is_dir():
        for version in ('8.3', '8.4', '8.5'):
            for suffix in ('php.ini', 'pool.d/cmprovision.conf'):
                item = 'etc/php/'+version+'/fpm/'+suffix
                if (root/item).exists() or (root/item).is_symlink(): add('system/'+item, item, 'system')
    # Certificate/private-key references cannot silently point outside the saved profile.
    for entry in list(entries):
        if not entry.name.startswith('system/etc/NetworkManager/system-connections/'): continue
        contents = read(root, entry.source).decode('utf-8')
        for match in re.finditer(r'^(?:ca-cert|client-cert|private-key|phase2-ca-cert|phase2-client-cert|phase2-private-key)=(.+)$', contents, re.M):
            path = match[1].strip()
            if path.startswith('file://'): path = path[7:]
            if not path.startswith('/etc/NetworkManager/certs/'):
                raise UpdateError('A Wi-Fi certificate/key reference is outside /etc/NetworkManager/certs. Move it there or use SSH to resolve it before backup.')
            if 'system/'+path.lstrip('/') not in {item.name for item in entries}:
                raise UpdateError('A referenced Wi-Fi certificate/key is missing from the backup inventory.')
    add('system/accounts.json', data=canonical_bytes(accounts(root)), group='accounts')
    return entries, absent
