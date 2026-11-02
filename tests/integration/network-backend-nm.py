#!/usr/bin/env python3
"""Exercise the experimental NM backend in the private hybrid VLAN namespace.

The scheduler and active-run query are replaced here; this does not test systemd or a real reboot.
"""
import importlib.machinery
import difflib
import json
import os
import subprocess
import sys

profile = importlib.machinery.SourceFileLoader('network_profile', sys.argv[1]).load_module()
transaction = importlib.machinery.SourceFileLoader('network_transaction', sys.argv[2]).load_module()
store_module = importlib.machinery.SourceFileLoader('network_store', sys.argv[3]).load_module()
backend_module = importlib.machinery.SourceFileLoader('network_backend', sys.argv[4]).load_module()
os.environ['PATH'] = '/run/lab-bin:/usr/sbin:/usr/bin:/sbin:/bin'


def run(*args):
    if args[0] == 'busctl':
        args = ('busctl', '--address=' + os.environ['DBUS_SYSTEM_BUS_ADDRESS'], *args[1:])
    result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=30)
    if result.returncode:
        raise backend_module.BackendError(result.stderr.strip())
    return result.stdout.strip()


class Scheduler:
    def __init__(self): self.pending = False
    def schedule(self, _): self.pending = True
    def cancel(self, _): self.pending = False


run('nmcli', 'connection', 'add', 'type', 'vlan', 'ifname', 'cmp-back124', 'dev', 'cmp-test0',
    'id', '124', 'con-name', 'cmp-backend', 'connection.autoconnect', 'no', 'ipv4.method', 'manual',
    'ipv4.addresses', '198.19.10.2/24', 'ipv4.never-default', 'yes', 'ipv6.method', 'disabled')
try:
    run('nmcli', '--wait', '10', 'connection', 'up', 'cmp-backend')
    connection = run('nmcli', '-g', 'connection.uuid', 'connection', 'show', 'cmp-backend')
    store = store_module.Store('/var/lib/lab-backend', '/run/lab-backend')
    store.initialize()
    scheduler = Scheduler()
    backend = backend_module.Backend(store, profile, scheduler, lambda: False, run=run)
    tx = transaction.NetworkTransaction(backend)
    request = {'uuid': connection, 'device': 'cmp-back124', 'profile': {'ipv4': {
        'method': 'manual', 'addresses': ['198.19.11.2/24'], 'never_default': True}}}
    # The parent carries provisioning and must not be changed by this transaction type.
    parent_uuid = run('nmcli', '-g', 'connection.uuid', 'connection', 'show', 'cmp-native')
    try:
        backend.validate({'uuid': parent_uuid, 'device': 'cmp-test0', 'profile': request['profile']})
        raise AssertionError('the provisioning interface was accepted')
    except backend_module.BackendError:
        pass
    # DHCP or a pre-existing profile could install a more-specific route that diverts a module.
    run('ip', 'route', 'add', '172.20.1.0/24', 'dev', 'cmp-back124')
    try:
        backend._check_stand_routes()
        raise AssertionError('a route diverting provisioning traffic was accepted')
    except backend_module.BackendError:
        pass
    finally:
        run('ip', 'route', 'delete', '172.20.1.0/24', 'dev', 'cmp-back124')
    original_dump = run('nmcli', '--escape', 'no', '-t', '-f', 'profile', 'connection', 'show', 'uuid', connection)
    token = tx.begin(request)
    try:
        tx.recover()
    except backend_module.BackendError:
        current_dump = run('nmcli', '--escape', 'no', '-t', '-f', 'profile', 'connection', 'show', 'uuid', connection)
        print('\n'.join(difflib.unified_diff(original_dump.splitlines(), current_dump.splitlines())))
        raise
    assert run('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'uuid', connection) == '198.19.10.2/24'
    assert not store.pending() and not scheduler.pending
    # Failure after the confirmed profile was persisted restores the prior saved profile.
    real_commit = backend.commit_checkpoint
    backend.commit_checkpoint = lambda _: (_ for _ in ()).throw(backend_module.BackendError('simulated failure'))
    token = tx.begin(request)
    try:
        tx.confirm(token)
        raise AssertionError('the commit should have failed')
    except backend_module.BackendError:
        pass
    assert run('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'uuid', connection) == '198.19.10.2/24'
    assert not store.pending()
    backend.commit_checkpoint = real_commit
    # An external temporary edit to an unrelated property must not be lost to checkpoint rollback.
    original_priority = run('nmcli', '-g', 'connection.autoconnect-priority', 'connection', 'show', 'uuid', connection)
    token = tx.begin(request)
    run('nmcli', 'connection', 'modify', '--temporary', 'uuid', connection, 'connection.autoconnect-priority', '77')
    try:
        tx.cancel(token)
        raise AssertionError('an unrelated external profile edit was overwritten')
    except backend_module.BackendError:
        pass
    assert store.pending() and run('nmcli', '-g', 'connection.autoconnect-priority', 'connection', 'show', 'uuid', connection) == '77'
    run('nmcli', 'connection', 'modify', '--temporary', 'uuid', connection, 'connection.autoconnect-priority', original_priority)
    tx.cancel(token)
    assert not store.pending()
    token = tx.begin(request)
    tx.confirm(token)
    assert run('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'uuid', connection) == '198.19.11.2/24'
    assert not store.pending()
    # A crash between writing and recording the hash is recoverable when NM's staged keyfile
    # predicts the exact persisted bytes, without overwriting an unrelated change.
    request['profile']['ipv4']['addresses'] = ['198.19.12.2/24']
    tx.begin(request)
    state = store.load()
    assert state['applied']['expected_file_hash'] is not None
    backend.persist(state['change'])
    assert backend.snapshot(state['change'])['file_hash'] == state['applied']['expected_file_hash']
    tx.recover()
    assert not store.pending()
    assert run('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'uuid', connection) == '198.19.11.2/24'
    # A genuinely unrelated disk edit must never be overwritten by automatic recovery.
    tx.begin(request)
    state = store.load()
    with open(state['change']['filename'], 'ab') as keyfile:
        keyfile.write(b'\n# external administrative edit\n')
    try:
        tx.recover()
        raise AssertionError('an unknown changed keyfile was overwritten')
    except backend_module.BackendError:
        pass
    assert store.pending() and store.load() is not None
finally:
    subprocess.call(['nmcli', 'connection', 'delete', 'cmp-backend'], stdout=subprocess.DEVNULL)
print('PASS: experimental NM backend validates, rolls back, confirms and fails closed on ambiguous keyfile changes')
