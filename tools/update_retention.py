"""Bounded updater-owned artifacts. No images, user backups or unrelated directories are pruned."""
import copy
import os
from pathlib import Path
import re
import shutil
import stat
import time


def prune(store, managed=Path('/var/lib/cmprovision-utility-os'), now=None, active_selector=None):
    now = time.time() if now is None else now
    with store.lock('control'):
        state = store._load()
        references = {value.get('id') for value in state['checks'].values()} | set(state['ready'])
        current = state['operations'].get(state['current'])
        if current: references.add(current['request'].get('candidate'))
        # Keep a small spare catalogue window as well as every live UI/receipt reference.
        references |= set(list(state['catalogues'])[-4:])
        state['catalogues'] = {key: value for key, value in state['catalogues'].items() if key in references}
        store._persist(state)
        retained = set(state['recoveries'])
        if state['current']: retained.add(state['current'])
        if state['critical']: retained.add(state['critical']['id'])
        selections = {state['context'].get('utility_selector')}
        selections.add(active_selector)
        if current: selections.add(current['request'].get('selector'))
        for operation in [*state['recoveries'].values(), *([current] if current else [])]:
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
