"""Real NM sharing fixture. Entrypoint MUST be launched by test_rescue_wifi_routing under unshare.

Ethernet veths exercise the same NM DHCP/NAT path as wireless shared mode. Physical AP/driver
acceptance is intentionally separate. The private D-Bus has no service activation directories.
"""
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time

sys.path.insert(0, str(Path(__file__).resolve().parents[2]/'tools'))
from rescue_wifi import Store
from rescue_wifi_linux import Linux, PROFILE_ID, PROFILE_UUID
from rescue_wifi_policy import DEFAULTS


class Fixture:
    def __init__(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='cmp-rescue-net-')
        self.root = Path(self.tmp.name)
        self.processes, self.logs = [], []
        self.env = dict(os.environ, DBUS_SYSTEM_BUS_ADDRESS='unix:path='+str(self.root/'bus'))

    def run(self, *argv, check=True, timeout=15, input=None):
        return subprocess.run(list(argv), text=True, capture_output=True, check=check, timeout=timeout,
                              input=input, env=self.env)

    def spawn(self, label, *argv):
        log = open(self.root/(label+'.log'), 'w')
        self.logs.append(log)
        process = subprocess.Popen(list(argv), stdout=log, stderr=log, env=self.env)
        self.processes.append(process)
        return process

    def nm(self, *argv, **options):
        return self.run('/usr/bin/nmcli', '--escape', 'no', *argv, **options)

    def wait(self, condition, seconds=10):
        deadline = time.monotonic() + seconds
        while time.monotonic() < deadline:
            if condition(): return
            time.sleep(0.1)
        raise AssertionError('Fixture service did not become ready.')

    def peer(self, name, device, address=None):
        process = self.spawn(name, 'unshare', '--net', 'sleep', '95')
        parent_inode = os.stat('/proc/self/ns/net').st_ino
        self.wait(lambda: os.stat(f'/proc/{process.pid}/ns/net').st_ino != parent_inode)
        self.run('ip', 'link', 'add', device, 'type', 'veth', 'peer', 'name', name)
        self.run('ip', 'link', 'set', name, 'netns', str(process.pid))
        self.run('ip', 'link', 'set', device, 'up')
        self.run('udevadm', 'trigger', '--action=add', '--subsystem-match=net', '--sysname-match='+device)
        self.run('udevadm', 'settle', '--timeout=5')
        self.run('nsenter', '-t', str(process.pid), '-n', 'ip', 'link', 'set', 'lo', 'up')
        self.run('nsenter', '-t', str(process.pid), '-n', 'ip', 'link', 'set', name, 'up')
        if address:
            self.run('nsenter', '-t', str(process.pid), '-n', 'ip', 'addr', 'add', address, 'dev', name)
        return process.pid

    def client(self, pid, *argv, **options):
        return self.run('nsenter', '-t', str(pid), '-n', *argv, **options)

    def setup(self):
        # Fail closed when invoked directly in the host namespace.
        if os.stat('/proc/self/ns/net').st_ino == os.stat('/proc/1/ns/net').st_ino:
            raise RuntimeError('This fixture requires a fresh network namespace.')
        self.run('mount', '--make-rprivate', '/')
        self.run('mount', '-t', 'sysfs', 'sysfs', '/sys')
        # udev initialization is required by NM 1.52. Keep its database/sockets and NM runtime private too.
        for number, target in enumerate(('/run/udev', '/run/NetworkManager', '/var/lib/NetworkManager')):
            directory = self.root/('runtime-'+str(number))
            directory.mkdir()
            Path(target).mkdir(parents=True, exist_ok=True)
            self.run('mount', '--bind', str(directory), target)
        shared_conf = self.root/'shared-conf'
        shared_conf.mkdir()
        Path('/etc/NetworkManager/dnsmasq-shared.d').mkdir(parents=True, exist_ok=True)
        self.run('mount', '--bind', str(shared_conf), '/etc/NetworkManager/dnsmasq-shared.d')
        (shared_conf/'test.conf').write_text('log-dhcp\nlog-facility=/run/NetworkManager/test-dhcp.log\n')
        self.spawn('udev', '/usr/lib/systemd/systemd-udevd', '--resolve-names=never')
        self.wait(lambda: Path('/run/udev/control').exists())
        self.run('ip', 'link', 'set', 'lo', 'up')
        initial = os.environ.get('CMP_RESCUE_FORWARD_INITIAL', '0')
        assert initial in ('0', '1')
        self.run('sysctl', '-q', '-w', 'net.ipv4.ip_forward='+initial)
        bus_config = self.root/'dbus.conf'
        bus_config.write_text(f'''<busconfig><type>system</type><listen>{self.env['DBUS_SYSTEM_BUS_ADDRESS']}</listen>
<policy context="default"><allow own="*"/><allow send_destination="*"/><allow receive_sender="*"/></policy></busconfig>''')
        self.spawn('dbus', 'dbus-daemon', '--nofork', '--config-file='+str(bus_config))
        self.wait(lambda: (self.root/'bus').exists())
        self.store = Store(self.root)
        self.store.initialize()
        self.store.directory(self.root/'etc/NetworkManager/system-connections')
        empty = self.root/'empty'
        empty.mkdir()
        config = self.root/'NetworkManager.conf'
        config.write_text(f'''[main]
plugins=keyfile
dhcp=internal
dns=none
no-auto-default=*
firewall-backend=nftables
[keyfile]
path={self.root}/etc/NetworkManager/system-connections
[connectivity]
enabled=false
''')
        self.spawn('nm', '/usr/sbin/NetworkManager', '--no-daemon', '--config='+str(config),
                   '--config-dir='+str(empty), '--system-config-dir='+str(empty),
                   '--state-file='+str(self.root/'nm-state'), '--pid-file='+str(self.root/'nm.pid'))
        self.wait(lambda: self.nm('-t', '-f', 'RUNNING', 'general', check=False).stdout.strip() == 'running')
        self.sensor = self.peer('sensor0', 'stand0', '172.20.10.5/16')
        self.engineer = self.peer('engineer0', 'ap0')
        self.run('ip', 'addr', 'add', '172.20.0.1/16', 'dev', 'stand0')
        self.run('ip', 'link', 'add', 'link', 'stand0', 'name', 'stand0.15', 'type', 'vlan', 'id', '15')
        self.run('ip', 'link', 'set', 'stand0.15', 'up')
        self.run('ip', 'addr', 'add', '198.18.15.2/24', 'dev', 'stand0.15')
        self.client(self.sensor, 'ip', 'link', 'add', 'link', 'sensor0', 'name', 'sensor0.15', 'type', 'vlan', 'id', '15')
        self.client(self.sensor, 'ip', 'link', 'set', 'sensor0.15', 'up')
        self.client(self.sensor, 'ip', 'addr', 'add', '198.18.15.3/24', 'dev', 'sensor0.15')
        for device in ('stand0', 'ap0'):
            self.run('udevadm', 'trigger', '--action=add', '--subsystem-match=net', '--sysname-match='+device)
            self.nm('device', 'set', device, 'managed', 'yes')
        self.run('udevadm', 'settle', '--timeout=5')
        self.wait(lambda: 'unmanaged' not in self.nm('-g', 'GENERAL.STATE', 'device', 'show', 'ap0').stdout)
        dhcp = self.root/'stand.conf'
        dhcp.write_text(f'''port=0
interface=stand0
bind-dynamic
dhcp-range=172.20.0.11,172.20.0.200,255.255.0.0
dhcp-option=option:router
dhcp-leasefile={self.root}/stand.leases
pid-file={self.root}/stand.pid
''')
        self.spawn('stand-dhcp', '/usr/sbin/dnsmasq', '--no-daemon', '--conf-file='+str(dhcp))
        self.wait(lambda: (self.root/'stand.leases').exists())
        self.backend = Linux(self.store, runner=self.backend_runner)
        self.config = dict(DEFAULTS, enabled=True, ssid='fixture', password='fixture-secret-1234',
                           ap_mac='02:00:00:00:00:01', client_mac='02:00:00:00:00:02', subnet='192.168.240.0/24')
        self.backend.guard(self.config, 'ap0')
        # A wired shared profile exercises actual NM DHCP/NAT without pretending veth is a Wi-Fi PHY.
        self.store.write_text(self.backend.keyfile, f'''[connection]
id={PROFILE_ID}
uuid={PROFILE_UUID}
type=ethernet
interface-name=ap0
autoconnect=false
[ipv4]
method=shared
address1=192.168.240.1/24
never-default=true
[ipv6]
method=disabled
''')
        self.nm('connection', 'load', str(self.backend.keyfile))
        self.nm('-w', '12', 'connection', 'up', 'uuid', PROFILE_UUID)
        self.backend.verify_sharing('nftables', 'ap0', '192.168.240.0/24')

    def backend_runner(self, argv, **options):
        options['env'] = dict(options.get('env', {}), DBUS_SYSTEM_BUS_ADDRESS=self.env['DBUS_SYSTEM_BUS_ADDRESS'])
        return subprocess.run(argv, **options)

    def dhcp(self, pid, device, prefix, name):
        script = self.root/(name+'.sh')
        record = self.root/(name+'.lease')
        script.write_text(f'''#!/bin/sh
case "$1" in bound|renew)
ip -4 address flush dev "$interface"
ip address add "$ip/{prefix}" dev "$interface"
[ -z "$router" ] || ip route replace default via "${{router%% *}}" dev "$interface"
printf '%s\\n%s\\n%s\\n' "$ip" "$router" "$dns" > {record}
;; esac
''')
        script.chmod(0o700)
        # dnsmasq probes candidate addresses before offering; do not time out before its conflict check completes.
        self.client(pid, 'busybox', 'udhcpc', '-i', device, '-n', '-q', '-t', '5', '-T', '2', '-s', str(script), timeout=15)
        return record.read_text().splitlines()

    def verify(self):
        capture = self.root/'capture.py'
        capture.write_text('''import socket,struct,time
s=socket.socket(socket.AF_PACKET,socket.SOCK_RAW,socket.htons(0x0003)); s.bind(('ap0',0)); s.settimeout(8)
end=time.monotonic()+8
while time.monotonic()<end:
 try:
  p=s.recv(65536)
  if p[12:14]==b'\\x08\\x06':
   print('ARP',socket.inet_ntoa(p[28:32]),socket.inet_ntoa(p[38:42]),flush=True); continue
  if p[12:14]!=b'\\x08\\x00': continue
  offset=14+(p[14]&15)*4
  if p[23]==17:
   a,b=struct.unpack('!HH',p[offset:offset+4])
   if a in (67,68): print(socket.inet_ntoa(p[26:30]),socket.inet_ntoa(p[30:34]),a,b,'bootp',p[offset+8],flush=True)
 except socket.timeout: break
''')
        self.spawn('capture', 'python3', str(capture))
        time.sleep(0.15)
        lease = self.dhcp(self.engineer, 'engineer0', 24, 'ap')
        assert lease[0].startswith('192.168.240.'), lease
        assert lease[1] == '192.168.240.1' and lease[2] == '192.168.240.1', lease
        stand = self.dhcp(self.sensor, 'sensor0', 16, 'stand')
        assert stand[0].startswith('172.20.0.'), stand
        assert not stand[1], 'Sensor fixture must not receive a default route.'
        sensor_ip = stand[0]
        self.client(self.engineer, 'ping', '-c', '1', '-W', '2', sensor_ip)
        self.client(self.engineer, 'ping', '-c', '1', '-W', '2', '198.18.15.3')
        # The sensor has no AP-subnet/default route; success proves NAT return routing.
        routes = self.client(self.sensor, 'ip', '-j', '-4', 'route', 'show').stdout
        assert 'default' not in routes and '192.168.240' not in routes, routes
        self.client(self.engineer, 'ping', '-c', '1', '-W', '2', '172.20.0.1')
        assert PROFILE_UUID in self.nm('-g', 'UUID', 'connection', 'show', '--active').stdout.splitlines()
        print('DHCP coexistence, gateway/DNS options, gatewayless endpoint and tagged VLAN 15 NAT: OK', flush=True)
        outside = self.peer('outside0', 'uplink0', '192.0.2.1/24')
        self.client(outside, 'ip', 'addr', 'add', '198.51.100.1/32', 'dev', 'outside0')
        self.run('ip', 'addr', 'add', '192.0.2.2/24', 'dev', 'uplink0')
        self.run('ip', 'route', 'add', 'default', 'via', '192.0.2.1')
        self.client(self.engineer, 'ping', '-c', '1', '-W', '2', '198.51.100.1')
        print('Upstream sharing resumes when a default route becomes available: OK', flush=True)

        # Source spoofing must be blocked on INPUT without blocking ordinary endpoint forwarding.
        receiver = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        receiver.bind(('0.0.0.0', 18081))
        receiver.settimeout(1)
        sender = 'import socket; s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM); s.sendto(b"ok",("172.20.0.1",18081))'
        self.client(self.engineer, 'python3', '-c', sender)
        assert receiver.recvfrom(32)[0] == b'ok'
        self.client(self.engineer, 'ip', 'addr', 'add', '172.20.99.99/32', 'dev', 'engineer0')
        spoof = 'import socket; s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM); s.bind(("172.20.99.99",0)); s.sendto(b"spoof",("172.20.0.1",18081))'
        self.client(self.engineer, 'python3', '-c', spoof)
        try:
            receiver.recvfrom(32)
            raise AssertionError('Spoofed stand source reached a local service.')
        except socket.timeout:
            pass
        receiver.close()
        print('Source guard preserves ordinary access and rejects spoofed stand identity: OK', flush=True)

        # Teardown of our profile must preserve unrelated sharing and foreign firewall state.
        second = self.peer('engineer1', 'ap1', '192.168.241.20/24')
        self.nm('device', 'set', 'ap1', 'managed', 'yes')
        self.nm('connection', 'add', 'type', 'ethernet', 'ifname', 'ap1', 'con-name', 'other-sharing',
                'ipv4.method', 'shared', 'ipv4.addresses', '192.168.241.1/24', 'ipv6.method', 'disabled', 'connection.autoconnect', 'no')
        self.nm('-w', '12', 'connection', 'up', 'id', 'other-sharing')
        self.client(second, 'ip', 'route', 'add', 'default', 'via', '192.168.241.1')
        self.run('nft', '-f', '-', input='add table inet keep_fixture\n')
        self.backend.stop()
        self.client(second, 'ping', '-c', '1', '-W', '2', sensor_ip)
        tables = self.run('nft', 'list', 'tables').stdout
        assert 'keep_fixture' in tables and 'nm-shared-ap1' in tables, tables
        assert 'cmprovision_rescue' not in tables and 'nm-shared-ap0' not in tables, tables
        print('Teardown preserves another shared connection and foreign firewall: OK', flush=True)
        print('NM_SHARED_ACCEPTANCE_OK', flush=True)

    def close(self):
        for process in reversed(self.processes):
            process.terminate()
        for process in reversed(self.processes):
            try: process.wait(timeout=2)
            except subprocess.TimeoutExpired: process.kill(); process.wait()
        for log in self.logs: log.close()
        self.tmp.cleanup()


fixture = Fixture()
try:
    fixture.setup()
    fixture.verify()
except Exception as error:
    if isinstance(error, subprocess.CalledProcessError):
        print('Command stdout: ' + (error.stdout or '') + '\nCommand stderr: ' + (error.stderr or ''), file=sys.stderr)
    if Path('/run/NetworkManager/test-dhcp.log').exists():
        print('AP DHCP log:\n' + Path('/run/NetworkManager/test-dhcp.log').read_text()[-3000:], file=sys.stderr)
    print('Namespace sockets:\n' + fixture.run('ss', '-lunp', check=False).stdout, file=sys.stderr)
    for path in Path('/proc').glob('[0-9]*'):
        try:
            if (path/'comm').read_text().strip() == 'dnsmasq' and (path/'ns/net').stat().st_ino == os.stat('/proc/self/ns/net').st_ino:
                print('DHCP argv: ' + (path/'cmdline').read_bytes().replace(b'\0', b' ').decode(), file=sys.stderr)
        except (OSError, ProcessLookupError): pass
    if (fixture.root/'bus').exists():
        print('NM devices:\n' + fixture.nm('device', 'status', check=False).stdout, file=sys.stderr)
        print('AP device:\n' + fixture.nm('-f', 'GENERAL', 'device', 'show', 'ap0', check=False).stdout, file=sys.stderr)
    for file in fixture.root.glob('*.log'):
        print(file.name + ':\n' + file.read_text()[-3500:], file=sys.stderr)
    raise
finally:
    fixture.close()
