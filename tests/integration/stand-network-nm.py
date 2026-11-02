#!/usr/bin/env python3
"""Move the stand between native Ethernet and VLAN in a private network namespace."""
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
stand_module = importlib.machinery.SourceFileLoader('stand_backend', sys.argv[5]).load_module()
binding_module = importlib.machinery.SourceFileLoader('stand_binding', sys.argv[6]).load_module()
dnsmasq_start = importlib.machinery.SourceFileLoader('dnsmasq_start', sys.argv[7]).load_module()
router_module = importlib.machinery.SourceFileLoader('network_router', sys.argv[8]).load_module()
os.makedirs('/run/lab-bin', exist_ok=True)
if not os.path.exists('/run/lab-bin/nmcli'):
    os.symlink(os.environ.get('CMP_LAB_NMCLI', '/usr/bin/nmcli'), '/run/lab-bin/nmcli')
os.environ['PATH'] = '/run/lab-bin:/usr/sbin:/usr/bin:/sbin:/bin'


def run(*args):
    if args[0] == 'busctl': args = ('busctl', '--address=' + os.environ['DBUS_SYSTEM_BUS_ADDRESS'], *args[1:])
    result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=30)
    if result.returncode: raise backend_module.BackendError(result.stderr.strip())
    return result.stdout.strip()


class Scheduler:
    def schedule(self, _): pass
    def cancel(self, _): pass


class LabBinding(binding_module.Binding):
    def validate(self, _): pass  # separate tests exercise real config validation as www-data


class LabService:
    def __init__(self, binding):
        self.binding, self.process, self.starts = binding, None, 0

    def active(self): return self.process is not None and self.process.poll() is None

    def stop(self):
        if self.active():
            self.process.terminate()
            self.process.wait(timeout=5)
        self.process = None

    def reconcile(self, should_be_active):
        if not should_be_active:
            if self.active(): raise RuntimeError('a disabled test service was started elsewhere')
            return
        self.stop()
        interface = self.binding.read() or 'cmp-test0'
        self.process = subprocess.Popen(['dnsmasq', '--keep-in-foreground', '--port=0', '--no-hosts', '--no-resolv',
            '--user=root', '--group=root', '--interface=' + interface, '--bind-dynamic', '--enable-tftp',
            '--tftp-root=/run/tftp', '--dhcp-range=172.20.0.10,172.20.0.20,255.255.0.0,5m',
            '--dhcp-leasefile=/run/lab-stand.leases', '--no-ping'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.starts += 1
        time.sleep(.3)
        if not self.active(): raise RuntimeError('test dnsmasq failed to start')


def probe(script, device, expectation):
    subprocess.check_call(['python3', os.path.join(os.path.dirname(__file__), script), device, expectation])


def address_on(device, ip):
    data = json.loads(run('ip', '-j', '-4', 'address', 'show'))
    return any(d.get('ifname') == device and any(a.get('local') == ip for a in d.get('addr_info', [])) for d in data)


os.kill(int(os.environ['CMP_LAB_DNS_PID']), signal.SIGTERM)
time.sleep(.3)
binding = LabBinding(dnsmasq_start, '/run/lab-stand-interface')
service = LabService(binding)
service.reconcile(True)
run('nmcli', 'connection', 'add', 'type', 'vlan', 'ifname', 'cmp-test0.42', 'dev', 'cmp-test0',
    'id', '42', 'con-name', 'cmp-stand-target', 'connection.autoconnect', 'no', 'ipv4.method', 'disabled',
    'ipv4.never-default', 'yes', 'ipv6.method', 'disabled')
try:
    run('nmcli', '--wait', '10', 'connection', 'up', 'cmp-stand-target')
    source = run('nmcli', '-g', 'connection.uuid', 'connection', 'show', 'cmp-native')
    target = run('nmcli', '-g', 'connection.uuid', 'connection', 'show', 'cmp-stand-target')
    files = store_module.Store('/var/lib/lab-stand', '/run/lab-stand')
    files.initialize()
    common = backend_module.Backend(files, profile, Scheduler(), lambda: False, run=run)
    b = router_module.Router(common, stand_module.Backend(common, binding, service))
    tx = transaction.NetworkTransaction(b)
    forward = {'kind': 'stand', 'source_device': 'cmp-test0', 'source_uuid': source,
               'target_device': 'cmp-test0.42', 'target_uuid': target}
    backward = {'kind': 'stand', 'source_device': 'cmp-test0.42', 'source_uuid': target,
                'target_device': 'cmp-test0', 'target_uuid': source}
    # Failure after staging only the first profile must not strand the provisioning service.
    normal_stage = b.stand.stage_temporary
    def fail_halfway(change):
        common.stage_temporary(change['source'])
        state = files.load()
        state['partial'] = {'source': common.snapshot(change['source'])}
        files.store(state)
        raise stand_module.StandError('simulated interrupted staging')
    b.stand.stage_temporary = fail_halfway
    try:
        tx.begin(forward)
        raise AssertionError('the interrupted stage did not fail')
    except stand_module.StandError:
        pass
    b.stand.stage_temporary = normal_stage
    assert not files.pending() and address_on('cmp-test0', '172.20.0.1') and binding.read() is None
    token = tx.begin(forward)
    assert address_on('cmp-test0', '192.0.2.10') and address_on('cmp-test0.42', '172.20.0.1')
    assert binding.read() == 'cmp-test0.42'
    probe('dhcp-probe.py', 'cmp-client42', 'offer')
    probe('tftp-probe.py', 'cmp-client42', 'data')
    probe('dhcp-probe.py', 'cmp-peer0', 'none')
    tx.cancel(token)
    assert address_on('cmp-test0', '172.20.0.1') and binding.read() is None
    probe('dhcp-probe.py', 'cmp-peer0', 'offer')
    # A concurrent administrator edit to a property outside the move must not be overwritten.
    priority = run('nmcli', '-g', 'connection.autoconnect-priority', 'connection', 'show', 'uuid', target)
    token = tx.begin(forward)
    run('nmcli', 'connection', 'modify', '--temporary', 'uuid', target, 'connection.autoconnect-priority', '77')
    try:
        tx.cancel(token)
        raise AssertionError('an external target profile edit was overwritten')
    except stand_module.StandError:
        pass
    assert files.pending() and run('nmcli', '-g', 'connection.autoconnect-priority', 'connection', 'show', 'uuid', target) == '77'
    run('nmcli', 'connection', 'modify', '--temporary', 'uuid', target, 'connection.autoconnect-priority', priority)
    tx.cancel(token)
    assert not files.pending() and address_on('cmp-test0', '172.20.0.1')
    # Simulate a crash after only the first of the two profiles was persisted.
    tx.begin(forward)
    state = files.load()
    common.persist(state['change']['source'])
    tx.recover()
    assert address_on('cmp-test0', '172.20.0.1') and binding.read() is None
    token = tx.begin(forward)
    tx.confirm(token)
    assert address_on('cmp-test0.42', '172.20.0.1') and binding.read() == 'cmp-test0.42'
    token = tx.begin(backward)
    tx.confirm(token)
    assert address_on('cmp-test0', '172.20.0.1') and binding.read() == 'cmp-test0'
    # A station with DHCP/TFTP intentionally stopped must never have it started by a move.
    service.stop()
    starts = service.starts
    token = tx.begin(forward)
    tx.confirm(token)
    assert not service.active() and service.starts == starts
    token = tx.begin(backward)
    tx.confirm(token)
    assert not service.active() and service.starts == starts
    assert address_on('cmp-test0', '192.0.2.10') and address_on('cmp-test0.99', '198.51.100.10')
    # A secondary station carries .2, and the same move must leave its DHCP/TFTP service stopped.
    run('nmcli', 'connection', 'modify', 'cmp-native', 'ipv4.addresses', '192.0.2.10/24,172.20.0.2/16')
    run('nmcli', 'device', 'reapply', 'cmp-test0')
    time.sleep(.5)
    token = tx.begin(forward)
    tx.confirm(token)
    assert address_on('cmp-test0.42', '172.20.0.2') and not service.active()
    token = tx.begin(backward)
    tx.confirm(token)
    assert address_on('cmp-test0', '172.20.0.2') and not service.active()
    service.reconcile(True)
    try:
        tx.begin(forward)
        raise AssertionError('the secondary station was allowed to run DHCP/TFTP')
    except stand_module.StandError:
        pass
    service.stop()
    print('PASS: stand VLAN move, cancel, partial commit recovery and disabled-service preservation')
finally:
    service.stop()
    subprocess.call(['nmcli', 'connection', 'delete', 'cmp-stand-target'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
