#!/usr/bin/python3
"""Fixed package entry point; privileged arguments are never accepted through sudo."""
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
from types import SimpleNamespace

# -I intentionally omits the script directory: only this root-owned package directory is added.
sys.path.insert(0, str(Path(__file__).resolve().parent))
from update_broker import Broker, decode_request, reconcile
from update_manifest import UpdateError
from update_store import UpdateStore
from update_system import LinuxSystem
from update_transport import ReleaseSource
from update_worker import Worker


def source():
    config = Path('/etc/cmprovision-updates/publisher.json')
    for path in (config.parent, config, config.parent/'publisher.pub'):
        info = path.lstat()
        if info.st_uid != 0 or info.st_mode & 0o022 or stat.S_ISLNK(info.st_mode):
            raise UpdateError('The publisher configuration is not root-owned and protected.')
    value = json.loads(config.read_text())
    if set(value) != {'repository'} or not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', value['repository']):
        raise UpdateError('The publisher configuration is invalid.')
    return ReleaseSource(value['repository'], config.parent/'publisher.pub')


def main():
    try:
        if os.geteuid() != 0: raise UpdateError('The fixed update controller must run as root.')
        store = UpdateStore()
        args = sys.argv[1:]
        if not args:
            request = decode_request(sys.stdin.buffer.read(8193))
            if request['action'] == 'status': reconcile(store)
            result = Broker(store).request(request)
        elif args == ['--initialize']:
            from update_recovery import install_dispatcher
            install_dispatcher(store)
            publisher = source()
            system = LinuxSystem(store)
            if not store.snapshot()['pending']:
                try: context = system.context()
                except UpdateError:
                    # dpkg marks the package installed only after postinst returns.
                    context = dict(system.www('updates:inspect'), cmprovision=system.command(
                        ['/usr/bin/dpkg-query', '-W', '-f=${Version}', 'cmprovision4']), fpms_version=system.package('fpms-lite'))
                store.metadata(context, {'repository': publisher.repository, 'configured': True})
            result = store.snapshot()
        elif args == ['--recover-boot']:
            pending = store.snapshot()['pending']
            if pending:
                store.mark_pending(pending['id'])
                # The transient recovery starts after this ordering barrier can finish;
                # starting nginx/queues from inside their Before= unit would deadlock systemd.
                result = subprocess.run(['/usr/bin/systemd-run', '--quiet', '--collect',
                    '--unit=cmprovision-update-recovery', '--', '/usr/bin/python3', '-I',
                    '/usr/lib/cmprovision/station-updates.py', '--recover'], capture_output=True, timeout=8)
                if result.returncode: raise UpdateError('Could not schedule interrupted update recovery. Maintenance remains enabled.')
            else:
                operation = store.snapshot()['operation']
                if operation and operation['state'] not in ('ready', 'succeeded', 'rolled-back', 'failed', 'canceled'):
                    store.update(operation['id'], state='failed', message='The previous noncritical operation was interrupted; check or download again.')
            result = store.snapshot()
        elif args == ['--recover']:
            # Recovery uses the retained code/state, even if dpkg interrupted replacement
            # of the live trust configuration or publisher modules.
            publisher = SimpleNamespace(repository=store.snapshot()['source'].get('repository', 'nimbo78/cmprovision'))
            worker = Worker(store, publisher, LinuxSystem(store))
            worker.recover_pending()
            operation = store.snapshot()['operation']
            if operation and operation['state'] not in ('ready', 'succeeded', 'rolled-back', 'failed', 'canceled', 'recovery-required'):
                store.update(operation['id'], state='failed', message='The previous noncritical worker was interrupted; check or download again.')
            result = store.snapshot()
        elif len(args) == 2 and args[0] == '--worker' and re.fullmatch('[0-9a-f]{32}', args[1]):
            Worker(store, source(), LinuxSystem(store)).run(args[1])
            result = store.snapshot()
        else: raise UpdateError('Unexpected update controller arguments.')
        print(json.dumps({'ok': True, 'result': result}))
        return 0
    except Exception as error:
        message = str(error) if isinstance(error, UpdateError) else 'The updater is unavailable. Inspect the updater service journal.'
        print(json.dumps({'ok': False, 'error': message}))
        return 1


if __name__ == '__main__': raise SystemExit(main())
