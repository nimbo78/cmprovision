"""Initial provisioning network and station role, inside the durable network transaction."""
import ipaddress
import os
import socket
import struct
import time


class RoleError(RuntimeError):
    pass


def probe_address(device, address):
    """ARP duplicate-address probes on exactly the selected L2 interface, without assigning an IP."""
    with open('/sys/class/net/' + device + '/address', encoding='ascii') as source:
        mac = bytes.fromhex(source.read().strip().replace(':', ''))
    if len(mac) != 6:
        raise RoleError('The interface has no Ethernet hardware address.')
    target = socket.inet_aton(address)
    arp = struct.pack('!HHBBH', 1, 0x0800, 6, 4, 1) + mac + bytes(4) + bytes(6) + target
    with socket.socket(socket.AF_PACKET, socket.SOCK_RAW, socket.htons(0x0806)) as channel:
        channel.bind((device, 0))
        for _ in range(3):
            channel.send(bytes.fromhex('ffffffffffff') + mac + bytes.fromhex('0806') + arp)
            deadline = time.monotonic() + .5
            while time.monotonic() < deadline:
                channel.settimeout(max(.01, deadline - time.monotonic()))
                try:
                    frame = channel.recv(2048)
                except socket.timeout:
                    break
                if len(frame) < 42 or frame[12:14] != bytes.fromhex('0806'):
                    continue
                packet = frame[14:42]
                if packet[:6] != struct.pack('!HHBB', 1, 0x0800, 6, 4) or packet[8:14] == mac:
                    continue
                if packet[14:18] == target or (packet[14:18] == bytes(4) and packet[24:28] == target):
                    raise RoleError('Another device is using or claiming ' + address + ' on this network.')


class Services:
    UNITS = {'dhcp': 'cmprovision-dnsmasq.service', 'usb': 'cmprovision-rpiboot.service'}

    def __init__(self, run, defer_start=False, boot_link=None):
        self.run, self.defer_start = run, defer_start
        self.boot_link = boot_link or (lambda unit: os.path.islink('/etc/systemd/system/multi-user.target.wants/' + unit))

    def snapshot(self):
        states = {}
        for key, unit in self.UNITS.items():
            output = self.run('systemctl', 'show', unit, '--property=LoadState', '--property=ActiveState', '--property=UnitFileState')
            fields = dict(line.split('=', 1) for line in output.splitlines() if '=' in line)
            if fields.get('LoadState') not in ('loaded', 'masked') or \
                    fields.get('ActiveState') not in ('active', 'inactive', 'failed') or \
                    fields.get('UnitFileState') not in ('enabled', 'disabled', 'masked'):
                raise RoleError('The provisioning service state is unavailable or changing.')
            wanted = self.boot_link(unit)
            enabled = fields['UnitFileState']
            if enabled != 'masked' and (enabled == 'enabled') != wanted:
                raise RoleError('The provisioning service uses a custom boot target; manage it with system tools.')
            states[key] = {'active': fields['ActiveState'] == 'active', 'enabled': enabled, 'boot_enabled': wanted}
        return states

    @staticmethod
    def plan(before, role, usb):
        wanted = {'dhcp': role == 'primary', 'usb': role == 'primary' and usb}
        temporary, final = {}, {}
        for key, active in wanted.items():
            temporary[key] = {'active': active, 'enabled':
                ('enabled' if before[key]['boot_enabled'] else 'disabled') if active else 'masked',
                'boot_enabled': before[key]['boot_enabled']}
            final[key] = {'active': active, 'enabled': 'enabled' if active else 'masked', 'boot_enabled': active}
        return temporary, final

    def stop(self):
        self.run('systemctl', 'stop', *self.UNITS.values())

    def persistence(self, states):
        for key, unit in self.UNITS.items():
            state = states[key]['enabled']
            # Masked units can still have WantedBy links. Preserve those separately so
            # cancelling a partially persisted role does not leave hidden boot changes.
            self.run('systemctl', 'unmask', unit)
            self.run('systemctl', 'enable' if states[key]['boot_enabled'] else 'disable', unit)
            if state == 'masked':
                self.run('systemctl', 'mask', unit)

    def apply(self, states):
        self.persistence(states)
        for key, unit in self.UNITS.items():
            if states[key]['active']:
                options = ['--no-block'] if self.defer_start else []
                self.run('systemctl', *options, 'restart', unit)
        if not self.defer_start and self.snapshot() != states:
            raise RoleError('The provisioning services did not reach the requested state.')


class Backend:
    def __init__(self, common, stand, services, probe=probe_address):
        self.common, self.stand, self.services, self.probe = common, stand, services, probe

    def _holders(self):
        devices = self.common._json('ip', '-j', '-4', 'address', 'show')
        return [(d.get('ifname'), a['local'], a['prefixlen']) for d in devices for a in d.get('addr_info', [])
                if a.get('family') == 'inet' and ipaddress.ip_address(a['local']) in self.common.STAND]

    def _active(self, device):
        rows = self.common.run('nmcli', '--escape', 'no', '-t', '-f', 'UUID,DEVICE', 'connection', 'show', '--active')
        matches = {row.partition(':')[0] for row in rows.splitlines() if row.partition(':')[2] == device}
        if len(matches) > 1:
            raise RoleError('Multiple active profiles use the provisioning interface.')
        return next(iter(matches), None)

    def validate(self, request):
        if not isinstance(request, dict) or set(request) != {'kind', 'device', 'uuid', 'role', 'usb', 'isolated'} or \
                request['kind'] != 'stand-role' or request['role'] not in ('primary', 'secondary', 'disabled') or \
                type(request['usb']) is not bool or type(request['isolated']) is not bool:
            raise RoleError('Invalid station role request.')
        role = request['role']
        if role == 'primary' and not request['isolated']:
            raise RoleError('Confirm that this is an isolated provisioning network with no other DHCP server.')
        if role != 'primary' and request['usb']:
            raise RoleError('USB boot is available only on a primary station.')
        profile = self.stand._profile(request['device'], request['uuid'])
        if profile['method'] not in ('auto', 'manual', 'disabled'):
            raise RoleError('Choose an automatic, static or disabled IPv4 profile.')
        self.stand._root(profile['device'], self.common._json('ip', '-j', '-d', 'link', 'show'))
        read = lambda name: self.stand._read(profile['uuid'], name)
        if read('connection.type') == 'vlan' and read('connection.interface-name') != profile['device']:
            raise RoleError('Bind this VLAN profile to its interface name before assigning a station role.')
        if read('connection.master') or read('connection.secondaries'):
            raise RoleError('Port/controller and dependent VPN profiles cannot carry the stand here.')
        if read('ipv4.route-table') not in ('', '0', '254') or read('ipv4.routing-rules'):
            raise RoleError('Use the main routing table and remove IPv4 policy rules before assigning a station role.')
        if any(device != profile['device'] for device, _, _ in self._holders()):
            raise RoleError('The provisioning network is on another interface; move it first.')
        binding = self.stand.binding.read()
        if binding is not None and binding != profile['device']:
            raise RoleError('DHCP/TFTP is bound to another interface; move the provisioning network first.')
        address = {'primary': '172.20.0.1/16', 'secondary': '172.20.0.2/16', 'disabled': None}[role]
        addresses = [a for a in profile['addresses'] if ipaddress.ip_interface(a).ip not in self.common.STAND]
        if address:
            self.probe(profile['device'], str(ipaddress.ip_interface(address).ip))
            addresses.append(address)
        method = 'manual' if addresses else 'disabled'
        if not addresses and any(read(name) for name in ('ipv4.gateway', 'ipv4.dns', 'ipv4.routes', 'ipv4.dns-search')):
            raise RoleError('This profile has extra IPv4 settings. Keep a management address or clear those settings before disabling it.')
        self.stand.binding.validate(profile['device'])
        before = self.services.snapshot()
        temporary, final = self.services.plan(before, role, request['usb'])
        profile['arguments'] = ['ipv4.method', method, 'ipv4.addresses', ','.join(addresses)]
        return {'kind': 'stand-role', 'role': role, 'device': profile['device'], 'uuid': profile['uuid'],
            'profile': profile, 'address': address, 'binding_before': binding,
            'binding_after': profile['device'] if address else None, 'services_before': before,
            'services_temporary': temporary, 'services_final': final}

    def snapshot(self, change):
        return {'profile': self.common.snapshot(change['profile']), 'binding': self.stand.binding.read(),
                'services': self.services.snapshot()}

    def checkpoint(self, change):
        if self.services.snapshot() != change['services_before'] or self.stand.binding.read() != change['binding_before']:
            raise RoleError('The provisioning service configuration changed; try again.')
        return self.common.checkpoint(change['profile'])

    def stage_temporary(self, change):
        self.common.stage_temporary(change['profile'])
        state = self.common.load()
        state['partial'] = {'profile': self.common.snapshot(change['profile'])}
        self.common.store(state)

    def apply_temporary(self, change):
        if self.services.snapshot() != change['services_before'] or self._active(change['device']) != change['uuid']:
            raise RoleError('The provisioning services or connection changed before the network was applied.')
        self.services.stop()
        self.common.run('nmcli', 'device', 'reapply', change['device'])
        expected = [(change['device'], str(ipaddress.ip_interface(change['address']).ip), 16)] if change['address'] else []
        deadline = time.monotonic() + 15
        while self._holders() != expected:
            if time.monotonic() >= deadline:
                raise RoleError('The provisioning address did not reach the selected interface.')
            time.sleep(.2)
        self.common._check_stand_routes()
        self.stand.binding.write(change['binding_after'])
        self.services.apply(change['services_temporary'])

    def verify_before_confirm(self, original, change, applied):
        if applied is None or self.stand.binding.read() != change['binding_after'] or \
                self.services.snapshot() != change['services_temporary'] or self._active(change['device']) != change['uuid']:
            raise RoleError('The provisioning service state changed outside this operation.')
        self.common.verify_before_confirm(original['profile'], change['profile'], applied['profile'])

    def persist(self, change):
        state = self.common.load()
        self.verify_before_confirm(state['original'], change, state['applied'])
        self.common.persist(change['profile'])
        self.services.persistence(change['services_final'])
        if self.services.snapshot() != change['services_final']:
            raise RoleError('The station services could not be enabled for the next boot.')

    def restore_original(self, original, change, applied, persisted, checkpoint, partial=None):
        if self._active(change['device']) not in (None, change['uuid']):
            raise RoleError('Another profile was activated externally; role recovery is paused.')
        applied_profile = applied['profile'] if applied else (partial or {}).get('profile')
        self.common.check_restore(original['profile'], change['profile'],
            applied_profile, persisted['profile'] if persisted else None)
        if self.stand.binding.read() not in (original['binding'], change['binding_after']):
            raise RoleError('The DHCP/TFTP binding was changed externally; recovery is paused.')
        for key, current in self.services.snapshot().items():
            candidates = [original['services'][key], change['services_temporary'][key], change['services_final'][key]]
            allowed = {(state['enabled'], state['boot_enabled']) for state in candidates}
            # Unmasking exposes the existing WantedBy link before enable/disable runs.
            allowed |= {('enabled' if state['boot_enabled'] else 'disabled', state['boot_enabled']) for state in candidates}
            if (current['enabled'], current['boot_enabled']) not in allowed or \
                    (current['active'] and not any(state['active'] for state in candidates)):
                raise RoleError('A provisioning service was changed externally; recovery is paused.')
        self.services.stop()
        self.common.restore_original(original['profile'], change['profile'],
            applied_profile, persisted['profile'] if persisted else None, checkpoint)
        if self._active(change['device']) is None:
            self.common.activate_profile(change['uuid'], change['device'])
        self.common.wait_addresses({'device': change['device'],
            'arguments': [part for pair in original['profile']['values'] for part in pair]},
            # The original connection may have been active via IPv6 without a
            # DHCPv4 lease. Restore its settings without requiring a new lease.
            allow_stand=True, check_routes=False, wait_dhcp=False)
        self.stand.binding.write(original['binding'])
        self.services.apply(original['services'])
