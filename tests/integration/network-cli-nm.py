#!/usr/bin/env python3
"""Exercise the root entrypoint with real NM in the private namespace, substituting systemd/SQLite."""
import importlib.machinery
import io
import json
import os
import subprocess
import sys

bus = os.environ['DBUS_SYSTEM_BUS_ADDRESS']
cli = importlib.machinery.SourceFileLoader('network_cli', sys.argv[1]).load_module()
os.environ['DBUS_SYSTEM_BUS_ADDRESS'] = bus
os.environ['PATH'] = '/run/lab-bin:/usr/sbin:/usr/bin:/sbin:/bin'


def run(*args):
    if args[0] == 'busctl': args = ('busctl', '--address=' + bus, *args[1:])
    result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=30)
    if result.returncode: raise cli.backend_module.BackendError(result.stderr.strip())
    return result.stdout.strip()


class Scheduler:
    def schedule(self, _): pass
    def cancel(self, _): pass


real_backend = cli.backend_module.Backend
busy = [False]
cli.backend_module.Backend = lambda files, profile, scheduler, _: real_backend(files, profile, scheduler, lambda: busy[0], run=run)
cli.watchdog.Watchdog = Scheduler
cli.BASE = '/var/lib/lab-cli'
cli.STATE = cli.BASE + '/transactions'
cli.RUNTIME = '/run/lab-cli'


def call(action, payload=None, suffix=None):
    argv, stdin = sys.argv, sys.stdin
    try:
        sys.argv = ['network-transaction', action] + ([suffix] if suffix else [])
        sys.stdin = io.TextIOWrapper(io.BytesIO(json.dumps(payload or {}).encode()), encoding='utf-8')
        return cli.main()
    finally:
        sys.argv, sys.stdin = argv, stdin


run('nmcli', 'connection', 'add', 'type', 'vlan', 'ifname', 'cmp-cli124', 'dev', 'cmp-test0',
    'id', '124', 'con-name', 'cmp-cli', 'connection.autoconnect', 'no', 'ipv4.method', 'manual',
    'ipv4.addresses', '198.19.40.2/24', 'ipv4.never-default', 'yes', 'ipv6.method', 'disabled')
try:
    run('nmcli', '--wait', '10', 'connection', 'up', 'cmp-cli')
    connection = run('nmcli', '-g', 'connection.uuid', 'connection', 'show', 'cmp-cli')
    request = {'uuid': connection, 'device': 'cmp-cli124', 'profile': {'ipv4': {
        'method': 'manual', 'addresses': ['198.19.41.2/24'], 'never_default': True}, 'ipv6': {'method': 'disabled'}}}
    assert call('init')['initialized'] and not call('status')['pending']
    draft = call('profiles', {'operation': 'create', 'device': 'cmp-peer0', 'name': 'CLI draft'})['created']
    assert call('profiles', {'operation': 'delete', 'uuid': draft})['deleted'] == draft
    busy[0] = True
    try:
        call('profiles', {'operation': 'create', 'device': 'cmp-peer0', 'name': 'Refused during writing'})
        raise AssertionError('a profile was created while provisioning')
    except cli.connection_module.ConnectionError:
        pass
    try:
        call('begin', request)
        raise AssertionError('a change was permitted during provisioning')
    except cli.coordinator.TransactionError:
        pass
    busy[0] = False
    token = call('begin', request)['token']
    status = call('status')
    assert status['pending'] and status['token'] == token and status['device'] == 'cmp-cli124'
    old_id = cli.store_module.Store(cli.STATE, cli.RUNTIME).load()['id']
    assert call('cancel', {'token': token})['cancelled']
    assert run('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'uuid', connection) == '198.19.40.2/24'
    token = call('begin', request)['token']
    call('recover', suffix=old_id)  # late watchdog for the cancelled transaction
    assert call('status')['token'] == token
    assert call('confirm', {'token': token})['confirmed']
    assert not call('status')['pending']
    assert run('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'uuid', connection) == '198.19.41.2/24'
finally:
    subprocess.call(['nmcli', 'connection', 'delete', 'cmp-cli'], stdout=subprocess.DEVNULL)
print('PASS: root CLI rejects active runs, cancels, ignores stale watchdog and confirms with real NM')
