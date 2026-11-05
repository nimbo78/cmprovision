#!/usr/bin/env python3
"""Real NM profile switching, disconnect and recovery; run only in hybrid-vlan.sh's namespace."""
import importlib.machinery
import json
import os
import subprocess
import sys

directory = sys.argv[1]


def module(name, file):
    return importlib.machinery.SourceFileLoader(name, os.path.join(directory, file)).load_module()


profile = module('network_profile', 'network-profile.py')
transaction = module('network_transaction', 'network-transaction.py')
storage = module('network_store', 'network-transaction-store.py')
backend_module = module('network_backend', 'network-transaction-backend.py')
connection_module = module('network_connection', 'network-connection-backend.py')
router_module = module('network_router', 'network-transaction-router.py')
os.environ['PATH'] = '/run/lab-bin:/usr/sbin:/usr/bin:/sbin:/bin'


def run(*args):
    if args[0] == 'busctl': args = ('busctl', '--address=' + os.environ['DBUS_SYSTEM_BUS_ADDRESS'], *args[1:])
    result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=30)
    if result.returncode: raise backend_module.BackendError(result.stderr.strip())
    return result.stdout.strip()


class Scheduler:
    def schedule(self, _): pass
    def cancel(self, _): pass


names = ['cmp-life-a', 'cmp-life-b', 'cmp-life-c']
try:
    for i, name in enumerate(names):
        run('nmcli', 'connection', 'add', 'type', 'vlan', 'ifname', 'cmp-life124', 'dev', 'cmp-test0',
            'id', '124', 'con-name', name, 'connection.autoconnect', 'no', 'ipv4.method', 'manual',
            'ipv4.addresses', '198.19.60.' + str(i + 2) + '/24', 'ipv4.never-default', 'yes', 'ipv6.method', 'disabled')
    ids = [run('nmcli', '-g', 'connection.uuid', 'connection', 'show', name) for name in names]
    run('nmcli', '--wait', '10', 'connection', 'up', 'uuid', ids[0])
    run('nmcli', 'connection', 'modify', 'uuid', ids[0], 'connection.autoconnect', 'yes')
    files = storage.Store('/var/lib/lab-connection', '/run/lab-connection')
    files.initialize()
    common = backend_module.Backend(files, profile, Scheduler(), lambda: False, run=run)
    connections = connection_module.Backend(common)
    tx = transaction.NetworkTransaction(router_module.Router(common, None, connections))
    request = {'kind': 'connection', 'action': 'activate', 'device': 'cmp-life124', 'uuid': ids[1]}
    token = tx.begin(request)
    assert connections._current('cmp-life124') == ids[1]
    try:
        connections.manage_saved({'operation': 'delete', 'uuid': ids[2]})
        raise AssertionError('a profile could be deleted during a pending transaction')
    except connection_module.ConnectionError:
        pass
    tx.cancel(token)
    assert connections._current('cmp-life124') == ids[0]
    assert run('nmcli', '-g', 'connection.autoconnect', 'connection', 'show', 'uuid', ids[0]) == 'yes'
    assert run('nmcli', '-g', 'connection.autoconnect', 'connection', 'show', 'uuid', ids[1]) == 'no'
    edited = {**request, 'profile': {'ipv4': {'method': 'manual', 'addresses': ['198.19.60.10/24'], 'never_default': True}}}
    token = tx.begin(edited)
    assert '198.19.60.10' in run('ip', '-4', 'address', 'show', 'dev', 'cmp-life124')
    tx.cancel(token)
    assert run('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'uuid', ids[1]) == '198.19.60.3/24'
    # Persisting just one of the two profiles before a crash must remain recoverable.
    tx.begin(request)
    common.persist(files.load()['change']['profiles'][0])
    tx.recover()
    assert connections._current('cmp-life124') == ids[0] and not files.pending()
    # A third party's connection must not be silently overwritten by recovery.
    token = tx.begin(request)
    run('nmcli', '--wait', '10', 'connection', 'up', 'uuid', ids[2])
    try:
        tx.cancel(token)
        raise AssertionError('an external connection was replaced')
    except connection_module.ConnectionError:
        pass
    assert connections._current('cmp-life124') == ids[2] and files.pending()
    run('nmcli', '--wait', '10', 'connection', 'up', 'uuid', ids[1])
    tx.cancel(token)
    token = tx.begin(request)
    tx.confirm(token)
    assert connections._current('cmp-life124') == ids[1]
    try:
        connections.manage_saved({'operation': 'delete', 'uuid': ids[1]})
        raise AssertionError('an active profile was deleted')
    except connection_module.ConnectionError:
        pass
    assert run('nmcli', '-g', 'connection.autoconnect', 'connection', 'show', 'uuid', ids[0]) == 'no'
    assert run('nmcli', '-g', 'connection.autoconnect', 'connection', 'show', 'uuid', ids[1]) == 'yes'
    request['action'] = 'deactivate'
    token = tx.begin(request)
    assert connections._current('cmp-life124') is None
    tx.cancel(token)
    assert connections._current('cmp-life124') == ids[1]
    token = tx.begin(request)
    tx.confirm(token)
    assert connections._current('cmp-life124') is None
    # Reconnect an inactive VLAN whose virtual interface no longer exists, then cancel.
    request['action'] = 'activate'
    token = tx.begin(request)
    tx.cancel(token)
    assert connections._current('cmp-life124') is None
    # A profile with an overlapping address must roll back to disconnected.
    run('nmcli', 'connection', 'modify', 'uuid', ids[1], 'ipv4.addresses', '172.20.10.2/16')
    try:
        tx.begin(request)
        raise AssertionError('a conflicting stand address was activated')
    except backend_module.BackendError:
        pass
    assert connections._current('cmp-life124') is None and not files.pending()
    parent = run('nmcli', '-g', 'connection.uuid', 'connection', 'show', 'cmp-native')
    try:
        connections.validate({'kind': 'connection', 'action': 'deactivate', 'device': 'cmp-test0', 'uuid': parent})
        raise AssertionError('the stand was disconnected')
    except connection_module.ConnectionError:
        pass
    assert '172.20.0.1' in run('ip', '-4', 'address', 'show', 'dev', 'cmp-test0')
    draft = connections.manage_saved({'operation': 'create', 'device': 'cmp-peer0', 'name': 'Ethernet draft'})['created']
    assert not any(connection == draft for connection, _ in connections._active())
    assert run('nmcli', '-g', 'connection.autoconnect', 'connection', 'show', 'uuid', draft) == 'no'
    assert run('nmcli', '-g', 'ipv4.method', 'connection', 'show', 'uuid', draft) == 'disabled'
    connections.manage_saved({'operation': 'delete', 'uuid': draft})
    registry = os.path.join(os.path.dirname(files.state_dir), 'vlans')
    os.makedirs(registry, mode=0o700, exist_ok=True)
    record = os.path.join(registry, ids[2] + '.json')
    with open(record, 'w') as f: json.dump({'uuid': ids[2]}, f)
    os.chmod(record, 0o600)
    connections.manage_saved({'operation': 'delete', 'uuid': ids[2]})
    assert not os.path.exists(record)
    print('PASS: profile switch, disconnect, reconnect, partial commit and conflict recovery')
finally:
    for name in names:
        subprocess.call(['nmcli', 'connection', 'delete', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
