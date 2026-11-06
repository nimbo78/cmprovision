#!/usr/bin/env python3
"""Real NM/ARP/DHCP/TFTP role tests in the private lab; systemd operations are simulated."""
import importlib.machinery
import json
import os
import signal
import socket
import struct
import subprocess
import sys
import time
import threading


def module(name, filename):
    return importlib.machinery.SourceFileLoader(name, os.path.join(sys.argv[1], filename)).load_module()


profile = module('role_profile', 'network-profile.py')
transaction = module('role_transaction', 'network-transaction.py')
storage = module('role_store', 'network-transaction-store.py')
common_module = module('role_common', 'network-transaction-backend.py')
stand_module = module('role_stand', 'stand-network-backend.py')
binding_module = module('role_binding', 'stand-network-binding.py')
dnsmasq = module('role_dnsmasq', 'dnsmasq-start')
role_module = module('role_backend', 'stand-role.py')
router = module('role_router', 'network-transaction-router.py')
os.makedirs('/run/lab-bin', exist_ok=True)
os.symlink(os.environ['CMP_LAB_NMCLI'], '/run/lab-bin/nmcli')
os.environ['PATH'] = '/run/lab-bin:/usr/sbin:/usr/bin:/sbin:/bin'


def run(*args):
    if args[0] == 'busctl': args = ('busctl', '--address=' + os.environ['DBUS_SYSTEM_BUS_ADDRESS'], *args[1:])
    result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=30)
    if result.returncode: raise common_module.BackendError(result.stderr.strip())
    return result.stdout.strip()


class Scheduler:
    def schedule(self, _): pass
    def cancel(self, _): pass


class Binding(binding_module.Binding):
    def validate(self, _): pass  # config validation as www-data has its own tests


class LabSystemd:
    def __init__(self, binding):
        self.binding, self.process = binding, None
        self.states = {'dhcp': {'active': False, 'masked': True, 'wanted': True},
                       'usb': {'active': False, 'masked': True, 'wanted': False}}
        self.keys = {unit: key for key, unit in role_module.Services.UNITS.items()}
        self.fail_usb_enable = False

    def stop_dhcp(self):
        if self.process is not None and self.process.poll() is None:
            self.process.terminate()
            self.process.wait(timeout=5)
        self.process = None
        self.states['dhcp']['active'] = False

    def run(self, *args):
        assert args[0] == 'systemctl', args
        command, *rest = [a for a in args[1:] if a != '--no-block']
        if command == 'show':
            value = self.states[self.keys[rest[0]]]
            return 'LoadState=' + ('masked' if value['masked'] else 'loaded') + '\nActiveState=' + \
                ('active' if value['active'] else 'inactive') + '\nUnitFileState=' + \
                ('masked' if value['masked'] else ('enabled' if value['wanted'] else 'disabled'))
        for unit in rest:
            key = self.keys[unit]
            value = self.states[key]
            if command == 'enable' and key == 'usb' and self.fail_usb_enable:
                self.fail_usb_enable = False
                raise RuntimeError('simulated failure after partial service persistence')
            if command in ('enable', 'disable'): value['wanted'] = command == 'enable'
            elif command in ('mask', 'unmask'): value['masked'] = command == 'mask'
            elif command in ('stop', 'restart'):
                if key == 'dhcp': self.stop_dhcp()
                value['active'] = False
                if command == 'restart':
                    assert not value['masked']
                    if key == 'dhcp':
                        self.process = subprocess.Popen(['dnsmasq', '--keep-in-foreground', '--port=0', '--no-hosts', '--no-resolv',
                            '--user=root', '--group=root', '--interface=' + self.binding.read(), '--bind-dynamic', '--enable-tftp',
                            '--tftp-root=/run/tftp', '--dhcp-range=172.20.0.11,172.20.0.20,255.255.0.0,5m',
                            '--dhcp-leasefile=/run/lab-role.leases', '--no-ping'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                        time.sleep(.3)
                        assert self.process.poll() is None, 'test DHCP failed to start'
                    value['active'] = True
            else: raise AssertionError(args)
        return ''


def probe(script, device, expectation):
    subprocess.check_call(['python3', os.path.join(os.path.dirname(__file__), script), device, expectation])


def holds(device, address):
    data = json.loads(run('ip', '-j', '-4', 'address', 'show'))
    return any(d.get('ifname') == device and any(a.get('local') == address for a in d.get('addr_info', [])) for d in data)


def duplicate_arp_peer(device, address, ready, stopped):
    # Model a separate host instead of assigning the duplicate to this namespace,
    # which would be caught earlier as a second local stand interface.
    with open('/sys/class/net/' + device + '/address') as source:
        mac = bytes.fromhex(source.read().strip().replace(':', ''))
    with socket.socket(socket.AF_PACKET, socket.SOCK_RAW, socket.htons(0x0806)) as channel:
        channel.bind((device, 0))
        channel.settimeout(.1)
        ready.set()
        while not stopped.is_set():
            try: frame = channel.recv(2048)
            except socket.timeout: continue
            if len(frame) < 42 or frame[20:22] != b'\x00\x01' or frame[38:42] != socket.inet_aton(address):
                continue
            payload = struct.pack('!HHBBH', 1, 0x0800, 6, 4, 2) + mac + socket.inet_aton(address) + frame[22:28] + frame[28:32]
            channel.send(frame[22:28] + mac + bytes.fromhex('0806') + payload)


os.kill(int(os.environ['CMP_LAB_DNS_PID']), signal.SIGTERM)
time.sleep(.3)
native = run('nmcli', '-g', 'connection.uuid', 'connection', 'show', 'cmp-native')
run('nmcli', 'connection', 'modify', 'uuid', native, 'ipv4.addresses', '192.0.2.10/24,172.20.0.2/16')
run('nmcli', 'device', 'reapply', 'cmp-test0')
binding = Binding(dnsmasq, '/etc/lab-role-interface')
binding.write('cmp-test0')
systemd = LabSystemd(binding)
services = role_module.Services(systemd.run, boot_link=lambda unit: systemd.states[systemd.keys[unit]]['wanted'])
os.makedirs('/var/lib/lab-role', mode=0o700)
files = storage.Store('/var/lib/lab-role/transactions', '/run/lab-role')
files.initialize()
common = common_module.Backend(files, profile, Scheduler(), lambda: False, run=run)
stand = stand_module.Backend(common, binding, None)
role = role_module.Backend(common, stand, services)
tx = transaction.NetworkTransaction(router.Router(common, stand, role=role))
request = {'kind': 'stand-role', 'device': 'cmp-test0', 'uuid': native, 'role': 'primary', 'usb': True, 'isolated': True}
initial = services.snapshot()
restarted, nm_log = None, None
try:
    try:
        tx.begin({**request, 'isolated': False})
        raise AssertionError('primary role did not require an isolated network')
    except role_module.RoleError:
        pass
    token = tx.begin(request)
    assert holds('cmp-test0', '172.20.0.1')
    probe('dhcp-probe.py', 'cmp-peer0', 'offer')
    # DHCP's boot link remains true throughout this particular operation. An external
    # disable must not be mistaken for one of our own partial service writes.
    systemd.states['dhcp']['wanted'] = False
    try:
        tx.cancel(token)
        raise AssertionError('an unrelated service enablement change was overwritten')
    except role_module.RoleError:
        pass
    assert files.pending() and not services.snapshot()['dhcp']['boot_enabled']
    systemd.states['dhcp']['wanted'] = True
    tx.cancel(token)
    assert holds('cmp-test0', '172.20.0.2') and services.snapshot() == initial, (role._holders(), services.snapshot(), initial)
    probe('dhcp-probe.py', 'cmp-peer0', 'none')
    token = tx.begin(request)
    tx.confirm(token)
    primary = services.snapshot()
    assert all(s['active'] and s['enabled'] == 'enabled' and s['boot_enabled'] for s in primary.values())
    secondary = {**request, 'role': 'secondary', 'usb': False, 'isolated': False}
    token = tx.begin(secondary)
    assert holds('cmp-test0', '172.20.0.2') and not services.snapshot()['dhcp']['active']
    tx.cancel(token)
    assert holds('cmp-test0', '172.20.0.1') and services.snapshot() == primary
    token = tx.begin(secondary)
    tx.confirm(token)
    disabled = {**secondary, 'role': 'disabled'}
    token = tx.begin(disabled)
    assert not role._holders() and holds('cmp-test0', '192.0.2.10')
    tx.cancel(token)
    assert holds('cmp-test0', '172.20.0.2')
    token = tx.begin(disabled)
    tx.confirm(token)
    assert binding.read() is None
    run('nmcli', 'connection', 'add', 'type', 'vlan', 'ifname', 'cmp-role42', 'dev', 'cmp-test0', 'id', '42',
        'con-name', 'cmp-role-fresh', 'ipv4.method', 'disabled', 'ipv6.method', 'disabled', 'connection.autoconnect', 'yes')
    run('nmcli', '--wait', '10', 'connection', 'up', 'cmp-role-fresh')
    tagged = run('nmcli', '-g', 'connection.uuid', 'connection', 'show', 'cmp-role-fresh')
    fresh = {**request, 'device': 'cmp-role42', 'uuid': tagged}
    ready, stopped = threading.Event(), threading.Event()
    peer = threading.Thread(target=duplicate_arp_peer, args=('cmp-client42', '172.20.0.1', ready, stopped))
    peer.start()
    assert ready.wait(2)
    try:
        tx.begin(fresh)
        raise AssertionError('a duplicate station address was accepted')
    except role_module.RoleError as exc:
        assert 'Another device' in str(exc), str(exc)
    finally:
        stopped.set()
        peer.join(timeout=2)
    token = tx.begin(fresh)
    probe('dhcp-probe.py', 'cmp-client42', 'offer')
    probe('tftp-probe.py', 'cmp-client42', 'data')
    probe('dhcp-probe.py', 'cmp-peer0', 'none')
    tx.cancel(token)
    assert not role._holders() and binding.read() is None
    # An IPv6-connected interface can have DHCPv4 enabled but no DHCP server.
    # Cancelling initial stand setup must not wait for a lease that never existed.
    run('nmcli', 'connection', 'modify', 'uuid', tagged, 'ipv4.method', 'auto',
        'ipv4.dhcp-timeout', '1', 'ipv4.may-fail', 'yes', 'ipv6.method', 'link-local')
    run('nmcli', '--wait', '20', 'connection', 'up', 'uuid', tagged)
    token = tx.begin(fresh)
    tx.cancel(token)
    assert not files.pending() and not role._holders()
    assert run('nmcli', '-g', 'ipv4.method', 'connection', 'show', 'uuid', tagged) == 'auto'
    run('nmcli', 'connection', 'modify', 'uuid', tagged, 'ipv4.method', 'disabled', 'ipv6.method', 'disabled')
    run('nmcli', '--wait', '10', 'connection', 'up', 'uuid', tagged)
    tx.begin(fresh)
    common.persist(files.load()['change']['profile'])
    tx.recover()
    assert not role._holders() and not files.pending()
    token = tx.begin(fresh)
    systemd.fail_usb_enable = True
    try:
        tx.confirm(token)
        raise AssertionError('partial service persistence did not fail')
    except RuntimeError as exc:
        assert 'simulated failure' in str(exc), str(exc)
    assert not role._holders() and not files.pending() and binding.read() is None
    assert all(not s['active'] and s['enabled'] == 'masked' and not s['boot_enabled'] for s in services.snapshot().values())
    token = tx.begin(fresh)
    tx.confirm(token)
    assert holds('cmp-role42', '172.20.0.1') and services.snapshot()['dhcp']['enabled'] == 'enabled'
    # Power loss after saving address/service changes, before recording a committed result.
    tx.begin({**fresh, 'role': 'secondary', 'usb': False, 'isolated': False})
    state = files.load()
    common.persist(state['change']['profile'])
    services.persistence(state['change']['services_final'])
    os.kill(int(os.environ['CMP_LAB_NM_PID']), signal.SIGKILL)
    services.stop()
    time.sleep(.5)
    run('ip', 'link', 'delete', 'dev', 'cmp-role42')
    os.rename('/run/NetworkManager', '/run/NetworkManager-before-role-reboot')
    os.mkdir('/run/NetworkManager', 0o755)
    os.rename('/run/lab-role', '/run/lab-role-before-reboot')
    files = storage.Store('/var/lib/lab-role/transactions', '/run/lab-role')
    files.initialize()
    assert files.pending()
    nm_log = open('/run/nm-role-reboot.log', 'w')
    restarted = subprocess.Popen([os.environ['CMP_LAB_NM'], '--debug', '--config=/etc/NetworkManager/NetworkManager.conf',
        '--log-level=WARN'], stdout=nm_log, stderr=subprocess.STDOUT)
    deadline = time.monotonic() + 15
    while time.monotonic() < deadline:
        try:
            run('nmcli', '-t', 'general', 'status')
            break
        except common_module.BackendError: time.sleep(.2)
    else: raise RuntimeError('NetworkManager did not restart')
    common = common_module.Backend(files, profile, Scheduler(), lambda: False, run=run)
    stand = stand_module.Backend(common, binding, None)
    role = role_module.Backend(common, stand, services)
    tx = transaction.NetworkTransaction(router.Router(common, stand, role=role))
    tx.recover()
    assert not files.pending() and holds('cmp-role42', '172.20.0.1')
    assert all(s['active'] and s['enabled'] == 'enabled' and s['boot_enabled'] for s in services.snapshot().values())
    probe('dhcp-probe.py', 'cmp-client42', 'offer')
    probe('tftp-probe.py', 'cmp-client42', 'data')
    print('PASS: station roles, first setup, ARP conflict, DHCP/TFTP, partial-save and reboot recovery')
finally:
    systemd.stop_dhcp()
    subprocess.call(['nmcli', 'connection', 'delete', 'cmp-role-fresh'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if restarted is not None:
        restarted.terminate()
        try: restarted.wait(timeout=5)
        except subprocess.TimeoutExpired: restarted.kill()
    if nm_log is not None: nm_log.close()
