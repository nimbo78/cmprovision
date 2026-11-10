"""Privileged restore effects, separately injectable for disposable integration tests."""
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import uuid

from backup_archive import RESERVE, create, verify_stream
from backup_policy import APP, read, safe_open
from backup_restore import file_group, json_member, parent_fd, write_file
from backup_system import LinuxBackupSystem, RUNTIME_TABLES
from backup_worker import Worker
from update_manifest import UpdateError, canonical_bytes
from update_system import BOOT_SERVICES, parse_plan


class LinuxRestoreSystem(LinuxBackupSystem):
    def restore_context(self):
        value = self.context()
        value.update(self.platform.context())
        value['os'] = self.context()['os']
        value['accounts'] = []
        for line in read(self.root, 'etc/passwd').decode().splitlines():
            fields = line.split(':')
            if len(fields) == 7: value['accounts'].append({'name': fields[0], 'uid': int(fields[2]), 'gid': int(fields[3])})
        value['groups'] = {parts[0]: int(parts[2]) for parts in (line.split(':') for line in read(self.root, 'etc/group').decode().splitlines()) if len(parts) == 4}
        interfaces = json.loads(self.command(['/usr/sbin/ip', '-j', 'link', 'show']))
        value['interfaces'] = {item['ifname']: item['address'] for item in interfaces if item.get('address') and re.fullmatch(r'[A-Za-z0-9_.:-]{1,15}', item.get('ifname', ''))}
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
                if not stat.S_ISREG(info.st_mode): raise UpdateError('An absent setting has an unsafe destination.')
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
        for item in needed:
            name = item['name']
            data = plan['rewritten'].get(name[7:]) if name.startswith('system/') else None
            write_file(stage, name, data if data is not None else lambda output, key=name: archive.copy(key, output), item['mode'])
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
        context = self.restore_context()
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
        if self.command(['/usr/bin/dpkg', '--audit']): raise UpdateError('Resolve the existing interrupted package operation first.')
        result = parse_plan(self.command(['/usr/bin/apt-get', '--simulate', '--no-remove', 'install', *paths], timeout=120))
        if not all({'package': key, 'version': version} in result for key, version in expected.items()): raise UpdateError('The package restore plan has changed.')
        return result

    def apply_restore(self, archive, plan, directory):
        stage = self.stage_restore(archive, plan, directory)
        paths = [stage/name for name in plan['install'] if name.endswith('.deb')]
        if paths:
            installation = self.package_plan(paths, archive)
            self.platform.prefetch(paths, installation)
        pending = self.store.configuration()['pending']
        for name in pending['services']:
            if name not in BOOT_SERVICES: self.command(['/usr/bin/systemctl', 'stop', name])
        if paths:
            if self.package_plan(paths, archive) != installation: raise UpdateError('The package restore plan changed before installation.')
            self.command(['/usr/bin/dpkg', '--force-confold', '--unpack', *paths], timeout=600)
            self.command(['/usr/bin/apt-get', '-y', '--no-download', '--no-remove', '-o', 'Dpkg::Options::=--force-confold', '--fix-broken', 'install',
                *(item['package']+'='+item['version'] for item in installation)], timeout=1800)
            for name in pending['services']: self.command(['/usr/bin/systemctl', 'stop', name])
        self._application(stage, archive)
        for name in plan['remove']:
            if name not in ('etc/localtime', 'etc/timezone'): self._remove(name)
        for name in plan['system']:
            if name in ('etc/localtime', 'etc/timezone'): continue
            with safe_open(stage, 'system/'+name) as source:
                mode = 0o440 if name.startswith('etc/sudoers') else 0o600
                write_file(self.root, name, lambda output: shutil.copyfileobj(source, output, 1024**2), mode)
        self.restore_accounts(plan['accounts'])
        if 'ssh' in plan['groups']: self.command(['/usr/sbin/sshd', '-t'])
        if 'sudo' in plan['groups']: self.command(['/usr/sbin/visudo', '-c'])
        if 'time' in plan['groups']:
            from zoneinfo import ZoneInfo
            settings = json_member(archive, 'system/time.json')
            try: ZoneInfo(settings['timezone'])
            except (KeyError, ValueError) as error: raise UpdateError('Invalid saved time zone.') from error
            self.command(['/usr/bin/timedatectl', 'set-timezone', settings['timezone']])
            if settings['enabled'] not in ('enabled', 'disabled', 'masked', 'masked-runtime', 'static') or settings['active'] not in ('active', 'inactive', 'failed'):
                raise UpdateError('Invalid saved synchronization service state.')
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
        elif selector.startswith('managed:') and self.context()['utility_selector'] != selector:
            # Root explicitly trusted the snapshot tree. Publish as the package's editable bundled OS,
            # never forge a signed managed receipt for unverifiable captured files.
            label = 'managed-'+selector.split(':')[1]
            base = stage/'utility-os'/label
            for current, unused, files in os.walk(base):
                for name in files:
                    path = Path(current)/name
                    if path.relative_to(base).as_posix() == 'receipt.json': continue
                    relative = path.relative_to(base).as_posix()
                    with safe_open(base, relative) as source:
                        write_file(self.root, APP+'/scriptexecute64/'+relative, lambda output: shutil.copyfileobj(source, output, 1024**2), 0o644)
            selector = 'bundled-modern'
        self.platform.activate_utility(selector)

    def finish_restore(self, archive, plan, job):
        imported = json_member(archive, 'system/backup-config.json')
        from backup_remote import validate_profile
        from backup_schedule import validate_schedule
        if imported.get('schema') != 1 or type(imported.get('profiles')) is not dict or len(imported['profiles']) > 8:
            raise UpdateError('Invalid saved backup configuration.')
        profiles = {key: validate_profile(value) for key, value in imported['profiles'].items()}
        schedule = validate_schedule(dict(imported['schedule'], enabled=False), profiles, self.store.clock())
        def reset(state):
            state.update(profiles=profiles, schedule=schedule, master_password=None, slots=[], remote_copies=[], last_success_at=None)
            if plan['mode'] == 'migrate': state['station_id'] = uuid.uuid4().hex
        self.store.mutate(reset)
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
