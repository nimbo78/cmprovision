"""Bounded updater-owned artifacts. No images, user backups or unrelated directories are pruned."""
import copy
from contextlib import ExitStack
import os
from pathlib import Path
import re
import shutil
import stat

from update_store import TERMINAL, RECOVERY_TTL
from update_manifest import UpdateError


def recovery_expired(operation, now):
    expiry = operation.get('recovery_expires_at')
    if expiry is None:
        completed = operation.get('updated_at')
        if type(completed) is not int or completed < 0: return False
        expiry = completed+RECOVERY_TTL
    return type(expiry) is int and expiry >= 0 and now >= expiry


def has_unretained_artifacts(store, retained):
    """Retry metadata-first cleanup after unlink failure or an interrupted prune."""
    for name in ('recovery', 'runners'):
        parent = store.private/name
        try:
            info = parent.lstat()
            if not stat.S_ISDIR(info.st_mode) or info.st_uid != store.owner or info.st_mode & 0o022: continue
            for path in parent.iterdir():
                if not re.fullmatch(r'[0-9a-f]{32}', path.name) or path.name in retained: continue
                info = path.lstat()
                if (stat.S_ISDIR(info.st_mode) and info.st_uid == store.owner and not info.st_mode & 0o022
                        and info.st_mtime <= store.clock()): return True
        except OSError: continue # Housekeeping observation cannot make public status unavailable.
    return False


def prune_idle(store):
    """Housekeeping may not take a queued/live worker's execution slot."""
    with ExitStack() as locks:
        with store.lock('control'):
            state = store._load()
            current = state['operations'].get(state['current'])
            if state['critical'] or current and current['state'] not in TERMINAL: return
            try: locks.enter_context(store.lock('worker', blocking=False))
            except UpdateError: return
            if os.path.lexists(store.runtime.with_name('cmprovision-backups')/'pending.json'): return
        # Without a proven active selector, status housekeeping leaves Utility OS trees alone.
        prune(store, managed=None)


def prune(store, managed=Path('/var/lib/cmprovision-utility-os'), now=None, active_selector=None, before_snapshot=False):
    now = store.clock() if now is None else now
    with store.lock('control'):
        state = store._load()
        references = {value.get('id') for value in state['checks'].values()} | set(state['ready'])
        current = state['operations'].get(state['current'])
        protected = set()
        if state['critical']: protected.add(state['critical']['id'])
        if current and (current['state'] not in TERMINAL or current['id'] in protected):
            protected.add(current['id'])
            if current['action'] == 'rollback': protected.add(current['request']['operation'])
        # Migrate old histories too. A currently selected rollback source is an
        # extra temporary reference, never an excuse to delete live recovery data.
        eligible = [key for key, operation in state['recoveries'].items() if not recovery_expired(operation, now)]
        latest = [] if before_snapshot else sorted(eligible, key=lambda key: state['recoveries'][key]['sequence'])[-1:]
        state['recoveries'] = {key: value for key, value in state['recoveries'].items()
                               if key in set(latest) | protected}
        if current: references.add(current['request'].get('candidate'))
        # Keep a small spare catalogue window as well as every live UI/receipt reference.
        references |= set(list(state['catalogues'])[-4:])
        state['catalogues'] = {key: value for key, value in state['catalogues'].items() if key in references}
        store._persist(state)
        retained = set(state['recoveries']) | protected
        selections = {state['context'].get('utility_selector')}
        selections.add(active_selector)
        if current and current['id'] in protected: selections.add(current['request'].get('selector'))
        active = [state['operations'][key] for key in protected if key in state['operations']]
        for operation in [*state['recoveries'].values(), *active]:
            selections.add(operation.get('previous', {}).get('utility_selector'))
        selections |= {value.get('selector') for value in state['ready'].values()}
    def cleanup(parent, valid, keep, verify=None, grace=3600):
        if not parent.exists(): return
        store._directory(parent, stat.S_IMODE(parent.lstat().st_mode))
        for path in parent.iterdir():
            if not valid(path.name) or path.name in keep: continue
            info = path.lstat()
            if (not stat.S_ISDIR(info.st_mode) or info.st_uid != store.owner or info.st_mode & 0o022
                    or info.st_mtime > now-grace): continue
            if verify:
                try: verify(path)
                except Exception: continue # An unknown/damaged tree is left for an administrator, not guessed at.
            shutil.rmtree(path) # fd-based rmtree does not follow subtree symlinks.
    cleanup(store.payloads, lambda value: re.fullmatch('[0-9a-f]{64}', value), references, grace=0)
    for directory in store.payloads.iterdir():
        if not re.fullmatch('[0-9a-f]{64}', directory.name): continue
        store._directory(directory, 0o700)
        for path in directory.glob('.download-*.part'):
            info = path.lstat()
            if (re.fullmatch(r'\.download-[0-9a-f]{32}\.part', path.name) and stat.S_ISREG(info.st_mode)
                    and info.st_uid == store.owner and not info.st_mode & 0o022): path.unlink()
    cleanup(store.private/'recovery', lambda value: re.fullmatch('[0-9a-f]{32}', value), retained, grace=0)
    cleanup(store.private/'runners', lambda value: re.fullmatch('[0-9a-f]{32}', value), retained, grace=0)
    if managed is None: return
    from update_utility import ManagedUtility
    utility = ManagedUtility(store, managed)
    keep_versions = {value.split(':', 1)[1] for value in selections if isinstance(value, str) and value.startswith('managed:')}
    def verify(path):
        if not path.name.startswith('.prepare-'):
            if active_selector is None: raise RuntimeError('Cannot prove the current boot selection; keep every complete release.')
            utility.validate('managed:'+path.name)
    cleanup(Path(managed)/'releases', lambda value: re.fullmatch(r'[0-9]{4}\.[0-9]{2}(?:\.[0-9]+)?', value)
            or value.startswith('.prepare-'), keep_versions, verify,
            grace=3600)
