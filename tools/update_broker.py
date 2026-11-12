"""Bounded root broker. No caller-supplied commands, environment, repository or paths."""
import json
import os
import subprocess
import time

from update_manifest import UpdateError
from update_store import ID_RE, matches, validate_request


def decode_request(raw):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result: raise UpdateError('Duplicate request fields are not allowed.')
            result[key] = value
        return result
    try:
        value = json.loads(raw, object_pairs_hook=unique) if len(raw) <= 8192 else None
    except (ValueError, RecursionError) as error: raise UpdateError('Invalid update request JSON.') from error
    if type(value) is not dict: raise UpdateError('Expected a bounded update request object.')
    if value.get('action') == 'status' and set(value) == {'action'}: return value
    if value.get('action') in ('cancel', 'diagnostics'):
        if set(value) != {'action', 'id'} or not matches(ID_RE, value.get('id')):
            raise UpdateError('Invalid canceled operation identifier.')
        return value
    return validate_request(value)


def launch(identity):
    command = ['/usr/bin/systemd-run', '--quiet', '--collect', '--unit=cmprovision-update-'+identity,
        '--property=Type=exec', '--property=Nice=10', '--property=IOSchedulingClass=idle',
        '--property=TimeoutStopSec=30s', '--', '/usr/bin/python3', '-I',
        '/usr/lib/cmprovision/station-updates.py', '--worker', identity]
    try: result = subprocess.run(command, timeout=8, capture_output=True, check=False)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise UpdateError('Worker start is uncertain. Reload status; do not submit another installation.') from error
    if result.returncode: raise UpdateError('Worker start was not confirmed. Inspect the updater service journal.')


def reconcile(store, run=subprocess.run, clock=time.time):
    recover = False
    status = store.snapshot()
    operation = status['operation']
    if not operation or operation['state'] in ('ready', 'succeeded', 'rolled-back', 'failed', 'canceled', 'recovery-required'):
        return
    if clock()-operation['updated_at'] < 30: return
    try:
        unit = run(['/usr/bin/systemctl', 'is-active', 'cmprovision-update-'+operation['id']],
                   capture_output=True, text=True, timeout=3, check=False)
        if unit.stdout.strip() in ('active', 'activating', 'deactivating'): return
        with store.lock('worker', blocking=False):
            current = store.operation(operation['id'])
            if current['state'] != operation['state']: return
            if status['pending']:
                store.update(operation['id'], state='unknown', message='The interrupted worker is being recovered; no installation will be repeated.')
                recover = True
            else:
                store.update(operation['id'], state='failed', message='The noncritical worker was interrupted. Check or download again.')
        if recover:
            # Release the worker lock before starting its retained replacement.
            run(['/usr/bin/python3', '-B', '-I', str(store.root/'recover.py')],
                capture_output=True, timeout=10, check=False)
    except (UpdateError, OSError, subprocess.TimeoutExpired):
        pass # Failure to observe a worker is not permission to repeat an installation.


class Broker:
    def __init__(self, store, launcher=launch): self.store, self.launcher = store, launcher

    def request(self, request):
        action = request['action']
        if action == 'status':
            status = self.store.snapshot()
            current = status['operation']
            orphan = current and current['id'] not in {row['id'] for row in status['recoveries']} and (
                self.store.private/'recovery'/current['id']).exists()
            if len(status['recoveries']) > 1 or orphan:
                from update_retention import prune_idle
                try: prune_idle(self.store)
                except (UpdateError, OSError): pass # Housekeeping cannot make status/recovery unavailable.
            return self.store.snapshot()
        if action == 'diagnostics':
            from update_diagnostics import read
            return read(self.store, request['id'])
        if action == 'cancel':
            self.store.cancel(request['id'])
            return self.store.snapshot()
        if os.path.lexists(self.store.runtime.with_name('cmprovision-backups')/'pending.json'):
            raise UpdateError('A station backup is being created. Wait for it to finish before checking or changing updates.')
        operation, created = self.store.begin(request)
        if created:
            try: self.launcher(operation['id'])
            except UpdateError:
                # Keep the allocated ID even when systemd's reply is lost. A second worker
                # or installation is never launched by a replay of this nonce.
                return dict(self.store.snapshot(), id=operation['id'], launch_uncertain=True)
        return dict(self.store.snapshot(), id=operation['id'])
