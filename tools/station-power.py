#!/usr/bin/python3
"""Fixed delayed power executor and local FPMS bridge. Never opens application DB files."""
import fcntl
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import time
import uuid

RUNTIME = Path('/run/cmprovision-power')
ROOT_UID = 0
ACTIONS = ('reboot', 'poweroff')
BOOT = Path('/proc/sys/kernel/random/boot_id')


class PowerError(RuntimeError): pass


def boot_id():
    value = BOOT.read_text().strip()
    if not re.fullmatch(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}', value):
        raise PowerError('Boot identity is unavailable.')
    return value


class Controller:
    def __init__(self, runtime=RUNTIME, run=subprocess.run, clock=time.time, boot=boot_id):
        self.runtime, self.run, self.clock, self.boot = Path(runtime), run, clock, boot

    def initialize(self):
        try:
            self.runtime.mkdir(mode=0o755)
            os.chmod(self.runtime, 0o755, follow_symlinks=False)
        except FileExistsError: pass
        info = self.runtime.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != ROOT_UID or info.st_mode & 0o022 or info.st_mode & 0o555 != 0o555:
            raise PowerError('The power runtime directory is not secure.')

    def open(self, name, flags, mode=0o600):
        try: fd = os.open(self.runtime / name, flags | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, mode)
        except OSError as error: raise PowerError('The power runtime file is unavailable.') from error
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != ROOT_UID or info.st_mode & (0o077 if name == 'control.lock' else 0o022):
            os.close(fd)
            raise PowerError('The power runtime file is not secure.')
        return fd

    def pending(self):
        if not os.path.lexists(self.runtime / 'pending.json'): return None
        fd = self.open('pending.json', os.O_RDONLY)
        with os.fdopen(fd, 'rb') as source:
            raw = source.read(4097)
        try:
            value = json.loads(raw) if len(raw) <= 4096 else None
        except (ValueError, RecursionError): value = None
        if not isinstance(value, dict) or value.get('action') not in ACTIONS or type(value.get('scheduled_at')) is not int:
            raise PowerError('The pending power record is invalid.')
        return value if value.get('boot_id') == self.boot() and 0 <= self.clock() - value['scheduled_at'] < 120 else None

    def write(self, state):
        fd, name = tempfile.mkstemp(prefix='.power-', dir=self.runtime)
        try:
            with os.fdopen(fd, 'w') as output:
                json.dump(state, output)
                output.write('\n')
                output.flush()
                os.fsync(output.fileno())
                os.fchmod(output.fileno(), 0o644)
            os.replace(name, self.runtime / 'pending.json')
        finally:
            if os.path.exists(name): os.unlink(name)

    def schedule(self, action):
        if not isinstance(action, str) or action not in ACTIONS: raise PowerError('Unknown power action.')
        self.initialize()
        fd = self.open('control.lock', os.O_RDWR | os.O_CREAT)
        try:
            try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as error: raise PowerError('Another power action is being scheduled.') from error
            if self.pending(): raise PowerError('A power action is already pending.')
            if os.path.lexists('/run/cmprovision-updates/pending.json'):
                raise PowerError('A station update is in maintenance; wait for it to finish.')
            state = dict(action=action, boot_id=self.boot(), scheduled_at=int(self.clock()), delay=5, confirmed=False)
            self.write(state)  # Publish before scheduling; an uncertain result must never allow another write to start.
            command = ['/usr/bin/systemd-run', '--quiet', '--collect', '--unit=cmprovision-station-power',
                       '--on-active=5s', '--timer-property=AccuracySec=1s', '--', '/usr/bin/systemctl', action]
            try: result = self.run(command, timeout=5, capture_output=True, text=True, check=False)
            except (OSError, subprocess.TimeoutExpired) as error:
                raise PowerError('Scheduling was not confirmed. Check station status before retrying.') from error
            if result.returncode != 0: raise PowerError('Scheduling was not confirmed. Check station status before retrying.')
            state['confirmed'] = True
            self.write(state)
            return state
        finally:
            os.close(fd)


def panel(request, run=subprocess.run):
    if not isinstance(request, dict): raise PowerError('Invalid panel request.')
    operation = request.get('operation')
    allowed = {'status': {'operation'}, 'prepare': {'operation', 'action'},
               'confirm': {'operation', 'token', 'interrupt'}, 'cancel': {'operation', 'token'}}
    if not isinstance(operation, str) or operation not in allowed or set(request) != allowed[operation]:
        raise PowerError('Invalid panel operation.')
    if operation == 'prepare' and request['action'] not in ACTIONS: raise PowerError('Unknown power action.')
    if operation in ('confirm', 'cancel') and (not isinstance(request['token'], str) or not re.fullmatch('[0-9a-f]{64}', request['token'])):
        raise PowerError('Invalid confirmation ticket.')
    if operation == 'confirm' and type(request['interrupt']) is not bool: raise PowerError('Invalid interruption confirmation.')
    # The system manager starts this short-lived command OUTSIDE the display's sandbox,
    # under www-data. Thus SQLite is never opened by root and FPMS keeps ProtectSystem/NNP.
    command = ['/usr/bin/systemd-run', '--quiet', '--pipe', '--wait', '--collect', '--uid=www-data',
               '--property=RuntimeMaxSec=10s', '--property=TimeoutStopSec=1s',
               '--unit=cmprovision-panel-power-' + uuid.uuid4().hex,
               '/usr/bin/php', '/var/lib/cmprovision/artisan', 'station:power', '--json']
    try:
        result = run(command, input=json.dumps(request), timeout=12, capture_output=True, text=True, check=False)
        response = json.loads(result.stdout)
    except (OSError, ValueError, subprocess.TimeoutExpired) as error:
        raise PowerError('The local power controller did not answer.') from error
    if result.returncode != 0 or not isinstance(response, dict) or type(response.get('ok')) is not bool:
        raise PowerError('The local power controller is unavailable.')
    return response


def main():
    try:
        if os.geteuid() != 0: raise PowerError('This fixed controller must run as root.')
        if sys.argv[1:] not in ([], ['panel']): raise PowerError('Unexpected arguments.')
        raw = sys.stdin.buffer.read(4097)
        if len(raw) > 4096: raise PowerError('Request is too large.')
        request = json.loads(raw)
        if sys.argv[1:] == ['panel']: response = panel(request)
        else:
            if not isinstance(request, dict) or set(request) != {'action'}: raise PowerError('Expected only a power action.')
            response = {'ok': True, 'result': Controller().schedule(request['action'])}
        print(json.dumps(response))
        return 0
    except (PowerError, OSError, ValueError, TypeError, RecursionError) as error:
        message = str(error) if isinstance(error, PowerError) else 'The power request could not be completed.'
        print(json.dumps({'ok': False, 'error': message}))
        return 1


if __name__ == '__main__': sys.exit(main())
