"""Fixed Linux effects for the root updater. Application DB access always runs as www-data."""
from contextlib import contextmanager
import fcntl
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import time

from update_manifest import DEB_VERSION_RE, UpdateError
from update_store import safe_text

APP = Path('/var/lib/cmprovision')
HELPERS = Path('/usr/lib/cmprovision')
BOOT_SERVICES = frozenset(('cmprovision-dnsmasq.service', 'cmprovision-rpiboot.service'))
READERS = ('cmprovision-queue.service', 'cmprovision-queue-images.service',
           'cmprovision-usb-diagnostics.service', 'cmprovision-wallboard-data.service',
           'cmprovision-fpms-data.service')
WATCHERS = ('cmprovision-wallboard.service', 'cmprovision-wallboard-display.service',
            'cmprovision-fpms.service', 'fpms-lite.service')
PACKAGE = r'[a-z0-9][a-z0-9+.-]*(?::[a-z0-9]+)?'


def parse_plan(text):
    result = []
    for line in text.splitlines():
        if line.startswith(('Remv ', 'Purg ')): raise UpdateError('The update would remove packages; resolve dependencies over SSH.')
        if not line.startswith('Inst '): continue
        match = re.fullmatch(r'Inst ('+PACKAGE+r')(?: \[[^\]\r\n]+\])? \((\S+) [^\r\n]+\)', line)
        if not match or not re.fullmatch(DEB_VERSION_RE, match[2]):
            raise UpdateError('The package manager returned an unsupported installation plan.')
        result.append({'package': match[1], 'version': match[2]})
    if len(result) > 128 or len({x['package'] for x in result}) != len(result):
        raise UpdateError('The dependency plan is too large or ambiguous.')
    return sorted(result, key=lambda item: item['package'])


class LinuxSystem:
    def __init__(self, store): self.store = store

    def command(self, argv, timeout=60, check=True, **options):
        env = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C', 'LC_ALL': 'C',
               'DEBIAN_FRONTEND': 'noninteractive', 'CMPROVISION_NO_SNAPSHOT': '1'}
        try:
            result = subprocess.run([str(arg) for arg in argv], timeout=timeout, capture_output=True,
                                    text=True, env=env, check=False, **options)
        except (OSError, subprocess.TimeoutExpired) as error:
            raise UpdateError('A required system operation timed out or could not start: '+Path(argv[0]).name) from error
        if check and result.returncode:
            print(safe_text((result.stderr+' '+result.stdout)[-2000:], 2000), file=sys.stderr)
            raise UpdateError('A required system operation failed: '+Path(argv[0]).name+'. Inspect the updater service journal.')
        return result.stdout.strip() if check else result

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

    def plan_install(self, paths, value):
        if self.command(['/usr/bin/dpkg', '--audit']): raise UpdateError('Finish the existing interrupted package operation over SSH first.')
        expected = {'cmprovision4': value['version']}
        if len(paths) == 2: expected['fpms-lite'] = value['version']
        for path in paths:
            info = self.command(['/usr/bin/dpkg-deb', '-f', path, 'Package', 'Version', 'Architecture'])
            fields = dict(line.split(': ', 1) for line in info.splitlines() if ': ' in line)
            if fields.get('Package') not in expected or fields.get('Version') != expected[fields['Package']] or fields.get('Architecture') != 'all':
                raise UpdateError('Signed package contents do not match their release metadata.')
        result = self.command(['/usr/bin/apt-get', '--simulate', '--no-remove', 'install', *paths], timeout=120)
        plan = parse_plan(result)
        if not all(any(item == {'package': name, 'version': version} for item in plan) for name, version in expected.items()):
            raise UpdateError('The package plan does not install the selected component versions.')
        return plan

    def prefetch(self, paths, plan):
        self.command(['/usr/bin/apt-get', '-y', '--download-only', '--no-remove', 'install', *paths], timeout=1800)

    def install(self, paths, plan, value):
        if self.plan_install(paths, value) != plan: raise UpdateError('The approved dependency plan changed.')
        # apt3 --no-download rejects local .deb paths. Unpack verified application files first;
        # configure them with pinned package names and already cached dependencies, offline.
        self.command(['/usr/bin/dpkg', '--force-confold', '--unpack', *paths], timeout=600)
        self.command(['/usr/bin/apt-get', '-y', '--no-download', '--no-remove',
                      '-o', 'Dpkg::Options::=--force-confold', '--fix-broken', 'install',
                      *(item['package']+'='+item['version'] for item in plan)], timeout=1800)

    def backup(self, identity):
        directory = self.store.private/'recovery'/identity
        self.store._directory(directory, 0o700)
        # Refuse installation unless a full, reinstallable snapshot can be kept independently
        # of the application's ordinary five-snapshot retention policy.
        size = int(self.command(['/usr/bin/du', '-sx', '--block-size=1', APP]).split()[0])
        if shutil.disk_usage(directory).free < size*2+128*1024**2:
            raise UpdateError('Not enough free space for a required recovery snapshot.')
        restore = directory/'restore.sh'
        shutil.copyfile('/usr/sbin/cmprovision-restore', restore)
        restore.chmod(0o700)
        self.command(['/usr/sbin/cmprovision-backup', '--with-uploads', '--keep', 'all', directory], timeout=1800)
        snapshots = [path for path in directory.iterdir() if path.is_dir() and not path.is_symlink()]
        if len(snapshots) != 1: raise UpdateError('Could not identify the completed recovery snapshot.')
        snapshot = snapshots[0]
        if (snapshot/'.incomplete').exists() or not list(snapshot.glob('cmprovision4_*.deb')):
            raise UpdateError('The recovery snapshot has no reinstallable application package.')
        if self.package('fpms-lite'):
            self.command(['/usr/bin/dpkg-repack', 'fpms-lite'], cwd=snapshot, timeout=600)
            if not list(snapshot.glob('fpms-lite_*.deb')): raise UpdateError('The recovery snapshot has no FPMS package.')
        hashes = {path.name: hashlib.file_digest(path.open('rb'), 'sha256').hexdigest()
                  for path in snapshot.iterdir() if path.is_file()}
        return {'directory': str(snapshot), 'identity': identity, 'hashes': hashes,
                'restore_sha256': hashlib.file_digest(restore.open('rb'), 'sha256').hexdigest()}

    def recover(self, backup):
        directory = self.store.private/'recovery'/backup['identity']
        snapshot = Path(backup['directory'])
        if snapshot.parent != directory or snapshot.is_symlink(): raise UpdateError('Recovery snapshot path does not match.')
        restore = directory/'restore.sh'
        for path, expected in [(restore, backup['restore_sha256']),
                               *((snapshot/name, digest) for name, digest in backup['hashes'].items())]:
            self.store._file(path)
            with path.open('rb') as stream:
                if hashlib.file_digest(stream, 'sha256').hexdigest() != expected: raise UpdateError('Recovery snapshot checksum failed.')
        # Restore package(s) offline; the retained helper then restores data/config without
        # invoking apt (and hence without allowing an unrecorded network dependency change).
        packages = sorted(snapshot.glob('*.deb'))
        self.command(['/usr/bin/dpkg', '--force-confold', '-i', *packages], timeout=1800)
        data = directory/'restore-data'
        self.store._directory(data, 0o700)
        for name in ('app.tar.gz', 'database.sqlite', 'system-config.tar.gz'):
            if name not in backup['hashes']: raise UpdateError('The recovery snapshot is incomplete.')
            if not (data/name).exists(): os.link(snapshot/name, data/name)
        self.command(['/bin/sh', restore, '--force', data], timeout=1800)
        from update_recovery import restore_trust
        restore_trust(self.store, backup['identity'])
        self.activate_utility(self.store.operation(backup['identity']).get('previous', {}).get('utility_selector', 'bundled-modern'))

    def health(self, expected, services):
        version = expected.get('cmprovision', expected.get('version'))
        if self.package('cmprovision4') != version: raise UpdateError('Installed application version does not match.')
        if expected.get('component') == 'application' and self.package('fpms-lite') is not None and self.package('fpms-lite') != version:
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

    def prune(self):
        from update_retention import prune
        try: active = self.www('updates:inspect').get('utility_selector')
        except Exception: active = None
        prune(self.store, active_selector=active)

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
