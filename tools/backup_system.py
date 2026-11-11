"""Fixed Linux effects for backup workers. SQLite is accessed only as www-data."""
import base64
from contextlib import contextmanager
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tarfile

from backup_archive import Entry, RESERVE
from backup_policy import APP, inventory, read, safe_open, _files, validate_payloads
from backup_store import BackupBusy
from update_manifest import DEB_VERSION_RE, UpdateError, canonical_bytes, verify_manifest
from update_system import LinuxSystem, BOOT_SERVICES, READERS
from update_utility import REQUIRED, verify_tree

RUNTIME_TABLES = ('jobs', 'failed_jobs', 'job_batches', 'sessions', 'cache', 'cache_locks',
                  'password_resets', 'password_reset_tokens', 'configuration_transfer_operations')


class LinuxBackupSystem:
    def __init__(self, store, root=Path('/'), runner=subprocess.run):
        self.store, self.root, self.runner = store, Path(root), runner
        self.app = self.root/APP
        self.platform = LinuxSystem(store)
        self.platform.command, self.platform.www = self.command, self.www
        self.mutations = store.runtime/'mutations.lock'
        try:
            fd = os.open(self.mutations, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
            os.fchmod(fd, 0o644); os.close(fd)
        except FileExistsError: store._file(self.mutations, 0o644)

    def command(self, argv, timeout=60, check=True, binary=False, **options):
        environment = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C', 'LC_ALL': 'C',
            'DEBIAN_FRONTEND': 'noninteractive', 'CMPROVISION_NO_SNAPSHOT': '1'}
        capture = {'stdout': subprocess.PIPE, 'stderr': subprocess.PIPE}
        capture.update(options)
        try:
            result = self.runner([str(item) for item in argv], timeout=timeout, text=not binary, env=environment,
                check=False, **capture)
        except (OSError, subprocess.SubprocessError) as error:
            raise UpdateError('A backup system operation could not finish: '+Path(argv[0]).name+'.') from error
        if check and result.returncode: raise UpdateError('A backup system operation failed: '+Path(argv[0]).name+'.')
        if not check: return result
        output = result.stdout or (b'' if binary else '')
        if len(output) > 1024**2: raise UpdateError('A backup system response exceeded its size limit.')
        return output.strip()

    def www(self, *arguments):
        raw = self.command(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/php', self.app/'artisan', *arguments], timeout=120)
        try: return json.loads(raw)
        except ValueError as error: raise UpdateError('Application backup inspection returned invalid JSON.') from error

    def admission(self): return self.www('updates:inspect')['admission']

    @contextmanager
    def admission_lock(self):
        # Drain already-admitted HTTP mutations BEFORE taking provisioning.lock. PHP helpers can
        # take provisioning.lock while their request holds mutations SH; the reverse order deadlocks.
        descriptors = []
        try:
            paths = [self.mutations]
            updater = self.root/'var/lib/cmprovision-updates/private'
            if os.path.lexists(updater):
                info = updater.lstat()
                if not stat.S_ISDIR(info.st_mode) or info.st_uid != self.store.owner or info.st_mode & 0o077:
                    raise UpdateError('The updater private directory is unsafe.')
                path = updater/'worker.lock'
                fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
                os.close(fd)
                paths.append(path)
            paths.append(self.root/'run/cmprovision-network/provisioning.lock')
            for path in paths:
                fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
                descriptors.append(fd)
                info = os.fstat(fd)
                if not stat.S_ISREG(info.st_mode) or info.st_uid != self.store.owner or info.st_mode & 0o022:
                    raise UpdateError('The backup admission guard is unsafe.')
                try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                except BlockingIOError as error: raise BackupBusy('The station is finishing another operation.') from error
            yield
        except OSError as error: raise UpdateError('The backup admission guard is unavailable.') from error
        finally:
            for fd in reversed(descriptors): os.close(fd)

    def conflicts(self):
        for name in ('run/cmprovision-updates/pending.json', 'run/cmprovision-network/pending',
                'var/lib/cmprovision-network/transactions/pending.json'):
            if os.path.lexists(self.root/name): raise UpdateError('A station update, network or power change is pending.')
        if self.root == Path('/'):
            spec = importlib.util.spec_from_file_location('backup_power', Path(__file__).with_name('station-power.py'))
            module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
            if module.Controller().pending(): raise UpdateError('A station restart or shutdown is pending.')
        elif os.path.lexists(self.root/'run/cmprovision-power/pending.json'):
            raise UpdateError('A station restart or shutdown is pending.')
        # Check the durable updater projection too: runtime markers may be absent during boot recovery.
        path = self.root/'var/lib/cmprovision-updates/public/status.json'
        if path.exists():
            value = json.loads(read(self.root, path.relative_to(self.root).as_posix(), 4*1024**2))
            if value.get('pending'): raise UpdateError('The station updater is still in maintenance or recovery.')

    def capture_services(self): return self.platform.capture_services()
    def ensure_boot_guard(self):
        from update_recovery import write
        changed = False
        # Retain outside dpkg: an interrupted unpack must not remove the boot barrier.
        for name in BOOT_SERVICES:
            for part in ('etc', 'etc/systemd', 'etc/systemd/system', 'etc/systemd/system/'+name+'.d'):
                self.store._directory(self.root/part, 0o755)
            path = self.root/'etc/systemd/system'/(name+'.d/90-cmprovision-backup-guard.conf')
            raw = ('[Unit]\nConditionPathExists=!'+str(self.store.root/'boot-blocked')+'\n').encode()
            if path.exists() or path.is_symlink():
                self.store._file(path, 0o644)
                if path.read_bytes() != raw: raise UpdateError('The retained backup boot guard was modified; inspect it over SSH.')
            else: write(path, raw, 0o644); changed = True
        if changed: self.command(['/usr/bin/systemctl', 'daemon-reload'])

    def pause(self, services):
        self.quiesce_restore({name: state for name, state in services.items() if name in BOOT_SERVICES or name in READERS})

    def quiesce_restore(self, services):
        # Maintainer scripts and explicit restarts cannot reopen readers/boot servers mid-restore.
        # Existing persistent/runtime masks belong to the station and must be preserved.
        self.ensure_boot_guard()
        pending = self.store.configuration()['pending']
        if pending: self.store._atomic(self.store.root/'boot-blocked', {'id': pending['id']}, 0o644)
        for name, state in services.items():
            if not state['enabled'].startswith('masked'):
                self.command(['/usr/bin/systemctl', 'mask', '--runtime', name])
            self.command(['/usr/bin/systemctl', 'stop', name])
    def resume(self, services):
        self.platform.resume(services, boot=False)
        boot = {name: state for name, state in services.items() if name in BOOT_SERVICES}
        if boot:
            self.store.allow_boot()
            self.platform.resume(boot)

    def snapshot_database(self, directory):
        destination = Path(directory)/'database.sqlite'
        if shutil.disk_usage(directory).free < RESERVE+self.app.joinpath('database/database.sqlite').stat().st_size*3:
            raise UpdateError('Not enough space for a consistent database snapshot and the free-space reserve.')
        temporary = self.command(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/mktemp', '-d', '/var/tmp/cmprovision-full-backup.XXXXXX'])
        if not re.fullmatch(r'/var/tmp/cmprovision-full-backup\.[A-Za-z0-9]{6}', temporary):
            raise UpdateError('The database snapshot producer returned an unsafe temporary directory.')
        prefix = ['/usr/sbin/runuser', '-u', 'www-data', '--']
        saved = temporary+'/database.sqlite'
        try:
            self.command([*prefix, '/usr/bin/sqlite3', '-cmd', '.timeout 10000', self.app/'database/database.sqlite', ".backup '"+saved+"'"], timeout=120)
            tables = self.command([*prefix, '/usr/bin/sqlite3', saved, "SELECT name FROM sqlite_master WHERE type='table';"])
            selected = [name for name in tables.splitlines() if name in RUNTIME_TABLES]
            sql = 'PRAGMA foreign_keys=OFF; BEGIN; '+''.join('DELETE FROM "'+name+'";' for name in selected)+' COMMIT; PRAGMA quick_check;'
            if self.command([*prefix, '/usr/bin/sqlite3', saved, sql], timeout=120) != 'ok':
                raise UpdateError('The database snapshot failed its integrity check.')
            fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            with os.fdopen(fd, 'wb') as output:
                self.command([*prefix, '/usr/bin/cat', saved], stdout=output, binary=True, timeout=120)
                output.flush(); os.fsync(output.fileno())
            return destination
        finally:
            self.command([*prefix, '/usr/bin/rm', '-rf', '--', temporary], check=False)

    def payload_references(self, database):
        helper = getattr(self, 'payload_helper', Path(__file__).with_name('backup_payloads.py'))
        with safe_open(Path(database).parent, Path(database).name) as stream:
            if os.fstat(stream.fileno()).st_size > 512*1024**2: raise UpdateError('Snapshot database exceeds the supported 512 MiB inspection limit.')
            raw = self.command(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/python3', '-I', helper], stdin=stream, timeout=120)
        try: return json.loads(raw)
        except ValueError as error: raise UpdateError('The snapshot payload reference inventory is unreadable.') from error

    def timezone(self):
        if self.root == Path('/'):
            result = self.command(['/usr/bin/timedatectl', 'show', '--property=Timezone', '--value'], check=False, timeout=5)
            try:
                from zoneinfo import ZoneInfo
                name = result.stdout.strip()
                ZoneInfo(name)
                if result.returncode == 0: return name
            except (ValueError, KeyError): pass
        path = self.root/'etc/localtime'
        if path.is_symlink():
            target = os.readlink(path)
            value = target.split('zoneinfo/', 1)[-1] if 'zoneinfo/' in target else None
            if value and '..' not in value.split('/'): return value
        try:
            value = read(self.root, 'etc/timezone', 256).decode().strip()
            from zoneinfo import ZoneInfo
            ZoneInfo(value)
            return value
        except (OSError, ValueError, UpdateError): return 'UTC'

    def context(self):
        values = self.www('updates:inspect')
        package = self.platform.package('cmprovision4')
        if not package: raise UpdateError('cmprovision4 must be fully installed before making a full backup.')
        fpms = self.platform.package('fpms-lite')
        release = 'etc/os-release' if not (self.root/'etc/os-release').is_symlink() else 'usr/lib/os-release'
        os_values = {}
        for line in read(self.root, release, 65536).decode().splitlines():
            if '=' in line:
                key, value = line.split('=', 1); os_values[key] = value.strip('"\'')
        if os_values.get('ID') not in ('debian', 'raspbian') or os_values.get('VERSION_ID') != '13':
            raise UpdateError('Full station backups require the supported Debian/Raspberry Pi OS Trixie platform.')
        values.update(cmprovision=package, fpms_version=fpms,
            os={'id': os_values['ID'], 'version': '13', 'architecture': self.command(['/usr/bin/dpkg', '--print-architecture'])})
        return values

    def _catalogues(self):
        path = self.root/'var/lib/cmprovision-updates/private/state.json'
        if not path.exists(): return []
        try:
            state = json.loads(read(self.root, path.relative_to(self.root).as_posix(), 4*1024**2))
            publisher = json.loads(read(self.root, 'etc/cmprovision-updates/publisher.json', 65536))['repository']
            result = []
            for envelope in state.get('catalogues', {}).values():
                raw = base64.b64decode(envelope['raw'], validate=True)
                signature = base64.b64decode(envelope['signature'], validate=True)
                value = verify_manifest(raw, signature, self.root/'etc/cmprovision-updates/publisher.pub', publisher)
                if value == envelope['manifest'] and hashlib.sha256(raw).hexdigest() == envelope['id']: result.append(envelope)
            return result
        except (OSError, ValueError, KeyError, TypeError): return []

    def _original(self, name, version, catalogues):
        for envelope in catalogues:
            value = envelope['manifest']
            if value['version'] != version: continue
            for asset in value['assets']:
                if not asset['name'].startswith(name+'_') and not asset['name'].startswith(name+'-'): continue
                relative = 'var/lib/cmprovision-updates/private/payloads/'+envelope['id']+'/'+asset['name']
                try:
                    with safe_open(self.root, relative) as stream:
                        info = os.fstat(stream.fileno())
                        if info.st_uid != self.store.owner or info.st_mode & 0o022 or info.st_size != asset['size']: continue
                        if hashlib.file_digest(stream, 'sha256').hexdigest() == asset['sha256']:
                            return relative, envelope
                except (OSError, UpdateError): continue
        return None, None

    def _installed_package_bytes(self, name):
        size = self.command(['/usr/bin/dpkg-query', '-W', '-f=${Installed-Size}', name])
        if size:
            if not re.fullmatch(r'[0-9]{1,12}', size): raise UpdateError('The installed package size metadata is invalid.')
            return int(size)*1024
        # Installed-Size is optional (the portable fpms-lite package omits it). Inspect
        # registered leaf metadata only: directory recursion would count unrelated user payloads.
        paths = self.command(['/usr/bin/dpkg-query', '--listfiles', name]).splitlines()
        if not paths or len(paths) > 100000: raise UpdateError('The installed package file inventory is unavailable.')
        total = 0
        for value in paths:
            path = Path(value)
            if not path.is_absolute() or '..' in path.parts or len(value) > 4096:
                raise UpdateError('The installed package file inventory is unsafe.')
            try: info = (self.root/value.lstrip('/')).lstat()
            except OSError as error: raise UpdateError('A registered station package file is missing; repair the package before backup.') from error
            if stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode): total += info.st_size
            elif not stat.S_ISDIR(info.st_mode): raise UpdateError('A registered station package file has an unsupported type.')
            if total > 2**40: raise UpdateError('The installed station package exceeds the backup size limit.')
        return total

    def _package(self, name, version, directory, catalogues):
        if not re.fullmatch(DEB_VERSION_RE, version): raise UpdateError('Installed package version is invalid.')
        original, envelope = self._original(name, version, catalogues)
        verified = self.command(['/usr/bin/dpkg', '--verify', name], check=False)
        if original and verified.returncode == 0 and not verified.stdout.strip():
            member = 'software/'+Path(original).name
            return Entry(member, root=self.root, source=original, group='software'), {
                'package': name, 'version': version, 'archive': member, 'provenance': 'signed-release', 'envelope': envelope}
        package_dir = directory/name
        package_dir.mkdir(mode=0o700)
        size = self._installed_package_bytes(name)
        if shutil.disk_usage(directory).free < size*2+RESERVE:
            raise UpdateError('Not enough space to capture the required station package while preserving the free-space reserve.')
        self.command(['/usr/bin/dpkg-repack', '--deb-option=-Zgzip', '--deb-option=-z1', '--deb-option=--threads-max=1', name],
            cwd=package_dir, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=900)
        files = list(package_dir.glob(name+'_*.deb'))
        if len(files) != 1 or files[0].is_symlink(): raise UpdateError('A required installed station package could not be captured.')
        package = files[0]
        actual = self.command(['/usr/bin/dpkg-deb', '-f', package, 'Package', 'Version', 'Architecture'])
        fields = dict(line.split(': ', 1) for line in actual.splitlines() if ': ' in line)
        if fields != {'Package': name, 'Version': version, 'Architecture': 'all'}:
            raise UpdateError('The captured station package does not match its installed identity.')
        package.chmod(0o600)
        member = 'software/'+package.name
        return Entry(member, root=package_dir, source=package.name, group='software'), {
            'package': name, 'version': version, 'archive': member, 'provenance': 'local-snapshot'}

    def _utility(self, context, catalogues):
        selector = context.get('utility_selector')
        if selector in ('legacy', 'bundled-modern'):
            boot = self.app/('scriptexecute' if selector == 'legacy' else 'scriptexecute64')
            label = selector
            evidence = {'component': 'utility-os', 'selector': selector, 'provenance': 'local-snapshot'}
        elif type(selector) is str and re.fullmatch(r'managed:[0-9]{4}\.[0-9]{2}(?:\.[0-9]+)?', selector):
            version = selector.split(':', 1)[1]
            base = self.root/'var/lib/cmprovision-utility-os/releases'/version
            receipt = json.loads(read(self.root, (base/'receipt.json').relative_to(self.root).as_posix(), 128*1024))
            value = receipt.get('manifest', {})
            if value.get('version') != version: raise UpdateError('The active Utility OS receipt has the wrong version.')
            boot, label = base/'boot', 'managed-'+version
            verify_tree(boot, value, self.store.owner)
            evidence = {'component': 'utility-os', 'selector': selector, 'provenance': 'local-snapshot'}
        else: raise UpdateError('The active Utility OS selection cannot be reconstructed.')
        link = self.app/'etc/boot'
        if not link.is_symlink(): raise UpdateError('The active Utility OS boot link is missing.')
        target = os.readlink(link)
        actual = self.root/target.lstrip('/') if target.startswith('/') else link.parent/target
        if Path(os.path.normpath(actual)) != boot: raise UpdateError('The active boot link does not match the selected Utility OS.')
        required = (REQUIRED-{'kernel8.img'}) | ({'kernel.img'} if selector == 'legacy' else {'kernel8.img'})
        for name in required:
            with safe_open(boot, name) as stream:
                info = os.fstat(stream.fileno())
                if info.st_uid != self.store.owner or info.st_mode & 0o022: raise UpdateError('Active Utility OS files are not protected.')
        entries = [Entry('utility-os/'+label+'/'+path[len(boot.relative_to(self.root).as_posix())+1:],
            root=self.root, source=path, group='utility-os') for path in _files(self.root, boot.relative_to(self.root).as_posix())]
        if label.startswith('managed-'):
            receipt_path = boot.parent/'receipt.json'
            entries.append(Entry('utility-os/'+label+'/receipt.json', root=boot.parent, source='receipt.json', group='utility-os'))
            original, envelope = self._original('utility-os', version, catalogues)
            if original:
                # The signed archive authenticates its MANIFEST; matching that exact inventory
                # plus verify_tree above proves the captured tree still matches the signed release.
                with safe_open(self.root, original) as source, tarfile.open(fileobj=source, mode='r:gz') as archive:
                    manifests = [item for item in archive if item.isfile() and item.name.rstrip('/').split('/')[-1] == 'MANIFEST']
                    if len(manifests) == 1 and manifests[0].size <= 128*1024:
                        saved_manifest = archive.extractfile(manifests[0]).read(128*1024+1)
                        current_manifest = read(self.root, (boot/'MANIFEST').relative_to(self.root).as_posix(), 128*1024)
                        if saved_manifest == current_manifest:
                            member = 'software/'+Path(original).name
                            entries.append(Entry(member, root=self.root, source=original, group='software'))
                            evidence.update(provenance='signed-release', archive=member, envelope=envelope)
        return entries, evidence

    def collect(self, job, directory):
        stage = Path(directory)/'stage'; stage.mkdir(mode=0o700)
        context = self.context()
        snapshot = self.snapshot_database(stage)
        entries, absent = inventory(self.root, **job['options'])
        payloads = self.payload_references(snapshot)
        validate_payloads(payloads, [{'name': entry.name, 'size': entry.size()} for entry in entries], job['options'], hashes=False)
        entries.append(Entry('application/database.sqlite', root=stage, source=snapshot.name))
        catalogues = self._catalogues()
        software = []
        for name, version in (('cmprovision4', context['cmprovision']), ('fpms-lite', context['fpms_version'])):
            if version:
                entry, evidence = self._package(name, version, stage, catalogues)
                entries.append(entry); software.append(evidence)
        utility, evidence = self._utility(context, catalogues)
        if context['utility_selector'] in ('legacy', 'bundled-modern'):
            evidence.update(provenance=software[0]['provenance'], covered_by='cmprovision4')
        entries.extend(utility); software.append(evidence)
        pending = self.store.configuration()['pending']
        system = {'system/payloads.json': payloads, 'system/service-state.json': pending['services'], 'system/backup-config.json': job['snapshot']['config'],
            'system/software.json': software, 'system/os.json': context['os'],
            'system/time.json': {'timezone': self.timezone(), 'enabled': self.command(['/usr/bin/systemctl', 'is-enabled', 'systemd-timesyncd.service'], check=False).stdout.strip(),
                'active': self.command(['/usr/bin/systemctl', 'is-active', 'systemd-timesyncd.service'], check=False).stdout.strip()}}
        entries.extend(Entry(name, data=canonical_bytes(value), group='system') for name, value in system.items())
        metadata = {'station_id': job['snapshot']['station_id'], 'version': context['cmprovision'],
            'source_commit': context.get('source_commit', 'unknown'), 'os': context['os'], 'options': job['options'],
            'utility_selector': context['utility_selector'], 'software': software, 'absent': absent,
            'created_at': job.get('captured_at', job['created_at']), 'scope': 'supported-station-configuration'}
        return entries, metadata

    def cleanup(self, directory, keep_download=False):
        directory = Path(directory)
        self.store._directory(directory, 0o700)
        for path in directory.iterdir():
            if path.name == 'owner.json': continue
            if path.name == 'backup.cmpbackup' and keep_download: continue
            info = path.lstat()
            if stat.S_ISDIR(info.st_mode): shutil.rmtree(path)
            else: path.unlink()

    def sweep(self):
        state = self.store.configuration()
        current = state['current']
        for directory in (self.store.private/'jobs').iterdir():
            if not re.fullmatch(r'[0-9a-f]{32}', directory.name) or directory.name == current: continue
            self.store._directory(directory, 0o700)
            marker = directory/'owner.json'
            if not marker.exists() or marker.is_symlink(): continue
            self.store._file(marker, 0o600)
            value = json.loads(read(directory, 'owner.json', 4096))
            job = state['operations'].get(directory.name)
            expiry = job.get('expires_at') if job else None
            if (value.get('id') != directory.name or value.get('station_id') not in [state['station_id'], *state.get('artifact_station_ids', [])]
                    or type(value.get('created_at')) is not int): continue
            if self.store.clock() >= (expiry or value['created_at']+3600): shutil.rmtree(directory)
        # Current completed download must expire too; do not touch a live worker's artifacts.
        job = state['operations'].get(current)
        if job and job['state'] in ('ready', 'succeeded', 'failed', 'canceled') and not state['pending']:
            expiry = job.get('expires_at') or job['updated_at']+3600
            if self.store.clock() >= expiry:
                with self.store.lock('worker', blocking=False):
                    path = self.store.private/'jobs'/current
                    if path.exists(): self.store._directory(path, 0o700); shutil.rmtree(path)
