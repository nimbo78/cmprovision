"""Typed backup broker; public callers cannot select local paths, programs or rclone options."""
import json
import subprocess

from backup_remote import _host
from backup_schedule import next_run
from update_manifest import UpdateError
from update_store import ID_RE, NONCE_RE, matches


def decode_request(raw):
    def unique(items):
        value = {}
        for key, item in items:
            if key in value: raise UpdateError('Duplicate backup request fields are not allowed.')
            value[key] = item
        return value
    try:
        if len(raw) > 196608: raise UpdateError('The backup request exceeds its size limit.')
        value = json.loads(raw, object_pairs_hook=unique, parse_constant=lambda _: (_ for _ in ()).throw(UpdateError('Invalid JSON number.')))
    except (ValueError, RecursionError, UnicodeError) as error: raise UpdateError('Invalid backup request JSON.') from error
    schemas = {'status': set(), 'password': {'password'}, 'save-profile': {'profile'}, 'delete-profile': {'id'},
        'schedule': {'schedule'}, 'create': {'nonce', 'target', 'options'}, 'test-profile': {'nonce', 'id'},
        'host-key': {'nonce', 'host', 'port'}, 'cancel': {'id'}, 'download-info': {'id'}, 'download': {'id'}}
    if type(value) is not dict or type(value.get('action')) is not str or value['action'] not in schemas or set(value) != schemas[value['action']] | {'action'}:
        raise UpdateError('Invalid backup request fields.')
    if 'id' in value and not matches(ID_RE, value['id']): raise UpdateError('Invalid backup operation/destination identifier.')
    if 'nonce' in value and not matches(NONCE_RE, value['nonce']): raise UpdateError('Invalid backup request nonce.')
    action = value['action']
    if action == 'password' and type(value['password']) is not str: raise UpdateError('Invalid master password.')
    if action == 'save-profile' and type(value['profile']) is not dict: raise UpdateError('Invalid backup destination.')
    if action == 'schedule' and type(value['schedule']) is not dict: raise UpdateError('Invalid backup schedule.')
    if action == 'create':
        if not (value['target'] == 'download' or matches(ID_RE, value['target'])): raise UpdateError('Invalid backup destination.')
        options = value['options']
        if type(options) is not dict or set(options) != {'images', 'packages'} or any(type(v) is not bool for v in options.values()):
            raise UpdateError('Invalid backup contents selection.')
    if action == 'host-key':
        if type(value['host']) is not str or type(value['port']) is not int or not 1 <= value['port'] <= 65535:
            raise UpdateError('Invalid SFTP host or port.')
        _host(value['host'])
    return value


def launch(identity):
    if not matches(ID_RE, identity): raise UpdateError('Invalid backup worker identifier.')
    try:
        result = subprocess.run(['/usr/bin/systemd-run', '--quiet', '--collect', '--unit=cmprovision-backup-'+identity,
            '--property=Type=exec', '--property=Nice=15', '--property=IOSchedulingClass=idle',
            '--property=RuntimeMaxSec=2h', '--property=TimeoutStopSec=30s', '--',
            '/usr/bin/python3', '-I', '/usr/lib/cmprovision/station-backups.py', '--worker', identity],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=8)
        if result.returncode: raise UpdateError('Backup worker start was not confirmed. Reload status before trying again.')
    except (OSError, subprocess.SubprocessError) as error: raise UpdateError('Backup worker start is uncertain. Reload status before trying again.') from error


class Broker:
    def __init__(self, store, launcher=launch, timezone=lambda: 'UTC'):
        self.store, self.launcher, self.timezone = store, launcher, timezone

    def status(self):
        result = self.store.status()
        result['timezone'] = self.timezone()
        result['next_run_at'] = next_run(result['schedule'], self.store.clock(), result['timezone'])
        return result

    def request(self, value):
        action = value['action']
        if action == 'status': return self.status()
        if action == 'password': return self.store.configure_password(value['password'])
        if action == 'save-profile': return self.store.save_profile(value['profile'])
        if action == 'delete-profile': return self.store.delete_profile(value['id'])
        if action == 'schedule': return self.store.save_schedule(value['schedule'], self.timezone())
        if action == 'cancel': return self.store.cancel(value['id'])
        if action in ('download-info', 'download'):
            self.store.download_file(value['id'])
            job = self.store.operation(value['id'])
            return {'id': job['id'], 'name': job['name'], 'bytes': job['bytes']}
        empty = {'images': False, 'packages': False}
        if action == 'create':
            job, created = self.store.begin(value['nonce'], value['target'], value['options'])
        elif action == 'test-profile':
            job, created = self.store.begin(value['nonce'], value['id'], empty, action='test')
        elif action == 'host-key':
            job, created = self.store.begin(value['nonce'], 'download', empty, action='host-key',
                arguments={'host': value['host'], 'port': value['port']})
        else: raise UpdateError('Unsupported backup action.')
        if created:
            try: self.launcher(job['id'])
            except UpdateError: return dict(self.status(), id=job['id'], launch_uncertain=True)
        return dict(self.status(), id=job['id'])


def reconcile(store, system, launcher=launch):
    """Timer/status repair never replays an interrupted snapshot or upload."""
    state = store.configuration()
    job = state['operations'].get(state['current'])
    if not job or store.clock()-job['updated_at'] < 30: return
    unit = system.command(['/usr/bin/systemctl', 'is-active', 'cmprovision-backup-'+job['id']], check=False, timeout=5)
    if unit.stdout.strip() in ('active', 'activating', 'deactivating'): return
    if state['pending']:
        try:
            system.command(['/usr/bin/systemd-run', '--quiet', '--collect', '--unit=cmprovision-backup-recovery', '--',
                '/usr/bin/python3', '-I', '/usr/lib/cmprovision/station-backups.py', '--recover'], timeout=8)
        except UpdateError: pass # An existing recovery unit or a lost reply never repeats a snapshot.
    elif job['state'] in ('queued', 'waiting'):
        try: launcher(job['id'])
        except UpdateError: pass
    elif job['state'] not in ('ready', 'succeeded', 'failed', 'canceled', 'recovery-required'):
        with store.lock('worker', blocking=False):
            store.update(job['id'], state='failed', message='The backup worker was interrupted. Create a fresh backup; no upload was repeated.')


def tick(store, system, launcher=launch):
    from backup_schedule import due
    import hashlib
    reconcile(store, system, launcher)
    try: system.sweep()
    except UpdateError: pass
    state = store.configuration()
    current = state['operations'].get(state['current'])
    if state['pending'] or current and current['state'] not in ('ready', 'succeeded', 'failed', 'canceled'): return
    zone = system.timezone()
    if state.get('schedule_timezone') != zone:
        def changed(value):
            if value.get('schedule_timezone') is not None: value['schedule']['not_before'] = int(store.clock())
            value['schedule_timezone'] = zone
        store.mutate(changed)
        state = store.configuration()
    slot = due(state['schedule'], state['slots'], store.clock(), zone)
    if not slot: return
    schedule = state['schedule']
    options = {key: schedule[key] for key in ('images', 'packages')}
    nonce = hashlib.sha256(('schedule:'+slot).encode()).hexdigest()
    job, created = store.begin(nonce, schedule['profile'], options, scheduled=True, slot=slot)
    if created: launcher(job['id'])
