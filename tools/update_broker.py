"""Bounded root broker. No caller-supplied commands, environment, repository or paths."""
import json
import subprocess

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
    if value.get('action') == 'cancel':
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


class Broker:
    def __init__(self, store, launcher=launch): self.store, self.launcher = store, launcher

    def request(self, request):
        action = request['action']
        if action == 'status': return self.store.snapshot()
        if action == 'cancel':
            self.store.cancel(request['id'])
            return self.store.snapshot()
        operation, created = self.store.begin(request)
        if created:
            try: self.launcher(operation['id'])
            except UpdateError:
                # Keep the allocated ID even when systemd's reply is lost. A second worker
                # or installation is never launched by a replay of this nonce.
                return dict(self.store.snapshot(), id=operation['id'], launch_uncertain=True)
        return dict(self.store.snapshot(), id=operation['id'])
