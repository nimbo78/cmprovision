#!/usr/bin/env python3
"""Use an actual DHCP server in a child network namespace, never the host network."""
import importlib.machinery
import json
import os
import subprocess
import sys
import time

profile = importlib.machinery.SourceFileLoader('network_profile', sys.argv[1]).load_module()
transaction = importlib.machinery.SourceFileLoader('network_transaction', sys.argv[2]).load_module()
store_module = importlib.machinery.SourceFileLoader('network_store', sys.argv[3]).load_module()
backend_module = importlib.machinery.SourceFileLoader('network_backend', sys.argv[4]).load_module()
os.environ['PATH'] = '/run/lab-bin:/usr/sbin:/usr/bin:/sbin:/bin'


def run(*args):
    if args[0] == 'busctl': args = ('busctl', '--address=' + os.environ['DBUS_SYSTEM_BUS_ADDRESS'], *args[1:])
    result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=30)
    if result.returncode: raise backend_module.BackendError(result.stderr.strip())
    return result.stdout.strip()


class Scheduler:
    def schedule(self, _): pass
    def cancel(self, _): pass


keeper = subprocess.Popen(['unshare', '--net', '--', 'sleep', '90'])
server = None
try:
    time.sleep(.2)
    if keeper.poll() is not None: raise RuntimeError('Could not start the child DHCP network namespace.')
    run('ip', 'link', 'add', 'link', 'cmp-peer0', 'name', 'cmp-dhcp-peer', 'type', 'vlan', 'id', '124')
    run('ip', 'link', 'set', 'cmp-dhcp-peer', 'netns', str(keeper.pid))
    enter = ['nsenter', '-t', str(keeper.pid), '-n', '--']
    subprocess.check_call(enter + ['ip', 'link', 'set', 'lo', 'up'])
    subprocess.check_call(enter + ['ip', 'link', 'set', 'cmp-dhcp-peer', 'up'])
    subprocess.check_call(enter + ['ip', 'address', 'add', '198.19.55.1/24', 'dev', 'cmp-dhcp-peer'])
    log = open('/run/lab-remote-dhcp.log', 'w', encoding='utf-8')
    server = subprocess.Popen(enter + ['dnsmasq', '--keep-in-foreground', '--port=0', '--no-hosts', '--no-resolv',
        '--user=root', '--group=root', '--interface=cmp-dhcp-peer', '--bind-interfaces',
        '--dhcp-range=198.19.55.20,198.19.55.30,255.255.255.0,5m', '--dhcp-leasefile=/run/lab-remote.leases',
        '--no-ping'], stdout=log, stderr=subprocess.STDOUT)
    time.sleep(.4)
    if server.poll() is not None: raise RuntimeError('The private DHCP server failed to start.')
    run('nmcli', 'connection', 'add', 'type', 'vlan', 'ifname', 'cmp-dhcp124', 'dev', 'cmp-test0', 'id', '124',
        'con-name', 'cmp-dhcp', 'connection.autoconnect', 'no', 'ipv4.method', 'manual',
        'ipv4.addresses', '198.19.40.2/24', 'ipv4.never-default', 'yes', 'ipv6.method', 'disabled')
    run('nmcli', '--wait', '10', 'connection', 'up', 'cmp-dhcp')
    connection = run('nmcli', '-g', 'connection.uuid', 'connection', 'show', 'cmp-dhcp')
    files = store_module.Store('/var/lib/lab-dhcp', '/run/lab-dhcp')
    files.initialize()
    b = backend_module.Backend(files, profile, Scheduler(), lambda: False, run=run)
    tx = transaction.NetworkTransaction(b)
    token = tx.begin({'uuid': connection, 'device': 'cmp-dhcp124', 'profile': {'ipv4': {
        'method': 'auto', 'never_default': True}}})
    addresses = json.loads(run('ip', '-j', '-4', 'address', 'show', 'dev', 'cmp-dhcp124'))
    assert any(a.get('local', '').startswith('198.19.55.') for a in addresses[0]['addr_info'])
    tx.cancel(token)
    assert run('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'uuid', connection) == '198.19.40.2/24'
    assert not files.pending()
    print('PASS: DHCP lease acquired on tagged management VLAN and cancellation restores the static profile')
    # A different DHCP server offering the provisioning subnet must be rejected and rolled back.
    server.terminate()
    server.wait(timeout=5)
    log.close()
    server = None
    subprocess.check_call(enter + ['ip', 'address', 'delete', '198.19.55.1/24', 'dev', 'cmp-dhcp-peer'])
    subprocess.check_call(enter + ['ip', 'address', 'add', '172.20.88.1/16', 'dev', 'cmp-dhcp-peer'])
    log = open('/run/lab-conflicting-dhcp.log', 'w', encoding='utf-8')
    server = subprocess.Popen(enter + ['dnsmasq', '--keep-in-foreground', '--port=0', '--no-hosts', '--no-resolv',
        '--user=root', '--group=root', '--interface=cmp-dhcp-peer', '--bind-interfaces',
        '--dhcp-range=172.20.88.100,172.20.88.110,255.255.0.0,5m', '--dhcp-leasefile=/run/lab-conflicting.leases',
        '--no-ping'], stdout=log, stderr=subprocess.STDOUT)
    time.sleep(.4)
    if server.poll() is not None: raise RuntimeError('The conflicting DHCP server failed to start.')
    try:
        tx.begin({'uuid': connection, 'device': 'cmp-dhcp124', 'profile': {'ipv4': {
            'method': 'auto', 'never_default': True}}})
        raise AssertionError('a DHCP lease overlapping the provisioning subnet was accepted')
    except backend_module.BackendError as exc:
        assert 'provisioning network' in str(exc), str(exc)
    assert not files.pending()
    assert run('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'uuid', connection) == '198.19.40.2/24'
    print('PASS: conflicting DHCP lease is rejected and the previous profile is restored')
    # A valid address can still carry a classless route that diverts part of the stand subnet.
    server.terminate()
    server.wait(timeout=5)
    log.close()
    server = None
    subprocess.check_call(enter + ['ip', 'address', 'delete', '172.20.88.1/16', 'dev', 'cmp-dhcp-peer'])
    subprocess.check_call(enter + ['ip', 'address', 'add', '198.19.55.1/24', 'dev', 'cmp-dhcp-peer'])
    log = open('/run/lab-conflicting-route-dhcp.log', 'w', encoding='utf-8')
    server = subprocess.Popen(enter + ['dnsmasq', '--keep-in-foreground', '--port=0', '--no-hosts', '--no-resolv',
        '--user=root', '--group=root', '--interface=cmp-dhcp-peer', '--bind-interfaces',
        '--dhcp-range=198.19.55.20,198.19.55.30,255.255.255.0,5m',
        '--dhcp-option=121,172.20.1.0/24,198.19.55.1', '--dhcp-leasefile=/run/lab-conflicting-route.leases',
        '--no-ping'], stdout=log, stderr=subprocess.STDOUT)
    time.sleep(.4)
    if server.poll() is not None: raise RuntimeError('The conflicting-route DHCP server failed to start.')
    try:
        tx.begin({'uuid': connection, 'device': 'cmp-dhcp124', 'profile': {'ipv4': {
            'method': 'auto', 'never_default': True}}})
        raise AssertionError('a DHCP route diverting provisioning traffic was accepted')
    except backend_module.BackendError as exc:
        assert 'divert provisioning traffic' in str(exc), str(exc)
    assert not files.pending()
    assert run('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'uuid', connection) == '198.19.40.2/24'
    print('PASS: conflicting DHCP route is rejected and the previous profile is restored')
finally:
    subprocess.call(['nmcli', 'connection', 'delete', 'cmp-dhcp'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if server is not None:
        server.terminate()
        server.wait(timeout=5)
        log.close()
    keeper.terminate()
    keeper.wait(timeout=5)
