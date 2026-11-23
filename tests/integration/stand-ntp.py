#!/usr/bin/env python3
"""Wire test with real chronyd, DHCP ACKs and a VLAN. Run in an isolated network/mount namespace."""
import os
from pathlib import Path
import signal
import socket
import struct
import subprocess
import sys
import tempfile
import time

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools'))
from stand_time import CONF, LISTENER, OPTIONS, Files, StandTime, chrony_config, process_bound_socket, sample

SELF = Path(__file__).resolve()
UUID = '7e73aa77-ab0a-4d88-a458-8b7c03047ea1'
NTPD = os.environ.get('CHRONYD', '/usr/sbin/chronyd')
NTPC = os.environ.get('CHRONYC', '/usr/bin/chronyc')


def run(*args):
    return subprocess.check_output(args, text=True, stderr=subprocess.STDOUT, timeout=10).strip()


def dhcp(interface, want42):
    # RFC 2131 selecting state: request option 42 explicitly and inspect the ACK, not just the offer.
    mac = bytes.fromhex(Path('/sys/class/net', interface, 'address').read_text().strip().replace(':', ''))
    xid = os.urandom(4)
    header = b'\1\1\6\0' + xid + b'\0\0\x80\0' + bytes(16) + mac + bytes(202) + b'\x63\x82\x53\x63'
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_BINDTODEVICE, interface.encode()+b'\0')
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        sock.settimeout(3)
        sock.bind(('', 68))
        def exchange(kind, extra):
            prl = b'\1\3\6' + (b'\x2a' if want42 else b'')
            sock.sendto(header + bytes((53, 1, kind, 55, len(prl))) + prl + extra + b'\xff', ('255.255.255.255', 67))
            while True:
                data = sock.recv(1536)
                if data[0] != 2 or data[4:8] != xid:
                    continue
                options, i = {}, 240
                while i < len(data) and data[i] != 255:
                    key = data[i]
                    i += 1
                    if key:
                        length = data[i]
                        options[key] = data[i+1:i+1+length]
                        i += length + 1
                return data[16:20], options
        ip, offer = exchange(1, b'')
        assert offer[53] == b'\2'
        assigned, ack = exchange(3, bytes((50, 4)) + ip + bytes((54, 4)) + offer[54])
        assert ip == assigned and ack[53] == b'\5' and 51 in ack
        return ack.get(42, b'').hex() or 'none'


def sntp(address):
    family = socket.AF_INET6 if ':' in address else socket.AF_INET
    with socket.socket(family, socket.SOCK_DGRAM) as sock:
        sock.settimeout(1)
        stamp = struct.pack('!II', int(time.time()) + 2208988800, 4242)
        sock.sendto(b'\x23' + bytes(39) + stamp, (address, 123))
        try:
            answer, _ = sock.recvfrom(512)
        except socket.timeout:
            return 'silent'
        assert answer[0] & 7 == 4 and answer[24:32] == stamp
        return 'synchronized' if answer[0] >> 6 != 3 and 0 < answer[1] < 16 else 'unsynchronized'


def lab(root):
    if os.stat('/proc/self/ns/net').st_ino == os.stat('/proc/1/ns/net').st_ino:
        raise RuntimeError('Use unshare --net --mount --propagation private before starting the test.')
    os.chmod(root, 0o755)
    # Both ip-netns and chronyc need writable runtime paths. Never use the host's sockets.
    run('mount', '-t', 'tmpfs', 'tmpfs', '/run')
    netns = Path('/run/netns')
    netns.mkdir()
    Path('/run/chrony').mkdir(mode=0o750)
    files = Files(root)
    files.write(LISTENER, b'')
    files.write(OPTIONS, b'')
    files.write('etc/default/cmprovision-stand-interface', b'pv15\n')
    files.write('etc/machine-id', b'a'*32)
    files.write(CONF, chrony_config(['192.0.2.2']))
    processes = {}
    def start(key, argv):
        processes[key] = subprocess.Popen(argv, stdout=subprocess.DEVNULL, stderr=log)
        time.sleep(.2)
        if processes[key].poll() is not None:
            raise RuntimeError(key+' failed: '+log_path.read_text()[-1600:])
    def stop(key):
        p = processes.pop(key, None)
        if p and p.poll() is None:
            p.terminate()
            p.wait(5)
    def peer(ns, *args):
        return run('ip', 'netns', 'exec', ns, *args)
    def probe(ns, op, *args):
        return peer(ns, sys.executable, str(SELF), op, *args)
    def restart():
        stop('server')
        config = root/'server.conf'
        # In a private namespace clocks still share the host kernel. -x prevents any host clock step.
        config.write_text('server 192.0.2.2 iburst minpoll 0 maxpoll 0\n'
                          'pidfile '+str(root/'server.pid')+'\ncmdport 0\nbindcmdaddress /run/chrony/server.sock'+
                          '\ninclude '+str(root/LISTENER)+'\n')
        start('server', [NTPD, '-n', '-x', '-u', 'root', '-f', str(config)])
    def command(program, *args, **kwargs):
        if program == 'nmcli':
            return {'GENERAL.CON-UUID': UUID, 'GENERAL.TYPE': 'vlan', 'ipv4.addresses': '172.20.0.1/16'}[args[1]]
        if program == 'systemctl':
            if args[0] in ('restart', 'try-restart'):
                restart()
            elif args[0] == 'reload':
                processes['dhcp'].send_signal(signal.SIGHUP)
            else:
                raise AssertionError(args)
            return ''
        if program == 'runuser':
            return 'port=0'
        if program == 'chronyd':
            return run(NTPD, '-p', 'include '+str(root/LISTENER))
        return run(program, *args)
    service = StandTime(files, command)
    argv = ['--dhcp-optsfile=/' + OPTIONS, '--interface=pv15']
    service.dhcp_process = lambda: argv if 'dhcp' in processes else []
    log_path = root/'daemons.log'
    log = log_path.open('w')
    try:
        run('ip', 'link', 'set', 'lo', 'up')
        for ns, port, server, client in [('device', 'stand', None, '172.20.0.55/16'),
                                         ('management', 'mgmt', '192.0.2.1/24', '192.0.2.2/24'),
                                         ('rescue', 'ap', '198.18.0.1/24', '198.18.0.2/24')]:
            run('ip', 'netns', 'add', ns)
            run('ip', 'link', 'add', port, 'type', 'veth', 'peer', 'name', 'client')
            run('ip', 'link', 'set', 'client', 'netns', ns)
            run('ip', 'link', 'set', port, 'up')
            peer(ns, 'ip', 'link', 'set', 'lo', 'up')
            peer(ns, 'ip', 'link', 'set', 'client', 'up')
            if server:
                run('ip', 'addr', 'add', server, 'dev', port)
                peer(ns, 'ip', 'addr', 'add', client, 'dev', 'client')
                peer(ns, 'ip', 'route', 'add', '172.20.0.0/16', 'via', server.split('/')[0])
            else:
                run('ip', 'link', 'add', 'link', port, 'name', 'pv15', 'type', 'vlan', 'id', '15')
                run('ip', 'addr', 'add', '172.20.0.1/16', 'dev', 'pv15')
                run('ip', 'link', 'set', 'pv15', 'up')
                peer(ns, 'ip', 'link', 'add', 'link', 'client', 'name', 'v15', 'type', 'vlan', 'id', '15')
                peer(ns, 'ip', 'addr', 'add', client, 'dev', 'v15')
                peer(ns, 'ip', 'link', 'set', 'v15', 'up')
        run('ip', '-6', 'addr', 'add', '2001:db8:42::1/64', 'dev', 'pv15', 'nodad')
        peer('device', 'ip', '-6', 'addr', 'add', '2001:db8:42::2/64', 'dev', 'v15', 'nodad')
        tftp = root/'tftp'
        tftp.mkdir(mode=0o755)
        (tftp/'probe.txt').write_bytes(b'cmprovision-vlan-probe\n')
        start('dhcp', ['dnsmasq', '-k', '-C', '/dev/null', '--port=0', '--no-ping', '--interface=pv15',
                       '--bind-dynamic', '--dhcp-range=172.20.0.60,172.20.0.80,255.255.0.0,1h',
                       '--dhcp-leasefile='+str(root/'leases'), '--dhcp-optsfile='+str(root/OPTIONS),
                       '--enable-tftp', '--tftp-root='+str(tftp), '--pid-file='+str(root/'dhcp.pid')])
        restart()
        assert probe('device', 'dhcp', 'v15', 'yes') == 'none'
        assert probe('device', 'sntp', '172.20.0.1') == 'silent'
        service.reconcile('chrony', True, True)
        assert process_bound_socket(processes['server'].pid, 'pv15', '172.20.0.1', NTPD)
        assert not process_bound_socket(processes['server'].pid, 'mgmt', '172.20.0.1', NTPD)
        assert probe('device', 'dhcp', 'v15', 'yes') == socket.inet_aton('172.20.0.1').hex()
        assert probe('device', 'dhcp', 'v15', 'no') == 'none'
        cold = probe('device', 'sntp', '172.20.0.1')
        assert cold in ('silent', 'unsynchronized'), cold
        source = root/'source.conf'
        source.write_text('local stratum 3\nallow 192.0.2.0/24\nbindaddress 192.0.2.2\ncmdport 0\n'
                          'bindcmdaddress /run/chrony/source.sock\npidfile '+str(root/'source.pid')+'\n')
        start('source', ['ip', 'netns', 'exec', 'management', NTPD, '-n', '-x', '-u', 'root', '-f', str(source)])
        for attempt in range(35):
            if probe('device', 'sntp', '172.20.0.1') == 'synchronized':
                break
            time.sleep(.5)
        else:
            raise AssertionError('No synchronized NTP response after upstream returned')
        control = (NTPC, '-n', '-c', '-h', '/run/chrony/server.sock')
        tracking = run(*control, 'tracking')
        sources = run(*control, 'sources')
        selected, synchronized, age = sample(tracking, sources)
        assert selected == '192.0.2.2' and synchronized and age is not None and age < 10, (tracking, sources)
        for ns, addr in [('management','192.0.2.1'), ('management','172.20.0.1'), ('rescue','198.18.0.1'),
                         ('rescue','172.20.0.1'), ('device','2001:db8:42::1')]:
            assert probe(ns, 'sntp', addr) == 'silent', (ns, addr)
        assert 'data' in peer('device', sys.executable, str(SELF.with_name('tftp-probe.py')), 'v15', 'data')
        service.reconcile('chrony', True, False)
        assert probe('device', 'dhcp', 'v15', 'yes') == 'none'
        assert probe('device', 'sntp', '172.20.0.1') == 'silent'
        assert 'data' in peer('device', sys.executable, str(SELF.with_name('tftp-probe.py')), 'v15', 'data')
        print('Tagged VLAN: NTP synchronized after upstream returned; DHCP ACK 42 = ac140001.')
        print('Management, rescue and IPv6 silent. Disabled: ACK has no 42, NTP silent. TFTP passed.')
    finally:
        for key in list(processes):
            stop(key)
        log.close()
        for ns in ('device','management','rescue'):
            if (netns/ns).exists():
                run('ip', 'netns', 'del', ns)


if __name__ == '__main__':
    if sys.argv[1:2] == ['dhcp']:
        print(dhcp(sys.argv[2], sys.argv[3] == 'yes'))
    elif sys.argv[1:2] == ['sntp']:
        print(sntp(sys.argv[2]))
    else:
        with tempfile.TemporaryDirectory(prefix='stand-ntp-') as directory:
            lab(Path(directory))
