"""Managed chrony configuration and DHCP option 42 for the local provisioning link."""
import ipaddress
import json
import math
import os
from pathlib import Path
import re
import stat
import tempfile
import time

NETWORK = ipaddress.ip_network('172.20.0.0/16')
INTENT = 'etc/cmprovision/stand-ntp.json'
LISTENER = 'run/chrony-dhcp/cmprovision-stand.conf'
OPTIONS = 'run/cmprovision-time/dhcp-options'
CLOSING = 'run/cmprovision-time/listener-closing'
CONF = 'etc/chrony/chrony.conf'
DHCP = 'cmprovision-dnsmasq.service'
CHRONY = 'chrony.service'
INTERFACE = re.compile(r'[A-Za-z0-9_.-]{1,15}')
HEADER = '# Managed by cmprovision: station time v1.\n'


class TimeError(RuntimeError):
    pass


def valid_servers(values):
    if not isinstance(values, list) or len(values) > 8:
        raise TimeError('Use at most eight NTP servers.')
    servers = []
    for value in values:
        if not isinstance(value, str) or not 1 <= len(value) <= 253:
            raise TimeError('Each NTP server must be a hostname or an IP address.')
        try:
            ipaddress.ip_address(value)
        except ValueError:
            if re.fullmatch(r'[0-9.]+', value) or not re.fullmatch(
                    r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?', value):
                raise TimeError('Each NTP server must be a hostname or an IP address.')
        if '%' in value:
            raise TimeError('Scoped IPv6 addresses are not supported.')
        if value.lower() not in servers:
            servers.append(value.lower())
    return servers


def chrony_config(servers):
    sources = (''.join('server ' + name + ' iburst\n' for name in servers) if servers else
               'pool 2.debian.pool.ntp.org iburst\nsourcedir /run/chrony-dhcp\n')
    return (HEADER + sources + 'driftfile /var/lib/chrony/chrony.drift\n'
            'makestep 1 3\nrtcsync\nleapseclist /usr/share/zoneinfo/leap-seconds.list\n'
            'cmdport 0\ninclude /' + LISTENER + '\n').encode()


class Files:
    """Only fixed paths under root. Every existing parent and file must be root-owned."""
    def __init__(self, root=Path('/'), uid=0):
        self.root, self.uid = Path(root), uid

    def directory(self, relative, create=False):
        parent = self.root
        for part in Path(relative).parts:
            parent /= part
            if create:
                parent.mkdir(mode=0o755, exist_ok=True)
            info = parent.lstat()
            if not stat.S_ISDIR(info.st_mode) or info.st_uid != self.uid or info.st_mode & 0o022:
                raise TimeError('The station time directory is not secure: ' + str(parent))
        return parent

    def read(self, name):
        try:
            path = self.directory(Path(name).parent) / Path(name).name
            fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        except FileNotFoundError:
            return None
        with os.fdopen(fd, 'rb') as stream:
            info = os.fstat(stream.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_uid != self.uid or info.st_mode & 0o022 or info.st_size > 65536:
                raise TimeError('The station time file is not a safe regular file: ' + name)
            return stream.read(65537)

    def write(self, name, value):
        old = self.read(name)
        if old == value:
            return False
        parent = self.directory(Path(name).parent, create=True)
        target = parent / Path(name).name
        if value is None:
            target.unlink()
        else:
            fd, tmp = tempfile.mkstemp(dir=parent)
            try:
                with os.fdopen(fd, 'wb') as stream:
                    stream.write(value)
                    stream.flush()
                    os.fchmod(fd, 0o644)
                    os.fsync(fd)
                os.replace(tmp, target)
            finally:
                if os.path.lexists(tmp):
                    os.unlink(tmp)
        fd = os.open(parent, os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
        return True


def chrony_servers(files, validate=valid_servers):
    data = files.read(CONF)
    if not data or not data.startswith(HEADER.encode()):
        return None
    servers = validate([line.split()[1] for line in data.decode('ascii').splitlines() if line.startswith('server ') and len(line.split()) >= 2])
    return servers if data == chrony_config(servers) else None


def managed_chrony(root=Path('/')):
    return chrony_servers(Files(root)) is not None


def timesync_sources(config):
    servers = []
    for line in (config or b'').decode('utf-8').splitlines():
        if line.startswith('NTP='):
            servers = valid_servers(line[4:].split())
    return servers


def timesync_config(servers):
    if not servers:
        return None
    return ('# Managed by cmprovision. Empty server list restores system defaults.\n[Time]\nNTP=\nNTP='
            + ' '.join(servers) + '\nFallbackNTP=\n').encode()


def saved_intent(value):
    if (type(value) is not dict or set(value) != {'version', 'station', 'role'} or type(value['version']) is not int
            or value['version'] != 1 or value['role'] not in ('primary', 'secondary')
            or not isinstance(value['station'], str) or not re.fullmatch(r'[a-f0-9]{32}', value['station'])):
        raise TimeError('The saved provisioning time setting is invalid.')
    return value


def sample(tracking, sources):
    selected = next((row[2] for line in sources.splitlines() if len(row := line.split(',')) >= 3 and row[1] == '*'), None)
    fields = tracking.strip().split(',')
    try:
        reference = float(fields[3])
        age = max(0, int(time.time() - reference)) if math.isfinite(reference) and reference > 0 else None
        sync = 0 < int(fields[2]) < 16 and fields[-1] in ('Normal', 'Insert second', 'Delete second')
    except (ValueError, IndexError, OverflowError):
        sync, age = False, None
    return selected, sync, age


def option_conflict(content):
    for line in content.splitlines():
        key, _, value = line.partition('#')[0].partition('=')
        key = key.strip().lower()
        if key in ('dhcp-optsfile', 'dhcp-optsdir', 'conf-dir', 'conf-file', 'conf-script'):
            return True
        if key in ('dhcp-option', 'dhcp-option-force'):
            fields = [part.strip().lower() for part in value.split(',')]
            option = next((x for x in fields if not x.startswith(('tag:', 'net:', 'vendor:', 'encap:', 'vi-encap:'))), '')
            if option in ('42', 'option:ntp-server'):
                return True
    return False


class StandTime:
    def __init__(self, files, command):
        self.files, self.command = files, command

    def role(self):
        data = self.files.read('etc/default/cmprovision-stand-interface')
        name = data.decode('ascii').strip() if data else ''
        if not INTERFACE.fullmatch(name) or name in ('.', '..'):
            raise TimeError('Confirm the provisioning interface in Settings → Network.')
        rows = json.loads(self.command('ip', '-j', '-4', 'address', 'show'))
        candidates = [(row['ifname'], addr['local'], addr['prefixlen']) for row in rows for addr in row.get('addr_info', [])
                      if addr.get('family') == 'inet' and ipaddress.ip_address(addr['local']) in NETWORK]
        if len(candidates) != 1 or candidates[0][0] != name or candidates[0][2] != NETWORK.prefixlen:
            raise TimeError('The confirmed interface must own exactly one provisioning address.')
        address = candidates[0][1]
        profile = self.command('nmcli', '-g', 'GENERAL.CON-UUID', 'device', 'show', name).strip()
        kind = self.command('nmcli', '-g', 'GENERAL.TYPE', 'device', 'show', name).strip()
        if kind not in ('ethernet', 'vlan') or not re.fullmatch(r'[0-9a-fA-F-]{36}', profile):
            raise TimeError('The provisioning link needs an active Ethernet or VLAN profile.')
        configured = re.split(r'[,\s]+', self.command('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'uuid', profile).strip())
        if address + '/16' not in configured:
            raise TimeError('The provisioning address differs from the saved network profile.')
        return name, address, 'primary' if address == '172.20.0.1' else 'secondary'

    def intent(self):
        data = self.files.read(INTENT)
        if data is None:
            return None
        return saved_intent(json.loads(data))

    def station(self):
        value = (self.files.read('etc/machine-id') or b'').decode('ascii').strip()
        if not re.fullmatch(r'[a-f0-9]{32}', value):
            raise TimeError('The local station identity is unavailable.')
        return value

    def transitioning(self):
        return any(os.path.lexists(self.files.root / path) for path in (
            'run/cmprovision-network/pending', 'var/lib/cmprovision-network/transactions/pending.json',
            'run/cmprovision-backups/pending.json', 'run/cmprovision-updates/pending.json'))

    def sockets(self):
        return self.command('ss', '-H', '-lunp', 'sport = :123').splitlines()

    @staticmethod
    def listening(rows, interface, address):
        endpoint = address + '%' + interface + ':123'
        return bool(rows) and all(len(row.split()) >= 5 and row.split()[3] == endpoint and '"chronyd"' in row for row in rows)

    def dhcp_process(self):
        pid = self.command('systemctl', 'show', DHCP, '-p', 'MainPID', '--value').strip()
        if pid == '0':
            return []
        if not re.fullmatch('[1-9][0-9]{0,8}', pid):
            raise TimeError('Cannot verify the running DHCP service.')
        try:
            return (self.files.root / 'proc' / pid / 'cmdline').read_bytes().decode().rstrip('\0').split('\0')
        except FileNotFoundError:
            return []

    def announce(self, address):
        value = ('option:ntp-server,' + address + '\n').encode() if address else b''
        dirty = OPTIONS + '.reload'
        if self.files.read(OPTIONS) == value and self.files.read(dirty) is None:
            return
        self.files.write(dirty, b'pending\n')
        self.files.write(OPTIONS, value)
        argv = self.dhcp_process()
        if '--dhcp-optsfile=/' + OPTIONS in argv:
            self.command('systemctl', 'reload', DHCP)
        self.files.write(dirty, None)

    def close(self):
        # Withdraw the lease option first. Never leave the listener open on a failed withdrawal.
        try:
            self.announce(None)
        finally:
            if self.files.read(LISTENER) or self.files.read(CLOSING):
                self.files.write(CLOSING, b'pending\n')
                self.files.write(LISTENER, b'')
                self.command('systemctl', 'try-restart', CHRONY)
                if any('"chronyd"' in row for row in self.sockets()):
                    raise TimeError('The previous provisioning NTP listener did not close.')
                self.files.write(CLOSING, None)

    def status(self, provider, enabled):
        state = dict(available=provider == 'chrony', requested=False, listening=False, advertising=False,
                     address=None, interface=None, reason=None)
        try:
            intent = self.intent()
            state['requested'] = bool(intent and intent['station'] == self.station())
            if provider != 'chrony':
                raise TimeError('Prepare the station with managed chrony to serve provisioning clients.')
            name, address, role = self.role()
            state.update(address=address, interface=name)
            if self.transitioning():
                raise TimeError('A station operation is pending.')
            if intent and (not state['requested'] or intent['role'] != role):
                state['requested'] = False
                raise TimeError('Enable time service for the current station role.')
            state['listening'] = self.listening(self.sockets(), name, address)
            if role == 'primary' and state['listening']:
                state['advertising'] = (self.files.read(OPTIONS) == ('option:ntp-server,' + address + '\n').encode()
                    and self.files.read(OPTIONS + '.reload') is None
                    and '--dhcp-optsfile=/' + OPTIONS in self.dhcp_process())
            if state['requested'] and not enabled:
                raise TimeError('Station time synchronization is disabled.')
            if state['requested'] and not state['listening']:
                raise TimeError('The provisioning time listener is waiting for the network or service.')
        except (TimeError, OSError, ValueError) as error:
            state['reason'] = str(error)
        return state

    def reconcile(self, provider, enabled, request=None):
        if request is False:
            self.files.write(INTENT, None)
            self.close()
            return
        intent = self.intent()
        if request is None and intent is None:
            if any(self.files.read(path) for path in (LISTENER, OPTIONS, CLOSING, OPTIONS + '.reload')):
                self.close()
            return
        try:
            if self.transitioning() or not enabled or provider != 'chrony':
                self.close()
                if request:
                    raise TimeError('Enable managed chrony on a confirmed, idle station first.')
                return
            name, address, role = self.role()
            local = dict(version=1, station=self.station(), role=role)
            if request:
                self.files.write(INTENT, (json.dumps(local) + '\n').encode())
            elif intent != local:
                self.close()
                self.files.write(INTENT, None)
                return
            rows = self.sockets()
            desired = f'binddevice {name}\nbindaddress {address}\nallow {NETWORK}\n'.encode()
            if role == 'primary':
                config = self.command('runuser', '-u', 'www-data', '--', '/usr/bin/head', '-c', '1048577',
                                      '/var/lib/cmprovision/etc/dnsmasq.conf')
                if len(config.encode()) > 1048576 or option_conflict(config):
                    raise TimeError('DHCP already configures NTP or external options. Review its configuration.')
                argv = self.dhcp_process()
                if '--interface=' + name not in argv or '--dhcp-optsfile=/' + OPTIONS not in argv:
                    raise TimeError('Restart the bound DHCP service during maintenance to load managed options.')
            elif self.dhcp_process():
                raise TimeError('A secondary station cannot advertise DHCP options.')
            if self.files.read(LISTENER) != desired or not self.listening(rows, name, address):
                self.close()
                if self.sockets():
                    raise TimeError('UDP port 123 is already in use.')
                self.files.write(LISTENER, desired)
                self.command('chronyd', '-p', '-f', '/' + CONF)
                self.command('systemctl', 'restart', CHRONY)
                if not self.listening(self.sockets(), name, address):
                    raise TimeError('chrony did not bind UDP 123 to the provisioning interface.')
            self.announce(address if role == 'primary' else None)
        except (TimeError, ValueError, OSError):
            self.close()
            raise

