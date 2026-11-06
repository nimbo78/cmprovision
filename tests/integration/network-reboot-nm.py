#!/usr/bin/env python3
"""Simulate a reboot in hybrid-vlan.sh's private namespace; never run against a host NM."""
import importlib.machinery
import json
import os
import signal
import subprocess
import sys
import time

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
    def schedule(self, _): pass
    def cancel(self, _): pass


run('nmcli', 'connection', 'add', 'type', 'vlan', 'ifname', 'cmp-reboot124', 'dev', 'cmp-test0',
    'id', '124', 'con-name', 'cmp-reboot', 'connection.autoconnect', 'no', 'ipv4.method', 'manual',
    'ipv4.addresses', '198.19.30.2/24', 'ipv4.never-default', 'yes', 'ipv6.method', 'disabled')
run('nmcli', '--wait', '10', 'connection', 'up', 'cmp-reboot')
connection = run('nmcli', '-g', 'connection.uuid', 'connection', 'show', 'cmp-reboot')
files = store_module.Store('/var/lib/lab-reboot', '/run/lab-reboot')
files.initialize()
b = backend_module.Backend(files, profile, Scheduler(), lambda: False, run=run)
tx = transaction.NetworkTransaction(b)
request = {'uuid': connection, 'device': 'cmp-reboot124', 'profile': {'ipv4': {
    'method': 'manual', 'addresses': ['198.19.31.2/24'], 'never_default': True}}}
tx.begin(request)
state = files.load()
# Simulate a power cut immediately after profile persistence, before recording the new file hash.
b.persist(state['change'])
assert b.snapshot(state['change'])['file_hash'] == state['applied']['expected_file_hash']
os.kill(int(os.environ['CMP_LAB_NM_PID']), signal.SIGKILL)
time.sleep(.5)
run('ip', 'link', 'delete', 'dev', 'cmp-reboot124')
# /run and NM's transient profiles disappear on reboot; /var/lib and /etc persist in this test.
os.rename('/run/NetworkManager', '/run/NetworkManager-before-reboot')
os.mkdir('/run/NetworkManager', 0o755)
os.rename('/run/lab-reboot', '/run/lab-reboot-before-reboot')
files = store_module.Store('/var/lib/lab-reboot', '/run/lab-reboot')
files.initialize()
assert files.pending() and os.path.exists(files.lock_path)
log = open('/run/nm-reboot.log', 'w', encoding='utf-8')
nm = subprocess.Popen([os.environ.get('CMP_LAB_NM', '/usr/sbin/NetworkManager'), '--debug',
                       '--config=/etc/NetworkManager/NetworkManager.conf', '--log-level=WARN'],
                      stdout=log, stderr=subprocess.STDOUT)
try:
    deadline = time.monotonic() + 15
    while time.monotonic() < deadline:
        try:
            run('nmcli', '-t', 'general', 'status')
            break
        except backend_module.BackendError:
            time.sleep(.2)
    else:
        raise RuntimeError('NetworkManager did not restart in the laboratory.')
    b = backend_module.Backend(files, profile, Scheduler(), lambda: False, run=run)
    transaction.NetworkTransaction(b).recover()
    assert not files.pending()
    assert run('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'uuid', connection) == '198.19.30.2/24'
    # The connection is intentionally inactive at recovery; it can be activated afterwards.
    run('nmcli', '--wait', '10', 'connection', 'up', 'uuid', connection)
    addresses = json.loads(run('ip', '-j', '-4', 'address', 'show', 'dev', 'cmp-reboot124'))
    assert any(a.get('local') == '198.19.30.2' for a in addresses[0]['addr_info'])
    print('PASS: persisted state recovers the original profile after simulated reboot and lost NM checkpoint')
finally:
    nm.terminate()
    try:
        nm.wait(timeout=5)
    except subprocess.TimeoutExpired:
        nm.kill()
    log.close()
