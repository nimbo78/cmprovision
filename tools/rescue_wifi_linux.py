"""Bounded Linux effects for the owned NetworkManager shared-mode AP."""
import ipaddress
import json
import os
from pathlib import Path
import re
import subprocess

from rescue_wifi import RescueError
from rescue_wifi_policy import choose_subnet, validate_config, MAC, has_management

PROFILE_UUID = 'f5e6b717-a82b-4b52-9a8e-b60937c5d725'
PROFILE_ID = 'cmprovision-rescue'
IFNAME = re.compile(r'^[A-Za-z0-9_.-]{1,15}$')
GUARD = 'cmprovision rescue source validation'


def split_nm(line):
    """nmcli terse lists escape backslashes and colons, not arbitrary shell quoting."""
    return re.split(r'(?<!\\):', line)


class Linux:
    def __init__(self, store, runner=subprocess.run):
        self.store, self.runner = store, runner
        self.keyfile = store.root/'etc/NetworkManager/system-connections/cmprovision-rescue.nmconnection'

    def run(self, *argv, check=True, input=None, timeout=8):
        try:
            result = self.runner(list(argv), input=input, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                 timeout=timeout, env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL':'C.UTF-8'})
        except (OSError, subprocess.SubprocessError) as error:
            raise RescueError('A Wi-Fi system command is unavailable or timed out.') from error
        if check and result.returncode:
            raise RescueError('A Wi-Fi system operation failed. Check the rescue Wi-Fi service journal.')
        if len(result.stdout) > 1048576:
            raise RescueError('A Wi-Fi system response is too large.')
        return result

    def nm(self, *argv, **options):
        return self.run('/usr/bin/nmcli', '--escape', 'no', *argv, **options)

    def binding(self):
        text = self.store.read_text(self.store.root/'etc/default/cmprovision-stand-interface', public=True)
        if not text or not IFNAME.fullmatch(text.strip()):
            raise RescueError('Bind provisioning DHCP to its stand interface in Network settings before enabling rescue Wi-Fi.')
        return text.strip()

    def networks(self, inactive=True, exclude_device=None):
        values = []
        for row in json.loads(self.run('/usr/sbin/ip', '-j', '-4', 'route', 'show', 'table', 'all').stdout):
            if (exclude_device is None or row.get('dev') != exclude_device) and row.get('dst') not in (None, 'default'):
                values.append(row['dst'])
        for row in json.loads(self.run('/usr/sbin/ip', '-j', 'address', 'show').stdout):
            if exclude_device is None or row.get('ifname') != exclude_device:
                for address in row.get('addr_info', []):
                    if address.get('family') == 'inet':
                        values.append(f"{address['local']}/{address['prefixlen']}")
        if inactive:
            uuids = self.nm('-g', 'UUID', 'connection', 'show').stdout.splitlines()
            if len(uuids) > 128:
                raise RescueError('Too many network profiles to check access-point subnet conflicts safely.')
            for identity in uuids:
                if identity == PROFILE_UUID or not re.fullmatch(r'[0-9a-f-]{36}', identity):
                    continue
                properties = self.nm('-g', 'ipv4.addresses,ipv4.routes', 'connection', 'show', 'uuid', identity).stdout
                values.extend(re.findall(r'\b(?:\d{1,3}\.){3}\d{1,3}/\d{1,2}\b', properties))
        return values

    def _guard_owned(self):
        tables = json.loads(self.run('/usr/sbin/nft', '-j', 'list', 'tables').stdout).get('nftables', [])
        found = any(row.get('table', {}).get('name') == 'cmprovision_rescue' and
                    row['table'].get('family') == 'inet' for row in tables)
        if not found:
            return False
        rows = json.loads(self.run('/usr/sbin/nft', '-j', 'list', 'table', 'inet', 'cmprovision_rescue').stdout).get('nftables', [])
        if not any(row.get('table', {}).get('comment') == GUARD for row in rows):
            raise RescueError('A firewall table with the rescue name belongs to another configuration.')
        return True

    def guard(self, config, device):
        if not IFNAME.fullmatch(device):
            raise RescueError('Invalid Wi-Fi interface name.')
        prefix = 'delete table inet cmprovision_rescue\n' if self._guard_owned() else ''
        script = prefix + f'''add table inet cmprovision_rescue {{ comment "{GUARD}"; }}
add chain inet cmprovision_rescue input {{ type filter hook input priority -10; policy accept; }}
add rule inet cmprovision_rescue input iifname "{device}" ip saddr 0.0.0.0 udp sport 68 udp dport 67 accept
add rule inet cmprovision_rescue input iifname "{device}" ip saddr != {config['subnet']} drop
'''
        self.run('/usr/sbin/nft', '-f', '-', input=script)

    def remove_guard(self):
        if self._guard_owned():
            self.run('/usr/sbin/nft', '-f', '-', input='delete table inet cmprovision_rescue\n')

    def check_backend(self):
        text = self.run('/usr/sbin/NetworkManager', '--print-config').stdout
        if re.search(r'^firewall-backend\s*=\s*none\s*$', text, re.M):
            raise RescueError('NetworkManager sharing firewall is disabled. Enable a supported NAT backend before rescue Wi-Fi.')
        self._guard_owned()  # also proves nft is available and detects a foreign owner before profile writes
        return 'iptables' if re.search(r'^firewall-backend\s*=\s*iptables\s*$', text, re.M) else 'nftables'

    def verify_sharing(self, backend, device, subnet):
        if backend == 'iptables':
            text = self.run('/usr/sbin/iptables', '-t', 'nat', '-S', 'POSTROUTING').stdout
            if any(subnet in line and 'MASQUERADE' in line and 'nm-shared' in line for line in text.splitlines()):
                return
        else:
            response = self.run('/usr/sbin/nft', '-j', 'list', 'table', 'ip', 'nm-shared-' + device, check=False)
            if response.returncode == 0:
                rows = json.loads(response.stdout).get('nftables', [])
                if any('masquerade' in expression for row in rows for expression in row.get('rule', {}).get('expr', [])):
                    return
        raise RescueError('The access point activated but its sharing/NAT rules are missing.')

    def check_owned_profile(self):
        response = self.nm('-g', 'connection.id', 'connection', 'show', 'uuid', PROFILE_UUID, check=False)
        if response.returncode == 10 or not response.stdout.strip() and response.returncode == 0:
            return
        if response.returncode or response.stdout.strip() != PROFILE_ID:
            raise RescueError('The rescue connection identifier is already in use.')
        listing = self.nm('-t', '-f', 'UUID,FILENAME', 'connection', 'show').stdout
        expected = str(self.keyfile)
        if not any(line == PROFILE_UUID + ':' + expected for line in listing.splitlines()):
            raise RescueError('The rescue connection is not stored in its owned keyfile.')
        self.store._check(self.keyfile.lstat())

    @staticmethod
    def escaped(value):
        left, right = len(value) - len(value.lstrip(' ')), len(value.rstrip(' '))
        return ''.join('\\\\' if c == '\\' else '\\s' if c == ' ' and (i < left or i >= right) else c
                       for i, c in enumerate(value))

    def start(self, config, radio):
        config = validate_config(config)
        self.binding()
        choose_subnet(self.networks(), config['subnet'])
        backend = self.check_backend()
        self.check_owned_profile()
        device = radio['device']
        if not IFNAME.fullmatch(device) or radio['mac'] != config['ap_mac']:
            raise RescueError('The selected Wi-Fi radio changed.')
        if has_management([p for p in self.profiles() if p['device'] == device], '', config['management_profiles']):
            raise RescueError('The selected radio recovered a client connection; keep it connected.')
        address = str(ipaddress.ip_network(config['subnet']).network_address + 1)
        # Decimal bytes keep SSID spaces, semicolons and UTF-8 unambiguous in NM 1.52 keyfiles.
        ssid = ';'.join(str(b) for b in config['ssid'].encode('utf-8')) + ';'
        text = f'''[connection]
id={PROFILE_ID}
uuid={PROFILE_UUID}
type=wifi
autoconnect=false

[wifi]
mode=ap
band=bg
channel={config['channel']}
mac-address={config['ap_mac']}
cloned-mac-address=permanent
ssid={ssid}
powersave=2

[wifi-security]
key-mgmt=wpa-psk
proto=rsn;
pairwise=ccmp;
group=ccmp;
psk={self.escaped(config['password'])}

[ipv4]
method=shared
address1={address}/24
never-default=true

[ipv6]
method=disabled
'''
        self.store.write_text(self.keyfile, text)
        self.guard(config, device)
        try:
            self.nm('connection', 'load', str(self.keyfile))
            self.nm('-w', '15', 'connection', 'up', 'uuid', PROFILE_UUID, timeout=20)
            self.verify_sharing(backend, device, config['subnet'])
        except RescueError:
            # Keep the input guard if deactivation cannot be confirmed.
            response = self.nm('-w', '5', 'connection', 'down', 'uuid', PROFILE_UUID, check=False)
            if response.returncode in (0, 10):
                self.remove_guard()
            raise

    def stop(self):
        self.check_owned_profile()
        active = self.nm('-g', 'UUID', 'connection', 'show', '--active').stdout.splitlines()
        if PROFILE_UUID in active:
            self.nm('-w', '10', 'connection', 'down', 'uuid', PROFILE_UUID, timeout=15)
        self.remove_guard()

    def retire(self):
        self.stop()
        response = self.nm('connection', 'delete', 'uuid', PROFILE_UUID, check=False)
        if response.returncode not in (0, 10):
            raise RescueError('The owned rescue connection could not be removed.')
        if os.path.lexists(self.keyfile):
            self.store._check(self.keyfile.lstat())
            self.keyfile.unlink()

    def profiles(self):
        """Only active eligible addressing, plus UUID choices for explicit wired management."""
        address_rows = json.loads(self.run('/usr/sbin/ip', '-j', 'address', 'show').stdout)
        addresses = {row['ifname']: [f"{a['local']}/{a['prefixlen']}" for a in row.get('addr_info', [])
                     if a.get('family') in ('inet', 'inet6') and not any(
                         flag in a.get('flags', []) for flag in ('tentative', 'dadfailed'))] for row in address_rows}
        listing = self.nm('-t', '-f', 'UUID,TYPE,DEVICE', 'connection', 'show').stdout.splitlines()
        if len(listing) > 128:
            raise RescueError('Too many network profiles to inspect safely.')
        result = []
        for line in listing:
            fields = line.split(':')
            if len(fields) != 3 or not re.fullmatch(r'[0-9a-f-]{36}', fields[0]):
                continue
            identity, kind, device = fields
            kind = {'802-11-wireless':'wifi', '802-3-ethernet':'ethernet'}.get(kind, kind)
            mode = ''
            if kind == 'wifi' and device:
                mode = self.nm('-g', '802-11-wireless.mode', 'connection', 'show', 'uuid', identity).stdout.strip()
            result.append({'uuid':identity, 'type':kind, 'device':device, 'mode':mode,
                           'active': bool(device and device != '--'), 'addresses':addresses.get(device, [])})
        return result

    def radios(self):
        rows = json.loads(self.run('/usr/sbin/ip', '-j', 'link', 'show').stdout)
        links = {row['ifname']: row for row in rows}
        result = []
        directory = self.store.root/'sys/class/net'
        for path in sorted(directory.iterdir()):
            if not (path/'phy80211').exists() or not IFNAME.fullmatch(path.name):
                continue
            try:
                mac = (path/'phy80211/macaddress').read_text().strip().lower()
            except OSError:
                mac = links.get(path.name, {}).get('permaddr', '').lower()
            if not MAC.fullmatch(mac):
                continue
            capability = self.nm('-g', 'WIFI-PROPERTIES.AP', 'device', 'show', path.name).stdout.strip()
            state = self.nm('-g', 'GENERAL.STATE', 'device', 'show', path.name).stdout.strip()
            result.append({'mac':mac, 'device':path.name, 'phy':(path/'phy80211').resolve().name,
                           'bus':(path/'device/subsystem').resolve().name, 'ap':capability == 'yes', 'nm_state':state})
        return result

    def clients(self, device):
        response = self.run('/usr/sbin/iw', 'dev', device, 'station', 'dump', check=False)
        return sum(line.startswith('Station ') for line in response.stdout.splitlines()) if response.returncode == 0 else None

    def wifi_enabled(self):
        return self.nm('-g', 'WIFI', 'general').stdout.strip() == 'enabled'

    def spare(self, device, connect):
        if not IFNAME.fullmatch(device):
            raise RescueError('Invalid Wi-Fi interface name.')
        if connect:
            self.nm('device', 'set', device, 'autoconnect', 'yes')
        self.nm('-w', '10', 'device', 'connect' if connect else 'disconnect', device, timeout=15)
