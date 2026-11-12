"""One completed local copy; callers serialize cleanup with the backup worker lock."""
import errno
import json
from pathlib import Path
import shutil

from update_manifest import UpdateError
from update_store import ID_RE, matches


def _owned_download(store, state, job):
    directory = store.private/'jobs'/job['id']
    if not directory.exists() and not directory.is_symlink(): return None
    store._directory(directory, 0o700)
    marker = directory/'owner.json'
    if not marker.exists() or marker.is_symlink(): return None
    store._file(marker, 0o600)
    with marker.open('rb') as stream: raw = stream.read(4097)
    if len(raw) > 4096: return None
    try: owner = json.loads(raw)
    except ValueError: return None
    if type(owner) is not dict: return None
    if (owner.get('id') != job['id'] or owner.get('station_id') not in
            [state['station_id'], *state.get('artifact_station_ids', [])] or type(owner.get('created_at')) is not int):
        return None
    return directory


def prune_downloads(store):
    with store.lock('control'):
        state = store._load()
        store.idle(state)
        copies, usable = {}, []
        for identity, job in state['operations'].items():
            if (not matches(ID_RE, identity) or job.get('id') != identity or job.get('action') != 'backup'
                    or job.get('target') != 'download' or job.get('state') != 'ready'): continue
            directory = _owned_download(store, state, job)
            if directory is None: continue
            copies[identity] = directory
            path = directory/'backup.cmpbackup'
            if path.exists() and not path.is_symlink():
                info = store._file(path, 0o600)
                if info.st_nlink == 1 and info.st_size == job.get('bytes'): usable.append(job)
        if not usable: return [] # An unverified/broken replacement cannot displace the last usable copy.
        latest = max(usable, key=lambda job: job.get('sequence', job['created_at']))['id']
        removed = {identity: path for identity, path in copies.items() if identity != latest}
        for identity in removed:
            state['operations'][identity].update(expires_at=0, message='Replaced by the latest verified backup.')
        if removed: store._persist(state) # Expire links before removing their owned files.
    for directory in removed.values(): shutil.rmtree(directory)
    return list(removed)


def prune_restore_snapshots(store, root):
    """Only verified recovery_file ledger entries prove ownership; unknown copies stay."""
    state = store.configuration()
    if state['pending']: return []
    parent = Path(root)/'var/backups/cmprovision-full-restore'
    copies = []
    for identity, job in state['operations'].items():
        if not matches(ID_RE, identity) or job.get('id') != identity or job.get('action') != 'restore': continue
        directory = parent/identity
        path = directory/'before.cmpbackup'
        if job.get('recovery_file') != str(path) or not path.exists(): continue
        store._directory(directory, 0o700)
        store._file(path, 0o600)
        copies.append((job, path))
    if not copies: return []
    latest = max(copies, key=lambda pair: pair[0].get('sequence', pair[0]['created_at']))[0]['id']
    removed = []
    for job, path in copies:
        if job['id'] == latest or job['state'] not in ('ready', 'succeeded', 'failed', 'canceled'): continue
        path.unlink()
        try: path.parent.rmdir()
        except OSError as error:
            if error.errno not in (errno.ENOTEMPTY, errno.EEXIST): raise
        store.update(job['id'], recovery_file=None)
        removed.append(job['id'])
    return removed
