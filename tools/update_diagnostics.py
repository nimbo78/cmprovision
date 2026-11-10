"""Bounded, secret-free failure records. Never stringify unexpected exceptions."""
import json
import os
from pathlib import Path
import re
import stat
import sys

from update_manifest import UpdateError, canonical_bytes
from update_store import ID_RE, matches

LIMIT = 64*1024
KEEP = 20


def _name(value, limit=80):
    return re.sub('[^A-Za-z0-9_.-]', '_', value)[:limit]


def _attribute(error, name):
    try: return getattr(error, name, None)
    except Exception: return None


def _error(error, seen=None):
    seen = set() if seen is None else seen
    seen.add(id(error))
    value = {'type': _name(type(error).__name__), 'frames': []}
    for field, low, high in (('errno', 0, 65535), ('code', 100, 599), ('timeout', 0, 86400)):
        attribute = _attribute(error, field)
        if type(attribute) is int and low <= attribute <= high:
            value['status' if field == 'code' else field] = attribute
    frame = error.__traceback__
    while frame is not None:
        code = frame.tb_frame.f_code
        value['frames'].append({'module': _name(Path(code.co_filename).name),
                                'function': _name(code.co_name), 'line': frame.tb_lineno})
        value['frames'] = value['frames'][-24:]
        frame = frame.tb_next
    cause = error.__cause__ or (None if error.__suppress_context__ else error.__context__)
    # urllib wraps an OSError as reason rather than using exception chaining.
    wrapped = _attribute(error, 'reason')
    if cause is None and isinstance(wrapped, BaseException): cause = wrapped
    if cause is not None and id(cause) not in seen and len(seen) < 3:
        value['cause'] = _error(cause, seen)
    return value


def journal(error, reference, phase):
    value = {'event': 'cmprovision-update-failure', 'reference': _name(reference),
             'phase': _name(phase), 'error': _error(error)}
    try: print(json.dumps(value, separators=(',', ':')), file=sys.stderr, flush=True)
    except Exception: pass # Broken stderr cannot change an update/rollback outcome.
    return value


def record(store, identity, error, phase):
    """Best effort; a diagnostic failure never prevents recovery of the original failure."""
    value = journal(error, identity, phase)
    summary = {'reference': _name(identity), 'phase': _name(phase), 'type': value['error']['type']}
    try:
        if not matches(ID_RE, identity): return summary
        operation = store.operation(identity)
        folder = store.private/'diagnostics'
        with store.lock('control'):
            store._directory(folder, 0o700)
            state = store._load()
            path = folder/(identity+'.json')
            report = _read(store, path) if path.exists() or path.is_symlink() else {
                'schema': 1, 'id': identity, 'action': _name(operation['action']), 'failures': []}
            report['component'] = _name(operation.get('component', ''))
            report['installed'] = {key: value for key, value in state['context'].items()
                if key in ('cmprovision', 'fpms_version', 'utility_os_version', 'source_commit')
                and type(value) is str and re.fullmatch(r'[A-Za-z0-9.~+:-]{1,128}', value)}
            report['failures'].append({'at': int(store.clock()), 'phase': summary['phase'], 'error': value['error']})
            report['failures'] = report['failures'][-4:]
            if len(canonical_bytes(report)) > LIMIT: return summary
            store._atomic(path, report, 0o600)
            protected = {item['id'] for item in state['operations'].values() if item['state'] == 'recovery-required'}
            if state['critical']: protected.add(state['critical']['id'])
            owned = []
            for candidate in folder.iterdir():
                if not re.fullmatch(ID_RE+r'\.json', candidate.name): continue
                info = candidate.lstat()
                if stat.S_ISREG(info.st_mode) and info.st_uid == store.owner and stat.S_IMODE(info.st_mode) == 0o600:
                    owned.append((info.st_mtime_ns, candidate))
            unprotected = sorted((item for item in owned if item[1].stem not in protected), reverse=True)
            for _, candidate in unprotected[KEEP:]: candidate.unlink()
    except Exception:
        pass # State, disk or diagnostic permission failures are secondary to the update outcome.
    return summary


def _read(store, path):
    store._file(path, 0o600)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(fd, 'rb') as stream: raw = stream.read(LIMIT+1)
    if len(raw) > LIMIT: raise UpdateError('The diagnostic report exceeds its size limit.')
    value = json.loads(raw)
    if type(value) is not dict or value.get('schema') != 1 or type(value.get('failures')) is not list:
        raise UpdateError('The diagnostic report is invalid.')
    return value


def read(store, identity):
    if not matches(ID_RE, identity): raise UpdateError('Invalid diagnostic operation identifier.')
    store.operation(identity)
    try:
        folder = store.private/'diagnostics'
        store._directory(folder, 0o700)
        value = _read(store, folder/(identity+'.json'))
        if value.get('id') != identity: raise UpdateError('The diagnostic operation does not match.')
        return value
    except (OSError, ValueError) as error:
        if isinstance(error, UpdateError): raise
        raise UpdateError('No readable diagnostic report is retained for this operation.') from error
