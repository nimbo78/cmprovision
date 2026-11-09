"""Root-owned durable updater state. Web-visible data is a bounded, read-only projection."""
import base64
from contextlib import contextmanager
import copy
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import time
import uuid

from update_manifest import COMPONENTS, SHA_RE, UOS_VERSION_RE, UpdateError, canonical_bytes

STATES = frozenset(('queued', 'checking', 'downloading', 'verifying', 'ready', 'preparing', 'applying',
                    'reconnecting', 'succeeded', 'rolled-back', 'failed', 'canceled', 'unknown', 'recovery-required'))
TERMINAL = frozenset(('ready', 'succeeded', 'rolled-back', 'failed', 'canceled'))
MAX_STATE = 4*1024*1024
ID_RE = r'[0-9a-f]{32}'
NONCE_RE = r'[0-9a-f]{64}'


def matches(pattern, value):
    return type(value) is str and re.fullmatch(pattern, value) is not None


def safe_text(value, limit=600):
    value = str(value).encode('utf-8', 'replace').decode('utf-8')
    value = re.sub(r'\x1b\[[0-?]*[ -/]*[@-~]', '', value)
    value = re.sub(r'[\x00-\x1f\x7f]', ' ', value)
    value = re.sub(r'(?i)(https?://)[^/\s@]+@', r'\1[redacted]@', value)
    value = re.sub(r'(?i)\b(password|token|secret|authorization|app_key)\s*[=:]\s*\S+', r'\1=[redacted]', value)
    return value[:limit]


def validate_request(request):
    if type(request) is not dict or not matches(NONCE_RE, request.get('nonce')):
        raise UpdateError('Invalid update request nonce.')
    action = request.get('action')
    fields = {
        'check': {'action', 'nonce', 'components'},
        'download': {'action', 'nonce', 'component', 'tag'},
        'apply': {'action', 'nonce', 'candidate', 'receipt'},
        'activate': {'action', 'nonce', 'selector'},
        'rollback': {'action', 'nonce', 'operation'},
    }
    if type(action) is not str or action not in fields or set(request) != fields[action]:
        raise UpdateError('Invalid update request fields.')
    if action == 'check':
        items = request['components']
        if (type(items) is not list or not 0 < len(items) <= 2 or not all(type(item) is str for item in items)
                or len(set(items)) != len(items) or not set(items) <= set(COMPONENTS)):
            raise UpdateError('Invalid update components.')
    if action == 'download':
        if request['component'] not in COMPONENTS or not matches(r'(?:v[0-9]+\.[0-9]+\.[0-9]+|utility-os-'+UOS_VERSION_RE+r')', request['tag']):
            raise UpdateError('Invalid update release selection.')
        if request['tag'].startswith('v') != (request['component'] == 'application'):
            raise UpdateError('Update component and tag do not match.')
    if action == 'apply' and (not matches(SHA_RE, request['candidate']) or not matches(SHA_RE, request['receipt'])):
        raise UpdateError('Invalid prepared update identifier.')
    if action == 'rollback' and not matches(ID_RE, request['operation']):
        raise UpdateError('Invalid recovery operation identifier.')
    if action == 'activate' and not (request['selector'] in ('legacy', 'bundled-modern')
                                    or matches(r'managed:'+UOS_VERSION_RE, request['selector'])):
        raise UpdateError('Invalid Utility OS selection.')
    return request


class UpdateStore:
    def __init__(self, root='/var/lib/cmprovision-updates', runtime='/run/cmprovision-updates', owner=0, clock=time.time):
        self.root, self.runtime, self.owner, self.clock = Path(root), Path(runtime), owner, clock
        self.private, self.public = self.root/'private', self.root/'public'
        self.payloads = self.private/'payloads'
        self.projection_error = None
        self.state_file, self.pending_file = self.private/'state.json', self.runtime/'pending.json'
        for path, mode in [(self.root, 0o755), (self.private, 0o700), (self.public, 0o755),
                           (self.payloads, 0o700), (self.runtime, 0o755)]:
            self._directory(path, mode)
        with self.lock('control'):
            if not self.state_file.exists() and not self.state_file.is_symlink():
                if self.pending_file.exists() or self.pending_file.is_symlink():
                    raise UpdateError('Update recovery state is missing during maintenance.')
                self._persist({'schema': 1, 'sequence': 0, 'operations': {}, 'requests': {}, 'current': None,
                               'critical': None, 'checks': {}, 'catalogues': {}, 'ready': {}, 'recoveries': {},
                               'context': {}, 'source': {}})

    def _directory(self, path, mode):
        if not path.is_absolute() or '..' in path.parts:
            raise UpdateError('Updater directories must be absolute trusted paths.')
        for current in [*reversed(path.parents), path]:
            try:
                info = current.lstat()
            except FileNotFoundError:
                try:
                    current.mkdir(mode=mode if current == path else 0o755)
                    current.chmod(mode if current == path else 0o755)
                except FileExistsError:
                    pass # Another trusted broker may have initialized this directory concurrently.
                info = current.lstat()
            shared_tmp = current in (Path('/tmp'), Path('/var/tmp')) and info.st_uid == 0 and info.st_mode & stat.S_ISVTX
            if (not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, self.owner)
                    or (info.st_mode & 0o022 and not shared_tmp)):
                raise UpdateError('Updater directory ownership or permissions are unsafe.')
            if current == path and (info.st_uid != self.owner or stat.S_IMODE(info.st_mode) != mode):
                raise UpdateError('Updater directory ownership or permissions do not match.')

    def _file(self, path, mode=None):
        info = path.lstat()
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != self.owner or info.st_mode & 0o022
                or (mode is not None and stat.S_IMODE(info.st_mode) != mode)):
            raise UpdateError('Updater state file ownership or permissions are unsafe.')
        return info

    @contextmanager
    def lock(self, name, blocking=True):
        if name not in ('control', 'worker'):
            raise UpdateError('Invalid updater lock.')
        path = self.private/(name+'.lock')
        try:
            fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
        except OSError as error:
            raise UpdateError('Could not open the updater lock safely.') from error
        try:
            self._file(path, 0o600)
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | (0 if blocking else fcntl.LOCK_NB))
            except BlockingIOError as error:
                raise UpdateError('Another update operation is running.') from error
            yield
        finally:
            fcntl.flock(fd, fcntl.LOCK_UN)
            os.close(fd)

    def _atomic(self, path, value, mode):
        if path.exists() or path.is_symlink(): self._file(path)
        raw = canonical_bytes(value)
        if len(raw) > MAX_STATE: raise UpdateError('Updater state exceeds its size limit.')
        temp = path.parent/('.'+path.stem+'.'+uuid.uuid4().hex+'.tmp')
        try:
            fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, mode)
            with os.fdopen(fd, 'wb') as stream:
                os.fchmod(stream.fileno(), mode)
                stream.write(raw)
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(temp, path)
            directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            try: os.fsync(directory)
            finally: os.close(directory)
        finally:
            if temp.exists(): temp.unlink()

    def _load(self):
        self._file(self.state_file, 0o600)
        try:
            fd = os.open(self.state_file, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
            with os.fdopen(fd, 'rb') as stream: raw = stream.read(MAX_STATE+1)
            if len(raw) > MAX_STATE: raise UpdateError('Updater state exceeds its size limit.')
            value = json.loads(raw)
        except (OSError, ValueError, RecursionError) as error:
            raise UpdateError('Updater state is unreadable; recovery is required.') from error
        if (type(value) is not dict or type(value.get('schema')) is not int or value.get('schema') != 1
                or type(value.get('operations')) is not dict
                or type(value.get('requests')) is not dict or type(value.get('checks')) is not dict
                or type(value.get('catalogues')) is not dict or type(value.get('ready')) is not dict
                or type(value.get('recoveries')) is not dict):
            raise UpdateError('Updater state schema is invalid; recovery is required.')
        return value

    def _public_operation(self, operation, history=False):
        keys = {'id', 'action', 'component', 'components', 'version', 'state', 'created_at', 'updated_at',
                'downloaded', 'total', 'message', 'cancel_requested'}
        if not history: keys |= {'log'}
        return {key: copy.deepcopy(value) for key, value in operation.items() if key in keys}

    def _projection(self, state):
        operations = state['operations']
        current = operations.get(state['current'])
        checks = {}
        for component, check in state['checks'].items():
            envelope = state['catalogues'].get(check.get('id'))
            checks[component] = dict(check, release=envelope['manifest'] if envelope else None)
        ready = []
        for identity, receipt in state['ready'].items():
            envelope = state['catalogues'].get(identity)
            if envelope:
                ready.append({'id': identity, 'release': envelope['manifest'],
                              'receipt': receipt['fingerprint'],
                              'plan': receipt.get('plan', []), 'prepared_at': receipt.get('prepared_at')})
        context = state['context']
        return {'schema': 1, 'installed': {'application': context.get('cmprovision'),
                 'fpms': context.get('fpms_version'), 'utility-os': context.get('utility_os_label', context.get('utility_os_version')),
                 'utility_selector': context.get('utility_selector')}, 'source': state['source'], 'checks': checks,
                'ready': ready, 'pending': copy.deepcopy(state['critical']),
                'recoveries': [self._public_operation(op, history=True) for op in sorted(state['recoveries'].values(),
                    key=lambda item: item['sequence'], reverse=True)],
                'operation': self._public_operation(current) if current else None,
                'history': [self._public_operation(op, history=True) for op in sorted(operations.values(),
                    key=lambda item: item['sequence'], reverse=True)[:10]]}

    def _persist(self, state):
        self._atomic(self.state_file, state, 0o600)
        # A failed UI projection is not a failed durable transaction. In particular, do not repeat or roll back
        # an accepted install just because its status file could not be refreshed. The broker can read private state.
        try:
            self._atomic(self.public/'status.json', self._projection(state), 0o644)
            self.projection_error = None
        except (OSError, UpdateError):
            self.projection_error = 'The read-only update status projection could not be refreshed.'

    def _mutate(self, action):
        with self.lock('control'):
            state = self._load()
            result = action(state)
            self._persist(state)
            return copy.deepcopy(result)

    def snapshot(self):
        with self.lock('control'):
            return self._projection(self._load())

    def operation(self, identity):
        if not matches(ID_RE, identity): raise UpdateError('Invalid update operation identifier.')
        with self.lock('control'):
            state = self._load()
            value = state['operations'].get(identity) or state['recoveries'].get(identity)
            if value is None: raise UpdateError('Update operation was not found.')
            return copy.deepcopy(value)

    def begin(self, request):
        validate_request(request)
        digest = hashlib.sha256(canonical_bytes(request)).hexdigest()
        def action(state):
            previous = state['requests'].get(request['nonce'])
            if previous:
                if previous['hash'] != digest: raise UpdateError('Update nonce was used for a different request.')
                operation = state['operations'].get(previous['id']) or state['recoveries'].get(previous['id'])
                if operation is None: raise UpdateError('This operation is archived and cannot be repeated.')
                return operation, False
            current = state['operations'].get(state['current'])
            if (state['critical'] or self.pending_file.exists() or self.pending_file.is_symlink()
                    or current and current['state'] not in TERMINAL):
                raise UpdateError('Another update operation is running or needs recovery.')
            state['sequence'] += 1
            identity = uuid.uuid4().hex
            operation = {'id': identity, 'request': request, 'action': request['action'], 'state': 'queued',
                         'created_at': int(self.clock()), 'updated_at': int(self.clock()),
                         'sequence': state['sequence'], 'downloaded': 0, 'total': 0, 'log': [], 'cancel_requested': False}
            for key in ('component', 'components'):
                if key in request: operation[key] = request[key]
            state['operations'][identity] = operation
            state['requests'][request['nonce']] = {'hash': digest, 'id': identity, 'sequence': state['sequence']}
            state['current'] = identity
            if len(state['operations']) > 40:
                for old in sorted(state['operations'].values(), key=lambda item: item['sequence'])[:-40]:
                    if old['id'] != identity and old['state'] in TERMINAL:
                        state['operations'].pop(old['id'])
            if len(state['requests']) > 4096:
                state['requests'] = dict(sorted(state['requests'].items(), key=lambda item: item[1]['sequence'])[-4096:])
            return operation, True
        return self._mutate(action)

    def update(self, identity, **changes):
        if not matches(ID_RE, identity): raise UpdateError('Invalid update operation identifier.')
        if 'state' in changes and changes['state'] not in STATES: raise UpdateError('Invalid update operation state.')
        for key in ('downloaded', 'total'):
            if key in changes and (type(changes[key]) is not int or not 0 <= changes[key] <= 2*1024**3):
                raise UpdateError('Invalid update progress.')
        if 'message' in changes: changes['message'] = safe_text(changes['message'])
        if 'log' in changes: changes['log'] = [safe_text(line) for line in changes['log']][-20:]
        def action(state):
            operation = state['operations'].get(identity)
            if operation is None: raise UpdateError('Update operation was not found.')
            operation.update(changes, updated_at=int(self.clock()))
            if operation['downloaded'] > operation['total']: raise UpdateError('Update progress exceeds its total.')
            if operation['state'] == 'succeeded' and (operation.get('backup') or operation.get('previous')):
                state['recoveries'][identity] = copy.deepcopy(operation)
                state['recoveries'] = dict(sorted(state['recoveries'].items(),
                    key=lambda item: item[1]['sequence'])[-5:])
            return operation
        return self._mutate(action)

    def cancel(self, identity):
        def action(state):
            operation = state['operations'].get(identity)
            if (not operation or operation['state'] not in ('queued', 'checking', 'downloading', 'verifying')
                    or state['critical']):
                raise UpdateError('This operation can no longer be canceled.')
            operation['cancel_requested'] = True
        return self._mutate(action)

    def mark_pending(self, identity):
        def action(state):
            if identity != state['current'] or identity not in state['operations']:
                raise UpdateError('Only the current update may enter maintenance.')
            if state['critical'] and state['critical']['id'] != identity:
                raise UpdateError('Another update is already in maintenance.')
            state['critical'] = {'id': identity, 'at': int(self.clock()), 'schema': 1}
            return state['critical']
        pending = self._mutate(action)
        self._atomic(self.pending_file, pending, 0o644)

    def clear_pending(self, identity):
        with self.lock('control'):
            state = self._load()
            operation = state['operations'].get(identity)
            if not operation or operation['state'] not in TERMINAL:
                raise UpdateError('An unresolved update cannot leave maintenance.')
            if state['critical'] and state['critical']['id'] != identity:
                raise UpdateError('Another update owns maintenance.')
            if self.pending_file.exists() or self.pending_file.is_symlink():
                self._file(self.pending_file, 0o644)
                self.pending_file.unlink()
                fd = os.open(self.runtime, os.O_RDONLY | os.O_DIRECTORY)
                try: os.fsync(fd)
                finally: os.close(fd)
            # A crash before this private commit still has durable maintenance state,
            # so boot recovery can recreate the runtime gate and finish committed cleanup.
            state['critical'] = None
            self._persist(state)

    def metadata(self, context, source):
        return self._mutate(lambda state: state.update(context=context, source=source))

    def catalogue(self, identity):
        if not matches(SHA_RE, identity): raise UpdateError('Invalid update catalogue identifier.')
        with self.lock('control'):
            value = self._load()['catalogues'].get(identity)
            if value is None: raise UpdateError('Verified update catalogue was not found.')
            return copy.deepcopy(value)

    def record_check(self, component, envelope=None, error=None):
        if component not in COMPONENTS: raise UpdateError('Unknown update component.')
        if envelope:
            try:
                raw = base64.b64decode(envelope['raw'], validate=True)
                signature = base64.b64decode(envelope['signature'], validate=True)
            except (ValueError, KeyError) as failure:
                raise UpdateError('Invalid verified update envelope.') from failure
            if hashlib.sha256(raw).hexdigest() != envelope['id'] or not signature:
                raise UpdateError('Verified catalogue identifier does not match.')
        def action(state):
            identity = envelope['id'] if envelope else None
            if envelope: state['catalogues'][identity] = envelope
            state['checks'][component] = {'id': identity, 'checked_at': int(self.clock()),
                'error': safe_text(error) if error else None, 'incompatible': envelope.get('incompatible') if envelope else None}
        return self._mutate(action)

    def cache_catalogue(self, envelope):
        try:
            raw = base64.b64decode(envelope['raw'], validate=True)
            valid = matches(SHA_RE, envelope['id']) and hashlib.sha256(raw).hexdigest() == envelope['id']
        except (KeyError, ValueError, TypeError) as error:
            raise UpdateError('Invalid verified update envelope.') from error
        if not valid: raise UpdateError('Verified catalogue identifier does not match.')
        return self._mutate(lambda state: state['catalogues'].update({envelope['id']: envelope}))

    def payload_dir(self, identity):
        if not matches(SHA_RE, identity): raise UpdateError('Invalid download directory identifier.')
        path = self.payloads/identity
        self._directory(path, 0o700)
        return path

    def save_receipt(self, identity, receipt):
        def action(state):
            if identity not in state['catalogues']: raise UpdateError('Prepared update has no verified catalogue.')
            value = {key: copy.deepcopy(value) for key, value in receipt.items() if key != 'fingerprint'}
            value.update(prepared_at=int(self.clock()), manifest_id=identity)
            value['fingerprint'] = hashlib.sha256(canonical_bytes(value)).hexdigest()
            state['ready'][identity] = value
        return self._mutate(action)

    def receipt(self, identity):
        if not matches(SHA_RE, identity): raise UpdateError('Invalid prepared update identifier.')
        with self.lock('control'):
            value = self._load()['ready'].get(identity)
            if value is None: raise UpdateError('Prepared update was not found; download it first.')
            return copy.deepcopy(value)
