#!/usr/bin/env python3
"""Simulate power loss halfway through a two-profile stand move in a private namespace."""
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


class Binding(binding_module.Binding):
    def validate(self, _): pass


class Service:
    def __init__(self, binding): self.binding, self.process = binding, None
    def active(self): return self.process is not None and self.process.poll() is None
    def stop(self):
        if self.active():
            self.process.terminate()
            self.process.wait(timeout=5)
        self.process = None
    def reconcile(self, should_be_active):
        if not should_be_active:
            if self.active(): raise RuntimeError('unexpected active dnsmasq')
            return
        self.stop()
        interface = self.binding.read() or 'cmp-test0'
        self.process = subprocess.Popen(['dnsmasq', '--keep-in-foreground', '--port=0', '--no-hosts', '--no-resolv',
            '--user=root', '--group=root', '--interface=' + interface, '--bind-dynamic', '--enable-tftp',
            '--tftp-root=/run/tftp', '--dhcp-range=172.20.0.10,172.20.0.20,255.255.0.0,5m',
            '--dhcp-leasefile=/run/lab-stand-reboot.leases', '--no-ping'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        time.sleep(.3)
        if not self.active(): raise RuntimeError('test dnsmasq failed')


os.kill(int(os.environ['CMP_LAB_DNS_PID']), signal.SIGTERM)
time.sleep(.3)
binding = Binding(dnsmasq_start, '/etc/lab-stand-interface')
service = Service(binding)
service.reconcile(True)
run('nmcli', 'connection', 'add', 'type', 'vlan', 'ifname', 'cmp-test0.42', 'dev', 'cmp-test0',
    'id', '42', 'con-name', 'cmp-stand-reboot', 'connection.autoconnect', 'no', 'ipv4.method', 'disabled',
    'ipv4.never-default', 'yes', 'ipv6.method', 'disabled')
run('nmcli', '--wait', '10', 'connection', 'up', 'cmp-stand-reboot')
source = run('nmcli', '-g', 'connection.uuid', 'connection', 'show', 'cmp-native')
target = run('nmcli', '-g', 'connection.uuid', 'connection', 'show', 'cmp-stand-reboot')
files = store_module.Store('/var/lib/lab-stand-reboot', '/run/lab-stand-reboot')
files.initialize()
common = backend_module.Backend(files, profile, Scheduler(), lambda: False, run=run)
tx = transaction.NetworkTransaction(router_module.Router(common, stand_module.Backend(common, binding, service)))
request = {'kind': 'stand', 'source_device': 'cmp-test0', 'source_uuid': source,
           'target_device': 'cmp-test0.42', 'target_uuid': target}
tx.begin(request)
state = files.load()
common.persist(state['change']['source'])  # power loss before the target profile is persisted
os.kill(int(os.environ['CMP_LAB_NM_PID']), signal.SIGKILL)
service.stop()
time.sleep(.5)
run('ip', 'link', 'delete', 'dev', 'cmp-test0.42')
os.rename('/run/NetworkManager', '/run/NetworkManager-before-stand-reboot')
os.mkdir('/run/NetworkManager', 0o755)
os.rename('/run/lab-stand-reboot', '/run/lab-stand-reboot-before')
files = store_module.Store('/var/lib/lab-stand-reboot', '/run/lab-stand-reboot')
files.initialize()
assert files.pending() and binding.read() == 'cmp-test0.42'
log = open('/run/nm-stand-reboot.log', 'w', encoding='utf-8')
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
    common = backend_module.Backend(files, profile, Scheduler(), lambda: False, run=run)
    tx = transaction.NetworkTransaction(router_module.Router(common, stand_module.Backend(common, binding, service)))
    tx.recover()
    assert not files.pending() and binding.read() is None and service.active()
    assert '172.20.0.1/16' in run('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'uuid', source)
    assert run('nmcli', '-g', 'ipv4.method', 'connection', 'show', 'uuid', target) == 'disabled'
    run('nmcli', '--wait', '10', 'connection', 'up', 'uuid', source)
    subprocess.check_call(['python3', os.path.join(os.path.dirname(__file__), 'dhcp-probe.py'), 'cmp-peer0', 'offer'])
    print('PASS: reboot during two-profile stand save restores the original profile and DHCP service')
finally:
    service.stop()
    nm.terminate()
    try:
        nm.wait(timeout=5)
    except subprocess.TimeoutExpired:
        nm.kill()
    log.close()
