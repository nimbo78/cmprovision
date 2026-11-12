"""Privileged restore effects, separately injectable for disposable integration tests."""
from contextlib import contextmanager
import ctypes
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import tempfile
import uuid

from backup_archive import RESERVE, create, verify_stream
from backup_policy import APP, read, safe_open, validate_payloads
from backup_restore import file_group, json_member, parent_fd, write_file, restore_backup_configuration
from backup_system import LinuxBackupSystem, RUNTIME_TABLES
from backup_worker import Worker
from update_manifest import UpdateError, canonical_bytes
from update_system import BOOT_SERVICES, parse_plan


class LinuxRestoreSystem(LinuxBackupSystem):
    def validate_restore_payloads(self, archive):
        size = next(row['size'] for row in archive.manifest['members'] if row['name'] == 'application/database.sqlite')
        if size > 512*1024**2 or shutil.disk_usage(self.store.private).free < size*2+RESERVE:
            raise UpdateError('Not enough space to inspect snapshot database references with the free-space reserve.')
        with tempfile.TemporaryDirectory(prefix='payload-check-', dir=self.store.private) as directory:
            path = Path(directory)/'database.sqlite'
            with path.open('xb') as output: archive.copy('application/database.sqlite', output)
            path.chmod(0o600)
            references = self.payload_references(path)
        if references != json_member(archive, 'system/payloads.json'):
            raise UpdateError('The archived payload inventory differs from the actual snapshot database references.')
        validate_payloads(references, archive.manifest['members'], archive.manifest['options'])

    def restore_context(self, recovery=False):
        try:
            value = self.context()
            value.update(self.platform.context())
            value['os'] = self.context()['os']
        except UpdateError:
            if not recovery: raise
            release = 'etc/os-release' if not (self.root/'etc/os-release').is_symlink() else 'usr/lib/os-release'
            fields = dict(line.split('=', 1) for line in read(self.root, release).decode().splitlines() if '=' in line)
            value = {'cmprovision': '0', 'fpms_version': '0' if self.platform.package('fpms-lite') else None,
                'utility_selector': None, 'utility_os_version': '0', 'updater_protocol': 1,
                'php': self.command(['/usr/bin/php', '-r', 'echo PHP_VERSION;']),
                'os': {'id': fields.get('ID', '').strip('"'), 'version': fields.get('VERSION_ID', '').strip('"'),
                    'architecture': self.command(['/usr/bin/dpkg', '--print-architecture'])}}
        value['accounts'] = []
        value['recovering'] = recovery
        value['verified_packages'] = {}
        for package in ('cmprovision4', 'fpms-lite'):
            result = self.command(['/usr/bin/dpkg', '--verify', package], check=False)
            value['verified_packages'][package] = result.returncode == 0 and not result.stdout.strip()
        for line in read(self.root, 'etc/passwd').decode().splitlines():
            fields = line.split(':')
            if len(fields) == 7: value['accounts'].append({'name': fields[0], 'uid': int(fields[2]), 'gid': int(fields[3])})
        value['groups'] = {parts[0]: int(parts[2]) for parts in (line.split(':') for line in read(self.root, 'etc/group').decode().splitlines()) if len(parts) == 4}
        interfaces = json.loads(self.command(['/usr/sbin/ip', '-j', 'link', 'show']))
        value['interfaces'] = {item['ifname']: item.get('permaddr') or item['address'] for item in interfaces if item.get('address') and re.fullmatch(r'[A-Za-z0-9_.:-]{1,15}', item.get('ifname', ''))}
        return value

    def freeze_restore(self, job):
        if not Worker(self.store, self)._freeze(job['id']):
            self.store.update(job['id'], state='failed', message='Restore requires an idle station; no data was changed.')
            raise UpdateError('Wait for the station to become idle, then preview and apply again.')

    def release_restore(self, job):
        pending = self.store.configuration()['pending']
        if pending and pending['id'] == job['id']:
            self.resume(pending['services'])
            with self.admission_lock(): self.store.clear_pending(job['id'])

    def recovery_snapshot(self, archive, job, password):
        directory = self.root/'var/backups/cmprovision-full-restore'/job['id']
        directory.mkdir(parents=True, mode=0o700)
        directory.chmod(0o700)
        path = directory/'before.cmpbackup'
        stage = directory/'capture'; stage.mkdir(mode=0o700)
        self.store.update(job['id'], state='collecting', message='Creating the mandatory pre-restore recovery copy.')
        try:
            entries, metadata = self.collect(job, stage)
            create(entries, path, password, metadata)
            verify_stream(path, password, self.store.private)
            self.store.update(job['id'], recovery_file=str(path))
        finally:
            if stage.exists(): shutil.rmtree(stage)

    def _remove(self, name):
        try:
            with parent_fd(self.root, name, create=False) as (parent, leaf):
                info = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
                if not (stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode)): raise UpdateError('An absent setting has an unsafe destination.')
                os.unlink(leaf, dir_fd=parent); os.fsync(parent)
        except FileNotFoundError: pass

    def stage_restore(self, archive, plan, directory):
        stage = Path(directory)/('restore-'+uuid.uuid4().hex); stage.mkdir(mode=0o700)
        selected = set(plan['system'])
        needed = [member for member in archive.manifest['members'] if member['name'].startswith('application/')
            or member['name'] in plan['install'] or member['name'].startswith('system/') and member['name'][7:] in selected
            or member['name'].startswith('utility-os/')]
        if shutil.disk_usage(stage).free < sum(item['size'] for item in needed)+RESERVE:
            raise UpdateError('Not enough space to stage the selected restore with the 256 MiB reserve.')
        for item, source in archive.selected({item['name'] for item in needed}):
            name = item.name
            data = plan['rewritten'].get(name[7:]) if name.startswith('system/') else None
            write_file(stage, name, data if data is not None else lambda output: shutil.copyfileobj(source, output, 1024**2), item.mode)
        self._validate_configs(stage, plan)
        return stage

    def _validate_configs(self, stage, plan):
        # Test staged include/key files, rather than accidentally validating the still-live configuration.
        ssh = stage/'system/etc/ssh/sshd_config'
        if ssh.exists():
            text = ssh.read_text()
            text = re.sub(r'(?m)^(\s*(?:Include|HostKey)\s+)(/etc/ssh/[^\r\n]+)', lambda m: m[1]+str(stage/'system')+m[2], text)
            check = stage/'sshd-check.conf'; check.write_text(text); check.chmod(0o600)
            self.command(['/usr/sbin/sshd', '-t', '-f', check])
        sudo = stage/'system/etc/sudoers'
        if sudo.exists():
            text = sudo.read_text().replace('/etc/sudoers.d', str(stage/'system/etc/sudoers.d'))
            check = stage/'sudoers-check'; check.write_text(text); check.chmod(0o440)
            self.command(['/usr/sbin/visudo', '-cf', check])

    def restore_accounts(self, rows):
        context = self.restore_context(recovery=True)
        names = {row['name'] for row in context['accounts']}
        for row in rows:
            name = row['name']
            if row['primary_group'] not in context['groups']:
                self.command(['/usr/sbin/groupadd', '--gid', str(row['gid']), '--', row['primary_group']])
            if name not in names:
                self.command(['/usr/sbin/useradd', '--uid', str(row['uid']), '--gid', str(row['gid']), '--home-dir', row['home'], '--create-home', '--shell', row['shell'], '--', name])
            self.command(['/usr/sbin/usermod', '--shell', row['shell'], '--groups', ','.join(row['groups']), '--', name])
            # Hashes must never appear in process arguments or private command output.
            self.command(['/usr/sbin/chpasswd', '-e'], input=name+':'+row['password_hash']+'\n')
            aging = row['aging']
            self.command(['/usr/bin/chage', '-d', aging[0] or '-1', '-m', aging[1] or '0', '-M', aging[2] or '-1',
                '-W', aging[3] or '7', '-I', aging[4] or '-1', '-E', aging[5] or '-1', '--', name])
            home = row['home'].lstrip('/')
            with parent_fd(self.root, home+'/.ssh/authorized_keys') as (parent, leaf):
                os.fchown(parent, row['uid'], row['gid']); os.fchmod(parent, 0o700)
            write_file(self.root, home+'/.ssh/authorized_keys', row['authorized_keys'].encode(), 0o600, row['uid'], row['gid'])

    def _application(self, stage, archive):
        app = self.root/APP
        source = stage/'application'
        # Every existing DB reader has been stopped before this replacement. Root never opens SQLite.
        for suffix in ('-wal', '-shm'): self._remove(APP+'/database/database.sqlite'+suffix)
        for name in ('.env', 'etc/dnsmasq.conf', 'database.sqlite'):
            target = 'database/database.sqlite' if name == 'database.sqlite' else name
            with safe_open(source, name) as stream:
                write_file(self.root, APP+'/'+target, lambda output: shutil.copyfileobj(stream, output, 1024**2), 0o600, 33, 33)
        tree = source/'storage/app'; tree.mkdir(mode=0o755, parents=True, exist_ok=True)
        # Move the complete data tree; stale keys/scripts/runtime files must not survive restoration.
        for current, directories, files in os.walk(tree, followlinks=False):
            os.chown(current, 33, 33); os.chmod(current, 0o755)
            for leaf in files: os.chown(Path(current)/leaf, 33, 33, follow_symlinks=False)
        with parent_fd(self.root, APP+'/storage/app') as (parent, leaf):
            try:
                info = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
                if not stat.S_ISDIR(info.st_mode): raise UpdateError('Application storage is linked or unsafe.')
                os.rename(leaf, stage/'previous-storage-app', src_dir_fd=parent)
            except FileNotFoundError: pass
            os.rename(tree, leaf, dst_dir_fd=parent); os.fsync(parent)
        # Optional uploads merge only selected payloads; excluded destination payloads remain inert.
        for suffix in ('public/uploads',):
            tree = source/suffix
            if not tree.exists(): continue
            for current, directories, files in os.walk(tree, followlinks=False):
                for leaf in files:
                    path = Path(current)/leaf; name = path.relative_to(source).as_posix()
                    with safe_open(source, name) as stream:
                        write_file(self.root, APP+'/'+name, lambda output: shutil.copyfileobj(stream, output, 1024**2), path.stat().st_mode & 0o777, 33, 33)
        if self.command(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/sqlite3', app/'database/database.sqlite', 'PRAGMA quick_check;'], timeout=120) != 'ok':
            raise UpdateError('The restored database failed its integrity check.')

    def package_plan(self, paths, archive):
        expected = {}
        for path in paths:
            info = self.command(['/usr/bin/dpkg-deb', '-f', path, 'Package', 'Version', 'Architecture'])
            fields = dict(line.split(': ', 1) for line in info.splitlines() if ': ' in line)
            if fields.get('Package') not in ('cmprovision4', 'fpms-lite') or fields.get('Version') != archive.manifest['version'] or fields.get('Architecture') != 'all':
                raise UpdateError('A captured package has inconsistent identity or architecture.')
            expected[fields['Package']] = fields['Version']
        if self.command(['/usr/bin/dpkg', '--audit']):
            if not getattr(self, 'recovering', False): raise UpdateError('Resolve the existing interrupted package operation first.')
            rows = self.command(['/usr/bin/dpkg-query', '-W', '-f=${Package}\t${db:Status-Abbrev}\n']).splitlines()
            broken = set()
            for row in rows:
                parts = row.split('\t')
                if len(parts) != 2 or len(parts[1]) < 2: raise UpdateError('Could not inspect interrupted package state safely.')
                if parts[1][0] == 'i' and (parts[1][1] != 'i' or parts[1][2:].strip()): broken.add(parts[0])
            if not broken or not broken <= expected.keys(): raise UpdateError('Unrelated packages need repair over SSH before station recovery.')
        result = parse_plan(self.command(['/usr/bin/apt-get', '--simulate', '--reinstall', '--no-remove', 'install', *paths], timeout=120))
        if not all({'package': key, 'version': version} in result for key, version in expected.items()): raise UpdateError('The package restore plan has changed.')
        return result

    def apply_restore(self, archive, plan, directory):
        stage = self.stage_restore(archive, plan, directory)
        paths = [stage/name for name in plan['install'] if name.endswith('.deb')]
        if paths:
            installation = self.package_plan(paths, archive)
            self.command(['/usr/bin/apt-get', '-y', '--download-only', '--reinstall', '--no-remove', '--allow-downgrades', 'install', *paths], timeout=1800)
        pending = self.store.configuration()['pending']
        self.quiesce_restore(pending['services'])
        if paths:
            if self.package_plan(paths, archive) != installation: raise UpdateError('The package restore plan changed before installation.')
            self.command(['/usr/bin/dpkg', '--force-confold', '--unpack', *paths], timeout=600)
            for name in pending['services']: self.command(['/usr/bin/systemctl', 'stop', name])
        self._application(stage, archive)
        if paths:
            # postinst must see the matching snapshot schema, including during downgrade/recovery.
            self.command(['/usr/bin/apt-get', '-y', '--no-download', '--no-remove', '-o', 'Dpkg::Options::=--force-confold', '--fix-broken', 'install',
                *(item['package']+'='+item['version'] for item in installation)], timeout=1800)
            for name in pending['services']: self.command(['/usr/bin/systemctl', 'stop', name])
        for name in plan['remove']:
            if name not in ('etc/localtime', 'etc/timezone'): self._remove(name)
        for name in plan['system']:
            if name in ('etc/localtime', 'etc/timezone'): continue
            with safe_open(stage, 'system/'+name) as source:
                mode = 0o440 if name.startswith('etc/sudoers') else 0o600
                if name in ('etc/default/cmprovision-fpms.json', 'etc/default/cmprovision-wallboard.json',
                            'etc/systemd/timesyncd.conf') or name.startswith('etc/systemd/timesyncd.conf.d/'):
                    mode = 0o644 # Web/display publishers and the unprivileged time service read these public settings.
                target = name
                if name == 'etc/default/locale' and (self.root/name).is_symlink():
                    if os.readlink(self.root/name) not in ('../locale.conf', '/etc/locale.conf'): raise UpdateError('The destination locale alias is unsafe.')
                    target = 'etc/locale.conf'
                write_file(self.root, target, lambda output: shutil.copyfileobj(source, output, 1024**2), mode)
        self.restore_accounts(plan['accounts'])
        if 'ssh' in plan['groups']: self.command(['/usr/sbin/sshd', '-t'])
        if 'sudo' in plan['groups']: self.command(['/usr/sbin/visudo', '-c'])
        if 'time' in plan['groups']:
            settings = plan['time']
            self.command(['/usr/bin/timedatectl', 'set-timezone', settings['timezone']])
            if settings['restore_ntp']:
                self.platform.resume({'systemd-timesyncd.service': {'enabled': settings['enabled'], 'active': settings['active'] == 'active'}})
        self.command(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/php', self.app/'artisan', 'migrate', '--force'], timeout=180)
        args = ['backups:restore-finalize']
        if not all(archive.manifest['options'].values()): args.append('--deactivate-project')
        self.www(*args)
        self._restore_utility(archive, plan, stage)
        self.command(['/usr/sbin/nginx', '-t'])
        if 'network' in plan['groups']: self.command(['/usr/bin/nmcli', 'connection', 'reload'])
        if 'ssh' in plan['groups']: self.command(['/usr/bin/systemctl', 'reload', 'ssh.service'])

    def _restore_utility(self, archive, plan, stage):
        selector = archive.manifest['utility_selector']
        utility = next(item for item in archive.manifest['software'] if item.get('component') == 'utility-os')
        member = utility.get('archive')
        if member in plan['evidence']:
            from update_utility import ManagedUtility
            ManagedUtility(self.store).prepare(plan['evidence'][member], stage/member)
        elif selector in ('bundled-modern', 'legacy') or selector.startswith('managed:') and (
                getattr(self, 'recovering', False) or self.context()['utility_selector'] != selector):
            # Root explicitly trusted the snapshot tree. Publish as the package's editable bundled OS,
            # never forge a signed managed receipt for unverifiable captured files.
            label = 'managed-'+selector.split(':')[1] if selector.startswith('managed:') else selector
            base = stage/'utility-os'/label
            (base/'receipt.json').unlink(missing_ok=True)
            for current, unused, files in os.walk(base, followlinks=False):
                os.chmod(current, 0o755)
                for name in files:
                    path = Path(current)/name
                    if not path.is_file() or path.is_symlink(): raise UpdateError('Unsafe staged Utility OS file.')
                    path.chmod(0o644)
            if selector != 'legacy':
                from update_utility import verify_tree
                verify_tree(base, owner=self.store.owner)
            target = 'scriptexecute' if selector == 'legacy' else 'scriptexecute64'
            with parent_fd(self.root, APP+'/'+target) as (parent, leaf):
                try: info = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
                except FileNotFoundError: os.rename(base, leaf, dst_dir_fd=parent)
                else:
                    if not stat.S_ISDIR(info.st_mode): raise UpdateError('The destination Utility OS tree is unsafe.')
                    # Linux exchange is atomic: readers see one exact tree, including after a crash.
                    exchange = ctypes.CDLL(None, use_errno=True).renameat2
                    exchange.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
                    exchange.restype = ctypes.c_int
                    if exchange(-100, os.fsencode(base), parent, os.fsencode(leaf), 2):
                        raise UpdateError('The exact Utility OS tree could not be atomically replaced.')
                os.fsync(parent)
            if selector.startswith('managed:'): selector = 'bundled-modern'
        self.platform.activate_utility(selector)

    def finish_restore(self, archive, plan, job):
        imported = restore_backup_configuration(json_member(archive, 'system/backup-config.json'), now=self.store.clock())
        def reset(state):
            state.update(**imported, master_password=None, slots=[], remote_copies=[], last_success_at=None)
        self.store.mutate(reset)
        if plan['mode'] == 'migrate': self.store.rotate_station_identity()
        pending = self.store.configuration()['pending']
        services = pending['services']
        if plan['restore_role']:
            saved = json_member(archive, 'system/service-state.json')
            for name, state in saved.items():
                if name not in services or type(state) is not dict or set(state) != {'active', 'enabled'} or type(state['active']) is not bool or state['enabled'] not in ('enabled', 'enabled-runtime', 'disabled', 'static', 'indirect', 'masked', 'masked-runtime'):
                    raise UpdateError('The saved station service role is incompatible with installed services.')
            services = dict(services, **saved)
        self.platform.resume(services, boot=False)
        self.platform.health({'version': archive.manifest['version'], 'component': 'application'}, services)
        self.platform.utility_health(self.context()['utility_selector'])
        self.resume({key: state for key, state in services.items() if key in BOOT_SERVICES})
        with self.admission_lock(): self.store.clear_pending(job['id'])
