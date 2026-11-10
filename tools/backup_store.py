"""Root-private backup state, idempotent jobs and a deliberately narrow public projection."""
import copy
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
SECRETS = ('password', 'key_pem', 'secret_key', 'access_key', 'session_token', 'ca_pem')


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
                        'profile': None, 'keep': 7, 'images': False, 'packages': False},
                    'slots': [], 'operations': {}, 'requests': {}, 'current': None, 'pending': None})

    def _load(self):
        self._file(self.state_file, 0o600)
        fd = os.open(self.state_file, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
        with os.fdopen(fd, 'rb') as stream: raw = stream.read(4*1024**2+1)
        try: value = json.loads(raw) if len(raw) <= 4*1024**2 else None
        except (ValueError, RecursionError) as error: raise UpdateError('Backup state is unreadable; recover over SSH.') from error
        if (type(value) is not dict or value.get('schema') != 1 or not matches(ID_RE, value.get('station_id'))
                or any(type(value.get(key)) is not dict for key in ('profiles', 'schedule', 'operations', 'requests'))):
            raise UpdateError('Backup state is invalid; recover over SSH.')
        return value

    @staticmethod
    def _job_public(job):
        allowed = {'id', 'target', 'state', 'message', 'created_at', 'updated_at', 'options', 'bytes', 'total',
                   'name', 'sha256', 'expires_at', 'cancel_requested', 'scheduled', 'warning', 'slot'}
        return {key: copy.deepcopy(value) for key, value in job.items() if key in allowed}

    def _projection(self, state):
        profiles = []
        for value in state['profiles'].values():
            public = {key: copy.deepcopy(item) for key, item in value.items() if key not in SECRETS}
            public.update({'has_'+key: bool(value.get(key)) for key in SECRETS})
            profiles.append(public)
        jobs = sorted(state['operations'].values(), key=lambda item: item['created_at'], reverse=True)
        return {'schema': 1, 'station_id': state['station_id'], 'password_configured': bool(state['master_password']),
            'profiles': profiles, 'schedule': copy.deepcopy(state['schedule']), 'pending': copy.deepcopy(state['pending']),
            'operation': self._job_public(state['operations'][state['current']]) if state['current'] else None,
            'history': [self._job_public(job) for job in jobs[:50]], 'last_success_at': max(
                (job['updated_at'] for job in jobs if job['state'] == 'succeeded'), default=None)}

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

    def begin(self, nonce, target, options, scheduled=False, slot=None):
        if not matches(NONCE_RE, nonce) or not (target == 'download' or matches(ID_RE, target)):
            raise UpdateError('Invalid backup request identity or destination.')
        if type(options) is not dict or set(options) != {'images', 'packages'} or any(type(v) is not bool for v in options.values()):
            raise UpdateError('Invalid backup contents selection.')
        digest = hashlib.sha256(canonical_bytes({'target': target, 'options': options})).hexdigest()
        def change(state):
            if nonce in state['requests']:
                previous = state['requests'][nonce]
                if previous['digest'] != digest: raise UpdateError('Backup request was replayed with changed choices.')
                if previous['id'] not in state['operations']: raise UpdateError('The replayed backup has expired.')
                return state['operations'][previous['id']], False
            self.idle(state)
            if not state['master_password']: raise UpdateError('Set the master password before creating a full backup.')
            if target != 'download' and target not in state['profiles']: raise UpdateError('Choose a saved backup destination.')
            identity = uuid.uuid4().hex
            job = {'id': identity, 'target': target, 'options': options, 'state': 'queued', 'bytes': 0, 'total': 0,
                'created_at': int(self.clock()), 'updated_at': int(self.clock()), 'message': 'Backup queued.',
                'cancel_requested': False, 'scheduled': bool(scheduled), 'slot': slot,
                'snapshot': {'master_password': state['master_password'], 'station_id': state['station_id'],
                    'profile': copy.deepcopy(state['profiles'].get(target)), 'config': self._configuration_export(state)}}
            state['operations'][identity] = job
            state['requests'][nonce] = {'digest': digest, 'id': identity}
            state['current'] = identity
            if slot:
                state['slots'] = (state['slots']+[slot])[-400:]
            for old in list(state['operations'])[:-50]:
                if state['operations'][old]['state'] in TERMINAL: state['operations'].pop(old)
            state['requests'] = dict(list(state['requests'].items())[-4096:])
            return job, True
        return self.mutate(change)

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
            job.update(fields, updated_at=int(self.clock()))
            if job['state'] in TERMINAL: job.pop('snapshot', None)
            return job
        return self.mutate(change)

    def job_dir(self, identity):
        self.operation(identity)
        directory = self.private/'jobs'/identity
        self._directory(directory, 0o700)
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
