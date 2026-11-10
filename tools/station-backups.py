#!/usr/bin/python3
"""Fixed root backup entry point. No environment/program/path overrides from web callers."""
import json
import os
from pathlib import Path
import re
import shutil
import sys
import uuid

sys.path.insert(0, str(Path(__file__).resolve().parent))
from backup_broker import Broker, decode_request, reconcile, tick
from backup_store import BackupStore
from backup_system import LinuxBackupSystem
from backup_worker import Worker
from update_diagnostics import journal
from update_manifest import UpdateError


def main():
    binary = False
    try:
        if os.geteuid() != 0: raise UpdateError('The fixed backup broker must run as root.')
        os.umask(0o077)
        store = BackupStore()
        system = LinuxBackupSystem(store)
        args = sys.argv[1:]
        if not args:
            request = decode_request(sys.stdin.buffer.read(196609))
            binary = request['action'] == 'download'
            if binary:
                path = store.download_file(request['id'])
                fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
                with os.fdopen(fd, 'rb') as source: shutil.copyfileobj(source, sys.stdout.buffer, length=1024**2)
                return 0
            if request['action'] == 'status': reconcile(store, system)
            result = Broker(store, timezone=system.timezone).request(request)
        elif args == ['--initialize']: result = store.status()
        elif args == ['--tick']:
            tick(store, system); result = {'message': 'Backup schedule reconciled.'}
        elif args == ['--recover']:
            Worker(store, system).recover_pending(); result = {'message': 'Backup recovery reconciled.'}
        elif args == ['--recover-boot']:
            pending = store.configuration()['pending']
            if pending:
                store._atomic(store.pending_file, {'schema': 1, 'id': pending['id'], 'at': pending['at']}, 0o644)
                # Do not start Before= boot services while still running their ordering barrier.
                system.command(['/usr/bin/systemd-run', '--quiet', '--collect', '--unit=cmprovision-backup-recovery', '--',
                    '/usr/bin/python3', '-I', '/usr/lib/cmprovision/station-backups.py', '--recover'], timeout=8)
            result = {'message': 'Backup boot recovery scheduled.'}
        elif len(args) == 2 and args[0] == '--worker' and re.fullmatch(r'[0-9a-f]{32}', args[1]):
            Worker(store, system).run(args[1])
            job = store.operation(args[1])
            result = {key: job[key] for key in ('id', 'state', 'message')}
        else: raise UpdateError('Unexpected backup controller arguments.')
        print(json.dumps({'ok': True, 'result': result}))
        return 0
    except Exception as error:
        journal(error, uuid.uuid4().hex, 'controller', event='cmprovision-backup-failure')
        if not binary:
            print(json.dumps({'ok': False, 'error': str(error) if isinstance(error, UpdateError) else 'The backup controller is unavailable. Check its service journal.'}))
        return 1


if __name__ == '__main__': raise SystemExit(main())
