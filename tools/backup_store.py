"""Root-private backup state, idempotent jobs and a deliberately narrow public projection."""
import copy
from contextlib import contextmanager, ExitStack
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import time
import uuid

from update_manifest import UpdateError, canonical_bytes
from update_store import UpdateStore, ID_RE, NONCE_RE, matches, safe_text

TERMINAL = frozenset(('ready', 'succeeded', 'failed', 'canceled'))
STATES = TERMINAL | {'queued', 'waiting', 'collecting', 'archiving', 'uploading', 'verifying', 'recovery-required'}
SECRETS = ('password', 'key_pem', 'key_password', 'secret_key', 'access_key', 'session_token', 'ca_pem')


class BackupBusy(UpdateError):
    """Admission is busy; retry later without pausing services."""


class BackupStore(UpdateStore):
    # Reuse the established no-follow/ownership/atomic-write primitives only. Backup schema is independent.
    def __init__(self, root='/var/lib/cmprovision-backups', runtime='/run/cmprovision-backups', owner=0, clock=time.time):
        self.root, self.runtime, self.owner, self.clock = Path(root), Path(runtime), owner, clock
        self.private, self.public = self.root/'private', self.root/'public'
        self.state_file, self.pending_file = self.private/'state.json', self.runtime/'pending.json'
        self.projection_error = None
        for path, mode in ((self.root, 0o755), (self.private, 0o700), (self.public, 0o755), (self.runtime, 0o755), (self.private/'jobs', 0o700)):
            self._directory(path, mode)
        with self.lock('control'):
            if not self.state_file.exists() and not self.state_file.is_symlink():
                if self.pending_file.exists() or self.pending_file.is_symlink(): raise UpdateError('Backup recovery state is missing.')
                self._persist({'schema': 1, 'station_id': uuid.uuid4().hex, 'master_password': None,
                    'profiles': {}, 'schedule': {'enabled': False, 'frequency': 'daily', 'time': '03:00', 'weekday': 0,
                        'profile': None, 'keep': 1, 'images': False, 'packages': False},
                    'slots': [], 'operations': {}, 'requests': {}, 'current': None, 'pending': None,
                    'sequence': 0, 'remote_copies': [], 'last_success_at': None})

    def _load(self):
        self._file(self.state_file, 0o600)
        fd = os.open(self.state_file, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
        with os.fdopen(fd, 'rb') as stream: raw = stream.read(4*1024**2+1)
        try: value = json.loads(raw) if len(raw) <= 4*1024**2 else None
        except (ValueError, RecursionError) as error: raise UpdateError('Backup state is unreadable; recover over SSH.') from error
        if (type(value) is not dict or value.get('schema') != 1 or not matches(ID_RE, value.get('station_id'))
                or any(type(value.get(key)) is not dict for key in ('profiles', 'schedule', 'operations', 'requests'))):
            raise UpdateError('Backup state is invalid; recover over SSH.')
        # Read-time migration also covers profiles/schedules saved by older versions.
        # No credentials, enable switches or active job snapshots are rewritten.
        value['schedule']['keep'] = 1
        for profile in value['profiles'].values():
            if type(profile) is not dict: raise UpdateError('Backup destination state is invalid.')
            profile['keep'] = 1
        return value

    @staticmethod
    def _job_public(job):
        allowed = {'id', 'target', 'state', 'message', 'created_at', 'updated_at', 'options', 'bytes', 'total',
                   'name', 'sha256', 'expires_at', 'cancel_requested', 'scheduled', 'warning', 'slot', 'action', 'host_keys'}
        return {key: copy.deepcopy(value) for key, value in job.items() if key in allowed}

    def _projection(self, state):
        profiles = []
        for value in state['profiles'].values():
            public = {key: copy.deepcopy(item) for key, item in value.items() if key not in SECRETS}
            public.update({'has_'+key: bool(value.get(key)) for key in SECRETS})
            profiles.append(public)
        jobs = sorted(state['operations'].values(), key=lambda item: item.get('sequence', item['created_at']), reverse=True)
        return {'schema': 1, 'station_id': state['station_id'], 'password_configured': bool(state['master_password']),
            'profiles': profiles, 'schedule': copy.deepcopy(state['schedule']),
            'pending': {'id': state['pending']['id'], 'at': state['pending']['at']} if state['pending'] else None,
            'operation': self._job_public(state['operations'][state['current']]) if state['current'] else None,
            'history': [self._job_public(job) for job in jobs[:50]], 'last_success_at': state.get('last_success_at')}

    def _persist(self, state):
        self._atomic(self.state_file, state, 0o600)
        try: self._atomic(self.public/'status.json', self._projection(state), 0o644)
        except (UpdateError, OSError): self.projection_error = 'Backup status could not be refreshed.'

    def mutate(self, action):
        with self.lock('control'):
            state = self._load()
            result = action(state)
            self._persist(state)
            return copy.deepcopy(result)

    def status(self): return self._projection(self._load())
    def snapshot(self): return self.status()

    @contextmanager
    def idle_worker(self):
        with ExitStack() as locks:
            with self.lock('control'):
                self.idle(self._load())
                locks.enter_context(self.lock('worker', blocking=False))
            yield

    def configuration(self): return copy.deepcopy(self._load())
    def export_configuration(self):
        state = self._load()
        return {'schema': 1, 'profiles': copy.deepcopy(state['profiles']), 'schedule': dict(state['schedule'], enabled=False)}

    @staticmethod
    def idle(state):
        current = state['operations'].get(state['current'])
        if state['pending'] or current and current['state'] not in TERMINAL:
            raise UpdateError('Wait for the current backup to finish before changing backup settings.')

    def configure_password(self, password):
        if type(password) is not str or len(password) < 12 or len(password.encode('utf-8')) > 4096:
            raise UpdateError('Use a master password of at least 12 characters and at most 4096 bytes.')
        def change(state):
            self.idle(state)
            state['master_password'] = password
        self.mutate(change)
        return self.status()

    def begin(self, nonce, target, options, scheduled=False, slot=None, action='backup', arguments=None):
        if not matches(NONCE_RE, nonce) or not (target == 'download' or matches(ID_RE, target)):
            raise UpdateError('Invalid backup request identity or destination.')
        if type(options) is not dict or set(options) != {'images', 'packages'} or any(type(v) is not bool for v in options.values()):
            raise UpdateError('Invalid backup contents selection.')
        if action not in ('backup', 'test', 'host-key', 'restore'): raise UpdateError('Invalid backup worker action.')
        digest = hashlib.sha256(canonical_bytes({'action': action, 'target': target, 'options': options, 'arguments': arguments})).hexdigest()
        with self.lock('control'):
            existing = self._load()
            if nonce in existing['requests']:
                previous = existing['requests'][nonce]
                if previous['digest'] != digest: raise UpdateError('Backup request was replayed with changed choices.')
                if previous['id'] not in existing['operations']: raise UpdateError('The replayed backup has expired.')
                return copy.deepcopy(existing['operations'][previous['id']]), False
        def change(state):
            if nonce in state['requests']:
                previous = state['requests'][nonce]
                if previous['digest'] != digest: raise UpdateError('Backup request was replayed with changed choices.')
                if previous['id'] not in state['operations']: raise UpdateError('The replayed backup has expired.')
                return state['operations'][previous['id']], False
            self.idle(state)
            if action == 'backup' and not state['master_password']: raise UpdateError('Set the master password before creating a full backup.')
            if target != 'download' and target not in state['profiles']: raise UpdateError('Choose a saved backup destination.')
            identity = uuid.uuid4().hex
            state['sequence'] = state.get('sequence', 0)+1
            job = {'id': identity, 'action': action, 'sequence': state['sequence'], 'target': target, 'options': options, 'state': 'queued', 'bytes': 0, 'total': 0,
                'created_at': int(self.clock()), 'updated_at': int(self.clock()), 'message': 'Backup queued.',
                'cancel_requested': False, 'scheduled': bool(scheduled), 'slot': slot,
                'snapshot': {'master_password': state['master_password'], 'station_id': state['station_id'],
                    'profile': copy.deepcopy(state['profiles'].get(target)), 'config': self._configuration_export(state), 'arguments': arguments}}
            state['operations'][identity] = job
            state['requests'][nonce] = {'digest': digest, 'id': identity}
            state['current'] = identity
            if slot:
                state['slots'] = (state['slots']+[slot])[-400:]
            ordered = sorted(state['operations'].values(), key=lambda item: item.get('sequence', 0))
            for old in ordered[:-50]:
                if old['state'] in TERMINAL: state['operations'].pop(old['id'])
            state['requests'] = dict(list(state['requests'].items())[-4096:])
            return job, True
        with self.lock('worker', blocking=False): return self.mutate(change)

    @staticmethod
    def _configuration_export(state):
        return {'schema': 1, 'profiles': copy.deepcopy(state['profiles']), 'schedule': dict(state['schedule'], enabled=False)}

    def operation(self, identity):
        if not matches(ID_RE, identity): raise UpdateError('Invalid backup operation identifier.')
        job = self._load()['operations'].get(identity)
        if not job: raise UpdateError('Backup operation was not found or has expired.')
        return copy.deepcopy(job)

    def update(self, identity, **fields):
        if 'state' in fields and fields['state'] not in STATES: raise UpdateError('Invalid backup state.')
        for name in ('bytes', 'total'):
            if name in fields and (type(fields[name]) is not int or not 0 <= fields[name] <= 2**50):
                raise UpdateError('Invalid backup progress.')
        for name in ('message', 'warning'):
            if name in fields: fields[name] = safe_text(fields[name])
        def change(state):
            job = state['operations'].get(identity)
            if not job: raise UpdateError('Backup operation was not found.')
            if fields.get('committed') and job['cancel_requested']:
                raise UpdateError('Backup canceled before commit.')
            job.update(fields, updated_at=int(self.clock()))
            if fields.get('committed') and job['scheduled']:
                from backup_schedule import due
                slot = due(state['schedule'], [], job.get('captured_at', job['created_at']), state.get('schedule_timezone', 'UTC'))
                if slot and slot not in state['slots']: state['slots'] = (state['slots']+[slot])[-400:]
            if job['state'] == 'succeeded' and job['action'] == 'backup': state['last_success_at'] = job.get('committed_at', int(self.clock()))
            if job['state'] in TERMINAL: job.pop('snapshot', None)
            return job
        return self.mutate(change)

    def save_profile(self, value):
        from backup_remote import validate_profile
        def change(state):
            self.idle(state)
            previous = state['profiles'].get(value.get('id')) if type(value.get('id')) is str else None
            profile = validate_profile(value, previous)
            if not previous and len(state['profiles']) >= 8: raise UpdateError('At most eight backup destinations are supported.')
            state['profiles'][profile['id']] = profile
            if state['schedule']['profile'] == profile['id']: state['schedule']['keep'] = profile['keep']
        self.mutate(change)
        return self.status()

    def delete_profile(self, identity):
        if not matches(ID_RE, identity): raise UpdateError('Invalid backup destination identifier.')
        def change(state):
            self.idle(state)
            if identity not in state['profiles']: raise UpdateError('Backup destination was not found.')
            state['profiles'].pop(identity)
            if state['schedule']['profile'] == identity: state['schedule'].update(enabled=False, profile=None)
        self.mutate(change)
        return self.status()

    def save_schedule(self, value, timezone=None):
        from backup_schedule import validate_schedule
        def change(state):
            self.idle(state)
            if value.get('enabled') and not state['master_password']: raise UpdateError('Set a master password before enabling scheduled backups.')
            schedule = validate_schedule(value, state['profiles'], self.clock())
            state['schedule'] = schedule
            if timezone is not None: state['schedule_timezone'] = timezone
            if schedule['profile'] in state['profiles']: state['profiles'][schedule['profile']]['keep'] = schedule['keep']
        self.mutate(change)
        return self.status()

    def cancel(self, identity):
        self.operation(identity)
        def change(state):
            job = state['operations'][identity]
            if job['state'] in TERMINAL: return
            if job['action'] == 'restore': raise UpdateError('Administrator restores must be recovered over SSH; they cannot be canceled in the browser.')
            if job.get('committed'): raise UpdateError('This backup is already complete; station cleanup is finishing.')
            job['cancel_requested'] = True
            job['message'] = 'Cancellation requested. A network operation can take up to 60 seconds to stop.'
        self.mutate(change)
        return self.status()

    def rotate_station_identity(self):
        def change(state):
            # Atomic root-private lineage lets the sweeper recognize our pre-migration artifacts
            # even after history eviction; never adopt arbitrary owner markers on disk.
            known = state.get('artifact_station_ids', [])
            if state['station_id'] not in known: known.append(state['station_id'])
            state.update(artifact_station_ids=known, station_id=uuid.uuid4().hex)
        self.mutate(change)

    def mark_pending(self, identity, services):
        def change(state):
            if identity != state['current'] or state['pending']: raise UpdateError('Another backup already holds maintenance.')
            state['pending'] = {'schema': 1, 'id': identity, 'at': int(self.clock()), 'services': copy.deepcopy(services)}
            return state['pending']
        pending = self.mutate(change)
        self._atomic(self.root/'boot-blocked', {'id': identity}, 0o644)
        self._atomic(self.pending_file, {'schema': 1, 'id': identity, 'at': pending['at']}, 0o644)

    def allow_boot(self):
        path = self.root/'boot-blocked'
        if path.exists() or path.is_symlink():
            self._file(path, 0o644)
            pending = self._load()['pending']
            if not pending or json.loads(path.read_bytes()) != {'id': pending['id']}:
                raise UpdateError('The durable backup boot barrier does not match recovery state.')
            path.unlink()
            descriptor = os.open(self.root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try: os.fsync(descriptor)
            finally: os.close(descriptor)

    def clear_pending(self, identity):
        with self.lock('control'):
            state = self._load()
            if state['pending'] and state['pending']['id'] != identity: raise UpdateError('A different backup owns maintenance.')
            self.allow_boot()
            if self.pending_file.exists() or self.pending_file.is_symlink():
                self._file(self.pending_file, 0o644); self.pending_file.unlink()
            state['pending'] = None
            self._persist(state)

    def record_remote(self, identity, receipt):
        def change(state):
            job = state['operations'][identity]
            value = {key: receipt[key] for key in ('name', 'size', 'sha256', 'station_id', 'namespace')}
            value.update(id=identity, created_at=job.get('captured_at', job['created_at']), sequence=job['sequence'])
            if not any(item['id'] == identity for item in state['remote_copies']): state['remote_copies'].append(value)
        self.mutate(change)

    def forget_remote(self, identities):
        def change(state): state['remote_copies'] = [item for item in state['remote_copies'] if item['id'] not in identities]
        self.mutate(change)

    def job_dir(self, identity):
        job = self.operation(identity)
        directory = self.private/'jobs'/identity
        self._directory(directory, 0o700)
        marker = directory/'owner.json'
        if not marker.exists() and not marker.is_symlink():
            self._atomic(marker, {'schema': 1, 'id': identity, 'station_id': self._load()['station_id'], 'created_at': job['created_at']}, 0o600)
        else: self._file(marker, 0o600)
        return directory

    def download_file(self, identity):
        job = self.operation(identity)
        if job['target'] != 'download' or job['state'] != 'ready' or self.clock() >= job.get('expires_at', 0):
            raise UpdateError('This backup download is not ready or has expired.')
        path = self.job_dir(identity)/'backup.cmpbackup'
        try:
            info = self._file(path, 0o600)
            if info.st_nlink != 1 or info.st_size != job['bytes']: raise UpdateError('Backup download file is inconsistent.')
        except OSError as error: raise UpdateError('Backup download file is no longer available.') from error
        return path
