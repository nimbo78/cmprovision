"""Fixed Linux effects for the root updater. Application DB access always runs as www-data."""
from contextlib import closing, contextmanager
import fcntl
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import stat
import subprocess
import sys
import time

from update_manifest import DEB_VERSION_RE, UpdateError
from update_recovery import BOOT_SERVICES
from update_store import ID_RE, matches, safe_text

APP = Path('/var/lib/cmprovision')
HELPERS = Path('/usr/lib/cmprovision')
RESTORE_HELPER = Path('/usr/sbin/cmprovision-restore')
READERS = ('cmprovision-queue.service', 'cmprovision-queue-images.service',
           'cmprovision-usb-diagnostics.service', 'cmprovision-wallboard-data.service',
           'cmprovision-fpms-data.service')
WATCHERS = ('cmprovision-wallboard.service', 'cmprovision-wallboard-display.service',
            'cmprovision-fpms.service', 'fpms-lite.service')
PACKAGE = r'[a-z0-9][a-z0-9+.-]*(?::[a-z0-9]+)?'
RECOVERY_DATA = ('app.tar.gz', 'database.sqlite', 'system-config.tar.gz')


def file_sha256(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def parse_plan(text, *, additions_only=False):
    result = []
    for line in text.splitlines():
        if line.startswith(('Remv ', 'Purg ')): raise UpdateError('The update would remove packages; resolve dependencies over SSH.')
        if not line.startswith('Inst '): continue
        match = re.fullmatch(r'Inst ('+PACKAGE+r')(?: \[([^\]\r\n]+)\])? \((\S+) [^\r\n]+\)', line)
        if not match or not re.fullmatch(DEB_VERSION_RE, match[3]):
            raise UpdateError('The package manager returned an unsupported installation plan.')
        if additions_only and match[2] is not None:
            raise UpdateError('Display support would change an installed package. Update its dependencies first.')
        result.append({'package': match[1], 'version': match[3]})
    if len(result) > 128 or len({x['package'] for x in result}) != len(result):
        raise UpdateError('The dependency plan is too large or ambiguous.')
    return sorted(result, key=lambda item: item['package'])


class LinuxSystem:
    def __init__(self, store): self.store = store

    def command(self, argv, timeout=60, check=True, *, stdout=subprocess.PIPE, strict_snapshot=False, **options):
        env = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C', 'LC_ALL': 'C',
               'DEBIAN_FRONTEND': 'noninteractive', 'CMPROVISION_NO_SNAPSHOT': '1'}
        if strict_snapshot:
            env['CMPROVISION_UPDATE_SNAPSHOT'] = '1'
        try:
            result = subprocess.run([str(arg) for arg in argv], timeout=timeout, stdout=stdout, stderr=subprocess.PIPE,
                                    text=True, env=env, check=False, **options)
        except (OSError, subprocess.TimeoutExpired) as error:
            raise UpdateError('A required system operation timed out or could not start: '+Path(argv[0]).name) from error
        if check and result.returncode:
            print(safe_text((result.stderr+' '+(result.stdout or ''))[-2000:], 2000), file=sys.stderr)
            raise UpdateError('A required system operation failed: '+Path(argv[0]).name+'. Inspect the updater service journal.')
        return (result.stdout or '').strip() if check else result

    def www(self, *args):
        return json.loads(self.command(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/php',
                                       APP/'artisan', *args], timeout=120))

    def package(self, name):
        result = self.command(['/usr/bin/dpkg-query', '-W', '-f=${db:Status-Status}\t${Version}', name], check=False)
        parts = result.stdout.strip().split('\t')
        return parts[1] if result.returncode == 0 and len(parts) == 2 and parts[0] == 'installed' else None

    def context(self):
        php = self.command(['/usr/bin/php', '-r', 'echo PHP_VERSION;'])
        app, fpms = self.package('cmprovision4'), self.package('fpms-lite')
        if not app: raise UpdateError('cmprovision4 is not fully installed.')
        values = self.www('updates:inspect')
        return dict(values, debian=Path('/etc/debian_version').read_text().split('.')[0].strip(),
                    architecture=self.command(['/usr/bin/dpkg', '--print-architecture']), php=php,
                    cmprovision=app, fpms_installed=fpms is not None, fpms_version=fpms, updater_protocol=1)

    def admission(self): return self.www('updates:inspect')['admission']

    def download_space(self, size):
        if shutil.disk_usage(self.store.payloads).free < size+64*1024**2:
            raise UpdateError('Not enough free space for the signed download plus the 64 MiB safety reserve.')

    def plan_snapshot_space(self, selected):
        # Only a new installation needs the estimator. A retained protocol-1 runner
        # can still recover offline after an older package has replaced the live code.
        from update_space import estimate
        return estimate(self.store, selected=selected)

    def require_snapshot_space(self, plan):
        from update_space import measure
        report = measure(plan, reclaimable=False)
        if not report['sufficient']:
            missing = [f"{(row['shortfall']+1048575)//1048576} MiB on {row['location']}"
                       for row in report['volumes'] if row['shortfall'] > 0]
            raise UpdateError('Not enough free space for a recovery snapshot. Free at least '+', '.join(missing)+'.')

    def conflicts(self):
        if os.path.lexists('/run/cmprovision-backups/pending.json'):
            raise UpdateError('A station backup is being created; wait for it to finish.')
        if any(os.path.lexists(path) for path in ('/run/cmprovision-network/pending',
                '/var/lib/cmprovision-network/transactions/pending.json')):
            raise UpdateError('Confirm or recover the pending network change first.')
        # The fixed power controller validates boot identity/expiry instead of treating a stale file as a request.
        from importlib.machinery import SourceFileLoader
        power = SourceFileLoader('update_power_control', str(HELPERS/'station-power.py')).load_module()
        if power.Controller().pending(): raise UpdateError('A station restart or shutdown is pending.')

    @contextmanager
    def admission_lock(self):
        path = Path('/run/cmprovision-network/provisioning.lock')
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
                raise UpdateError('The provisioning admission lock is unsafe.')
            fcntl.flock(fd, fcntl.LOCK_EX)
            yield
        finally: os.close(fd)

    def capture_services(self):
        php = self.command(['/usr/bin/systemctl', 'list-unit-files', 'php*-fpm.service', '--no-legend', '--no-pager'])
        names = ['nginx.service', *BOOT_SERVICES, *READERS, *WATCHERS,
                 *re.findall(r'^(php[0-9.]+-fpm\.service)\s', php, re.M)]
        states = {}
        for name in names:
            result = self.command(['/usr/bin/systemctl', 'show', name, '-p', 'LoadState', '-p', 'UnitFileState', '-p', 'ActiveState'], check=False)
            values = dict(line.split('=', 1) for line in result.stdout.splitlines() if '=' in line)
            if values.get('LoadState') == 'not-found': continue
            if values.get('ActiveState') not in ('active', 'inactive', 'failed'):
                raise UpdateError('Wait for station services to finish starting/stopping before applying updates.')
            enabled = values.get('UnitFileState', '')
            if enabled.startswith('masked') and values['ActiveState'] == 'active':
                raise UpdateError('A masked service is still running. Stop or unmask it before updating: '+name)
            if enabled not in ('enabled', 'enabled-runtime', 'disabled', 'static', 'indirect', 'masked', 'masked-runtime'):
                raise UpdateError('Unsupported service state for '+name)
            states[name] = {'enabled': enabled, 'active': values['ActiveState'] == 'active'}
        return states

    def pause(self, services):
        for name, state in services.items():
            if name in BOOT_SERVICES or name in READERS:
                if state['enabled'] not in ('masked', 'masked-runtime'):
                    self.command(['/usr/bin/systemctl', 'mask', '--runtime', name])
            self.command(['/usr/bin/systemctl', 'stop', name])

    def ensure_boot_guard(self):
        from update_recovery import install_boot_guard
        install_boot_guard(self.store)
        # Reload even after a previously interrupted installation of the drop-ins.
        self.command(['/usr/bin/systemctl', 'daemon-reload'])

    def check_boot_services(self, services, *, role_only=False):
        for name in BOOT_SERVICES:
            wanted = services.get(name)
            result = self.command(['/usr/bin/systemctl', 'show', name, '-p', 'LoadState',
                '-p', 'UnitFileState', '-p', 'ActiveState'])
            state = dict(line.split('=', 1) for line in result.splitlines() if '=' in line)
            if not wanted and state.get('LoadState') == 'not-found': continue
            if not wanted or state.get('UnitFileState') != wanted['enabled']:
                raise UpdateError('The station boot-service role differs from the snapshot: '+name)
            if not role_only and state.get('ActiveState') != ('active' if wanted['active'] else 'inactive'):
                raise UpdateError('Could not restore the recorded boot-service state: '+name)

    def resume(self, services, boot=True):
        for name, state in services.items():
            if not boot and name in BOOT_SERVICES: continue
            enabled = state['enabled']
            if enabled not in ('masked', 'masked-runtime'):
                self.command(['/usr/bin/systemctl', 'unmask', '--runtime', name])
                if enabled in ('enabled', 'disabled'):
                    self.command(['/usr/bin/systemctl', 'enable' if enabled == 'enabled' else 'disable', name])
                elif enabled == 'enabled-runtime':
                    self.command(['/usr/bin/systemctl', 'enable', '--runtime', name])
            else:
                argv = ['/usr/bin/systemctl', 'mask']
                if enabled == 'masked-runtime': argv.append('--runtime')
                self.command([*argv, name])
            self.command(['/usr/bin/systemctl', 'start' if state['active'] and not enabled.startswith('masked') else 'stop', name])

    def plan_install(self, paths, value, *, fpms_only=False):
        version = value['version']
        audit = self.command(['/usr/bin/dpkg', '--audit'])
        retry_panel = False
        if audit and fpms_only and audit == self.command(['/usr/bin/dpkg', '--audit', 'fpms-lite']):
            state = self.command(['/usr/bin/dpkg-query', '-W', '-f=${db:Status-Status}\t${Version}', 'fpms-lite'])
            retry_panel = state in ('unpacked\t'+version, 'half-configured\t'+version)
        if audit and not retry_panel: raise UpdateError('Finish the existing interrupted package operation over SSH first.')
        expected = {'fpms-lite' if fpms_only else 'cmprovision4': version}
        if not fpms_only and len(paths) == 2: expected['fpms-lite'] = version
        for path in paths:
            info = self.command(['/usr/bin/dpkg-deb', '-f', path, 'Package', 'Version', 'Architecture'])
            fields = dict(line.split(': ', 1) for line in info.splitlines() if ': ' in line)
            if fields.get('Package') not in expected or fields.get('Version') != expected[fields['Package']] or fields.get('Architecture') != 'all':
                raise UpdateError('Signed package contents do not match their release metadata.')
        result = self.command(['/usr/bin/apt-get', '--simulate', '--no-remove', 'install', *paths], timeout=120)
        plan = parse_plan(result, additions_only=fpms_only)
        # After power loss APT may only propose configuring the unpacked panel.
        # The retry still installs the exact file verified against the signed release.
        if retry_panel and not any(item['package'] == 'fpms-lite' for item in plan):
            plan.append({'package': 'fpms-lite', 'version': version})
            plan.sort(key=lambda item: item['package'])
        if not all(any(item == {'package': name, 'version': version} for item in plan) for name, version in expected.items()):
            raise UpdateError('The package plan does not install the selected component versions.')
        return plan

    def prefetch(self, paths, plan):
        self.command(['/usr/bin/apt-get', '-y', '--download-only', '--no-remove', 'install', *paths], timeout=1800)

    def install_fpms(self, path, version, plan):
        if self.plan_install([path], {'version': version}, fpms_only=True) != plan:
            raise UpdateError('Display dependencies changed. Retry installation.')
        dependencies = [item for item in plan if item['package'] != 'fpms-lite']
        if dependencies:
            pins = [item['package']+'='+item['version'] for item in dependencies]
            args = ['--no-remove', '--no-install-recommends', '--mark-auto', 'install', *pins]
            simulated = self.command(['/usr/bin/apt-get', '--simulate', *args], timeout=120)
            if parse_plan(simulated, additions_only=True) != dependencies:
                raise UpdateError('The cached display dependencies no longer match the installation plan.')
            self.command(['/usr/bin/apt-get', '-y', '--no-download', *args], timeout=1800)
        self.command(['/usr/bin/dpkg', '--force-confold', '--install', path], timeout=600)

    def install(self, paths, plan, value, *, before_mutation=None):
        if self.plan_install(paths, value) != plan: raise UpdateError('The approved dependency plan changed.')
        # apt3 --no-download rejects local .deb paths. Unpack verified application files first;
        # configure them with pinned package names and already cached dependencies, offline.
        if before_mutation: before_mutation()
        self.command(['/usr/bin/dpkg', '--force-confold', '--unpack', *paths], timeout=600)
        self.command(['/usr/bin/apt-get', '-y', '--no-download', '--no-remove',
                      '-o', 'Dpkg::Options::=--force-confold', '--fix-broken', 'install',
                      *(item['package']+'='+item['version'] for item in plan)], timeout=1800)

    def backup(self, identity):
        if not matches(ID_RE, identity):
            raise UpdateError('Invalid recovery operation identifier.')
        directory = self.store.private/'recovery'/identity
        self.store._directory(directory, 0o700)
        if any(directory.iterdir()):
            raise UpdateError('The new recovery snapshot needs its own empty operation directory.')
        try:
            return self._create_snapshot(identity, directory)
        except Exception:
            # Under the worker lock this directory contained only this unpublished attempt.
            # Reclaim it before the worker records the failure, especially after ENOSPC.
            try:
                shutil.rmtree(directory)
            except OSError as error:
                print('Could not remove the unpublished recovery snapshot:', error, file=sys.stderr)
            raise

    def _create_snapshot(self, identity, directory):
        restore = directory/'restore.sh'
        with RESTORE_HELPER.open('rb') as source, restore.open('xb') as destination:
            os.fchmod(destination.fileno(), 0o700)
            shutil.copyfileobj(source, destination)
            destination.flush()
            os.fsync(destination.fileno())
        self.command(['/usr/sbin/cmprovision-backup', '--with-uploads', '--keep', '1', directory],
                     timeout=1800, strict_snapshot=True)
        snapshots = [path for path in directory.iterdir() if path.is_dir() and not path.is_symlink()]
        if len(snapshots) != 1: raise UpdateError('Could not identify the completed recovery snapshot.')
        snapshot = snapshots[0]
        if (snapshot/'.incomplete').exists() or not list(snapshot.glob('cmprovision4_*.deb')):
            raise UpdateError('The recovery snapshot has no reinstallable application package.')
        if self.package('fpms-lite'):
            self.command(['/usr/bin/dpkg-repack', 'fpms-lite'], cwd=snapshot, timeout=600)
            if not list(snapshot.glob('fpms-lite_*.deb')): raise UpdateError('The recovery snapshot has no FPMS package.')
        names = {path.name for path in snapshot.iterdir()}
        stamps = self._snapshot_stamps(identity, snapshot, names)
        self._check_snapshot_contents(identity, snapshot, names)
        hashes = {}
        flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC
        try:
            for path in stamps:
                fd = os.open(path, flags | os.O_NONBLOCK)
                with os.fdopen(fd, 'rb') as stream:
                    if not stat.S_ISREG(os.fstat(fd).st_mode):
                        raise UpdateError('The recovery snapshot file changed during verification.')
                    os.fsync(fd)
                    hashes[path] = hashlib.file_digest(stream, 'sha256').hexdigest()
            # Each child's name must survive in its parent, including a freshly made
            # recovery tree or updater root. Each fd refers to its own filesystem.
            for path in (snapshot, directory, directory.parent, self.store.private, self.store.root, self.store.root.parent):
                fd = os.open(path, flags | os.O_DIRECTORY)
                try: os.fsync(fd)
                finally: os.close(fd)
        except OSError as error:
            raise UpdateError('Could not write the recovery snapshot to stable storage.') from error
        if stamps != self._snapshot_stamps(identity, snapshot, names):
            raise UpdateError('The recovery snapshot changed during verification.')
        return {'directory': str(snapshot), 'identity': identity,
                'hashes': {name: hashes[snapshot/name] for name in names}, 'restore_sha256': hashes[restore]}

    def _snapshot_stamps(self, identity, snapshot, names):
        from update_recovery import protected
        if not matches(ID_RE, identity) or snapshot.parent != self.store.private/'recovery'/identity:
            raise UpdateError('Recovery snapshot path does not match.')
        try:
            for directory in (snapshot.parent.parent, snapshot.parent, snapshot):
                protected(directory, self.store.owner, directory=True)
            if ('.incomplete' in names or not set(RECOVERY_DATA).issubset(names)
                    or set(names) != {path.name for path in snapshot.iterdir()}):
                raise UpdateError('The recovery snapshot is incomplete or its inventory changed.')
            stamps = {}
            for path in [snapshot.parent/'restore.sh', *(snapshot/name for name in names)]:
                info = self.store._file(path)
                stamps[path] = (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns)
            return stamps
        except (OSError, RuntimeError) as error:
            raise UpdateError('The recovery snapshot is missing, unreadable or unsafe.') from error

    def _check_snapshot_contents(self, identity, snapshot, names):
        previous = self.store.operation(identity)['previous']
        expected = {'cmprovision4': previous['cmprovision']}
        if previous.get('fpms_installed'): expected['fpms-lite'] = previous['fpms_version']
        for name in sorted(n for n in names if n.endswith('.deb')):
            package = name.partition('_')[0]
            version = expected.pop(package, None)
            if not version: raise UpdateError('The recovery snapshot contains an unexpected package.')
            info = self.command(['/usr/bin/dpkg-deb', '-f', snapshot/name, 'Package', 'Version', 'Architecture'], check=False)
            fields = dict(line.split(': ', 1) for line in info.stdout.splitlines() if ': ' in line)
            if info.returncode: raise UpdateError('The recovery package '+name+' is unreadable.')
            if fields != {'Package': package, 'Version': version, 'Architecture': 'all'}:
                raise UpdateError('A recovery package does not match the recorded installation.')
            contents = self.command(['/usr/bin/dpkg-deb', '--contents', snapshot/name], check=False,
                                    stdout=subprocess.DEVNULL, timeout=1800)
            if contents.returncode: raise UpdateError('The recovery package '+name+' has an unreadable payload.')
        if expected: raise UpdateError('The recovery snapshot is missing an installed station package.')

        # Read through the gzip footer and tar headers without extracting user data.
        # Selecting required members keeps the command output bounded for large libraries.
        for name, members in (
                ('app.tar.gz', ('var/lib/cmprovision/.env', 'var/lib/cmprovision/etc/dnsmasq.conf')),
                ('system-config.tar.gz', ('etc/nginx/sites-available/cmprovision',))):
            result = self.command(['/usr/bin/tar', '--list', '--gzip', '--ignore-zeros', '--file', snapshot/name,
                                   '--', *members], check=False, timeout=1800)
            if result.returncode: raise UpdateError('The recovery archive '+name+' is incomplete or unreadable.')
        database = snapshot/'database.sqlite'
        if not database.stat().st_size: raise UpdateError('The recovery database is empty.')
        try:
            # This is the root-owned copy. Immutable mode never creates a journal beside it.
            with closing(sqlite3.connect(database.as_uri()+'?mode=ro&immutable=1', uri=True)) as connection:
                connection.execute('PRAGMA trusted_schema=OFF')
                if connection.execute('PRAGMA quick_check(1)').fetchone() != ('ok',):
                    raise UpdateError('The recovery database failed its integrity check.')
        except sqlite3.Error as error:
            raise UpdateError('The recovery database is unreadable.') from error

    def validate_backup(self, backup):
        snapshot = Path(backup['directory'])
        hashes = backup['hashes']
        stamps = self._snapshot_stamps(backup['identity'], snapshot, hashes)
        if (file_sha256(snapshot.parent/'restore.sh') != backup['restore_sha256']
                or any(file_sha256(snapshot/name) != digest for name, digest in hashes.items())):
            raise UpdateError('Recovery snapshot checksum failed.')
        self._check_snapshot_contents(backup['identity'], snapshot, hashes)
        return stamps

    def recover(self, backup, *, verified=None, before_mutation=None, safety=None):
        snapshot = Path(backup['directory'])
        directory = snapshot.parent
        if verified is None: verified = self.validate_backup(backup)
        # The worker lock protects the selected source from pruning. Recheck the protected
        # files after taking the safety copy without decompressing the entire library twice.
        if verified != self._snapshot_stamps(backup['identity'], snapshot, backup['hashes']):
            raise UpdateError('The recovery snapshot changed after verification.')
        helper = directory/'restore.sh'
        if safety is not None:
            # A pre-2.30.6 helper could ignore a system-config extraction error.
            # Use the strict helper saved by this rollback before dpkg replaces it.
            from update_recovery import protected
            if not matches(ID_RE, safety['identity']):
                raise UpdateError('Invalid safety snapshot identity.')
            helper = self.store.private/'recovery'/safety['identity']/'restore.sh'
            protected(helper.parent, self.store.owner, directory=True)
            self.store._file(helper)
            if file_sha256(helper) != safety['restore_sha256']:
                raise UpdateError('The safety snapshot restore helper changed.')
        data = directory/'restore-data'
        self.store._directory(data, 0o700)
        optional = ('time-settings.saved', 'boot-services.saved')
        files = (*RECOVERY_DATA, *(name for name in optional if name in backup['hashes']))
        if any(path.name not in files for path in data.iterdir()):
            raise UpdateError('Unexpected file in the recovery staging directory.')
        for name in files:
            target = data/name
            if os.path.lexists(target):
                self.store._file(target)
                if not target.samefile(snapshot/name): raise UpdateError('The staged recovery data changed.')
            else:
                os.link(snapshot/name, target)
        packages = sorted(snapshot/name for name in backup['hashes'] if name.endswith('.deb'))
        if before_mutation: before_mutation()
        self.command(['/usr/bin/dpkg', '--force-confold', '-i', *packages], timeout=1800)
        self.command(['/bin/sh', helper, '--force', data], timeout=1800, strict_snapshot=True)
        from update_recovery import restore_trust
        restore_trust(self.store, backup['identity'])
        self.activate_utility(self.store.operation(backup['identity']).get('previous', {}).get('utility_selector', 'bundled-modern'))

    def health(self, expected, services):
        version = expected.get('cmprovision', expected.get('version'))
        if self.package('cmprovision4') != version: raise UpdateError('Installed application version does not match.')
        if 'fpms_installed' in expected:
            wanted_fpms = expected['fpms_version'] if expected['fpms_installed'] else None
            if self.package('fpms-lite') != wanted_fpms:
                raise UpdateError('Installed FPMS package does not match the expected version or presence.')
        elif expected.get('component') == 'application' and self.package('fpms-lite') not in (None, version):
            raise UpdateError('FPMS package version does not match the application.')
        result = self.www('updates:health')
        if result.get('ok') is not True: raise UpdateError('Application database/schema health check failed.')
        self.http_health(expected)
        for name, state in services.items():
            if name not in BOOT_SERVICES and state['active']:
                if self.command(['/usr/bin/systemctl', 'is-active', name]) != 'active':
                    raise UpdateError('Updated service is not healthy: '+name)

    def http_health(self, expected):
        for attempt in range(15):
            connection = http.client.HTTPConnection('127.0.0.1', 80, timeout=3)
            try:
                connection.request('GET', '/updates/health', headers={'Accept': 'application/json'})
                response = connection.getresponse()
                value = json.loads(response.read(4097))
                if response.status == 200 and value.get('ok') is True and value.get('protocol') == 1:
                    php = value.get('php', '')
                    minimum = expected.get('compatibility', {}).get('php', {}).get('.'.join(php.split('.')[:2]))
                    if expected.get('compatibility') and (not minimum or self.command(['/usr/bin/dpkg', '--compare-versions', php, 'ge', minimum], check=False).returncode):
                        raise UpdateError('The running PHP-FPM version does not meet release security requirements.')
                    login = http.client.HTTPConnection('127.0.0.1', 80, timeout=3)
                    try:
                        login.request('GET', '/login')
                        page = login.getresponse()
                        body = page.read(1024*1024)
                        if page.status == 200 and b'name="email"' in body and b'name="password"' in body:
                            return
                    finally: login.close()
            except (OSError, ValueError, http.client.HTTPException): pass
            finally: connection.close()
            if attempt < 14: time.sleep(2)
        raise UpdateError('The updated nginx/PHP web interface failed its loopback health check.')

    def retain_runner(self, identity):
        from update_recovery import retain_runner, install_dispatcher
        install_dispatcher(self.store)
        return retain_runner(self.store, identity)

    def prune(self, before_snapshot=False):
        from update_retention import prune
        try: active = self.www('updates:inspect').get('utility_selector')
        except Exception: active = None
        prune(self.store, active_selector=active, before_snapshot=before_snapshot)

    def prepare_utility(self, envelope, archive, store):
        from update_utility import ManagedUtility
        selector = ManagedUtility(store).prepare(envelope, archive)
        return {'selector': selector, 'files': [archive.name]}

    def validate_utility(self, selector):
        if selector.startswith('managed:'):
            from update_utility import ManagedUtility
            ManagedUtility(self.store).validate(selector, self.context())
        return self.www('updates:utility', '--validate='+selector)
    def activate_utility(self, selector):
        self.validate_utility(selector)
        return self.www('updates:utility', '--activate='+selector)
    def utility_health(self, selector):
        result = self.validate_utility(selector)
        if result.get('current') != selector: raise UpdateError('Utility OS activation could not be verified.')
