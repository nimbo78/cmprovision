"""Guarded transfer of the provisioning address between active profiles on one Ethernet port."""
import ipaddress
import json
import re
import time
import uuid


class StandError(RuntimeError):
    pass


class Backend:
    ADDRESSES = {'172.20.0.1/16', '172.20.0.2/16'}
    STAND = ipaddress.ip_network('172.20.0.0/16')
    IFNAME = re.compile(r'^[A-Za-z0-9_.-]{1,15}$')
    DEVICE_PATH = re.compile(r'^/org/freedesktop/NetworkManager/Devices/\d+$')

    def __init__(self, common, binding, service):
        self.common, self.binding, self.service = common, binding, service

    def guard(self): return self.common.guard()
    def load(self): return self.common.load()
    def pending(self): return self.common.pending()
    def store(self, value): self.common.store(value)
    def set_pending(self): self.common.set_pending()
    def clear_state(self): self.common.clear_state()
    def clear_pending(self): self.common.clear_pending()
    def active_runs(self): return self.common.active_runs()
    def schedule_recovery(self, identifier): self.common.schedule_recovery(identifier)
    def cancel_recovery(self, identifier): self.common.cancel_recovery(identifier)
    def rollback_checkpoint(self, checkpoint): self.common.rollback_checkpoint(checkpoint)
    def commit_checkpoint(self, checkpoint): self.common.commit_checkpoint(checkpoint)

    def _read(self, connection, name):
        return self.common.run('nmcli', '--escape', 'no', '-g', name, 'connection', 'show', 'uuid', connection)

    def _profile(self, device, connection):
        if not isinstance(device, str) or not self.IFNAME.fullmatch(device):
            raise StandError('Invalid network interface.')
        try:
            if not isinstance(connection, str) or str(uuid.UUID(connection)) != connection:
                raise ValueError()
        except ValueError as exc:
            raise StandError('Invalid NetworkManager profile identifier.') from exc
        if self.common.run('nmcli', '-g', 'GENERAL.CON-UUID', 'device', 'show', device) != connection:
            raise StandError('A selected profile is no longer active on its interface.')
        if self.common.run('nmcli', '-g', 'GENERAL.TYPE', 'device', 'show', device) not in ('ethernet', 'vlan'):
            raise StandError('The stand network needs an Ethernet or VLAN interface.')
        active = self.common.run('nmcli', '--escape', 'no', '-t', '-f', 'UUID,DEVICE', 'connection', 'show', '--active').splitlines()
        if {line.partition(':')[2] for line in active if line.partition(':')[0] == connection} != {device}:
            raise StandError('A selected profile is active on multiple interfaces.')
        filename = self.common._keyfile(connection)
        self.common._hash(filename)
        method = self._read(connection, 'ipv4.method')
        addresses = [str(ipaddress.ip_interface(x.strip())) for x in self._read(connection, 'ipv4.addresses').split(',') if x.strip()]
        return {'uuid': connection, 'device': device, 'filename': filename, 'method': method, 'addresses': addresses}

    def _root(self, device, links):
        by_name = {link.get('ifname'): link for link in links}
        by_index = {link.get('ifindex'): link for link in links}
        current, seen = by_name.get(device), set()
        while current is not None:
            name = current.get('ifname')
            if name in seen:
                raise StandError('The Ethernet parent chain is invalid.')
            seen.add(name)
            kind = current.get('linkinfo', {}).get('info_kind')
            if kind in ('bridge', 'bond', 'team', 'vrf'):
                raise StandError('Moving a bridged or bonded stand needs system network tools.')
            if kind != 'vlan':
                return name
            current = by_name.get(current.get('link')) if current.get('link') else by_index.get(current.get('link_index'))
        raise StandError('The Ethernet parent could not be identified.')

    def validate(self, request):
        expected = {'kind', 'source_device', 'source_uuid', 'target_device', 'target_uuid'}
        if not isinstance(request, dict) or set(request) != expected or request['kind'] != 'stand':
            raise StandError('Invalid provisioning network request.')
        source = self._profile(request['source_device'], request['source_uuid'])
        target = self._profile(request['target_device'], request['target_uuid'])
        if source['device'] == target['device'] or source['uuid'] == target['uuid']:
            raise StandError('Choose another interface for the provisioning network.')
        links = self.common._json('ip', '-j', '-d', 'link', 'show')
        if self._root(source['device'], links) != self._root(target['device'], links):
            raise StandError('Both interfaces must share the same physical Ethernet port.')
        selected = self.ADDRESSES.intersection(source['addresses'])
        if source['method'] not in ('manual', 'auto') or len(selected) != 1:
            raise StandError('The current profile must explicitly contain one stand address: 172.20.0.1/16 or 172.20.0.2/16.')
        stand_address = next(iter(selected))
        stand_ip = str(ipaddress.ip_interface(stand_address).ip)
        if target['method'] not in ('disabled', 'manual', 'auto'):
            raise StandError('The target IPv4 assignment method is not supported for the stand.')
        # No other interface on this server may already carry a stand address.
        addresses = self.common._json('ip', '-j', '-4', 'address', 'show')
        holders = [(d.get('ifname'), a.get('local'), a.get('prefixlen')) for d in addresses for a in d.get('addr_info', [])
                   if a.get('family') == 'inet' and ipaddress.ip_address(a['local']) in self.STAND]
        if holders != [(source['device'], stand_ip, 16)]:
            raise StandError('The stand address is absent, duplicated or shared with another interface.')
        for profile in (source, target):
            if profile['method'] == 'disabled' or (profile is source and len(profile['addresses']) == 1 and profile['method'] == 'manual'):
                if any(self._read(profile['uuid'], name) for name in ('ipv4.gateway', 'ipv4.dns', 'ipv4.routes')):
                    raise StandError('A profile has IPv4 settings that cannot be preserved while disabled.')
        current_binding = self.binding.read()
        if current_binding is not None and current_binding != source['device']:
            raise StandError('DHCP/TFTP is configured for a different interface.')
        self.binding.validate(target['device'])
        service_active = self.service.active()
        if stand_ip == '172.20.0.2' and service_active:
            raise StandError('The secondary station must not run DHCP/TFTP on the shared stand.')
        source_addresses = [a for a in source['addresses'] if a != stand_address]
        source_method = 'disabled' if source['method'] == 'manual' and not source_addresses else source['method']
        target_method = 'manual' if target['method'] == 'disabled' else target['method']
        source['arguments'] = ['ipv4.method', source_method, 'ipv4.addresses', ','.join(source_addresses)]
        target['arguments'] = ['ipv4.method', target_method, 'ipv4.addresses', ','.join(target['addresses'] + [stand_address])]
        return {'kind': 'stand', 'source': source, 'target': target, 'address': stand_address, 'binding_before': current_binding,
                'service_active': service_active}

    def snapshot(self, change):
        return {'source': self.common.snapshot(change['source']), 'target': self.common.snapshot(change['target']),
                'binding': self.binding.read(), 'service_active': self.service.active()}

    def checkpoint(self, change):
        devices = [self.common.run('nmcli', '--escape', 'no', '-g', 'GENERAL.DBUS-PATH', 'device', 'show', change[side]['device'])
                   for side in ('source', 'target')]
        if not all(self.DEVICE_PATH.fullmatch(device) for device in devices):
            raise StandError('NetworkManager did not provide device identifiers.')
        output = self.common.run('busctl', 'call', 'org.freedesktop.NetworkManager', '/org/freedesktop/NetworkManager',
            'org.freedesktop.NetworkManager', 'CheckpointCreate', 'aouu', '2', *devices, '120', '0')
        match = re.fullmatch(r'o "(/org/freedesktop/NetworkManager/Checkpoint/\d+)"', output)
        if not match: raise StandError('NetworkManager did not create a checkpoint.')
        return match.group(1)

    def stage_temporary(self, change):
        for side in ('source', 'target'):
            self.common.stage_temporary(change[side])
            state = self.load()
            state.setdefault('partial', {})[side] = self.common.snapshot(change[side])
            self.store(state)

    def apply_temporary(self, change):
        for side in ('source', 'target'):
            self.common.run('nmcli', 'device', 'reapply', change[side]['device'])
        stand_ip = str(ipaddress.ip_interface(change['address']).ip)
        deadline = time.monotonic() + 20
        while time.monotonic() < deadline:
            addresses = self.common._json('ip', '-j', '-4', 'address', 'show')
            holders = [(d.get('ifname'), a.get('local'), a.get('prefixlen')) for d in addresses for a in d.get('addr_info', [])
                       if a.get('family') == 'inet' and a.get('local') == stand_ip]
            if holders == [(change['target']['device'], stand_ip, 16)]:
                break
            time.sleep(.2)
        else:
            raise StandError('The provisioning address did not move to the selected interface.')
        self.common._check_stand_routes()
        self.binding.write(change['target']['device'])
        self.service.reconcile(change['service_active'])

    def verify_before_confirm(self, original, change, applied):
        current = self.snapshot(change)
        if applied is None or current['source'] != applied['source'] or current['target'] != applied['target'] or \
                current['source']['file_hash'] != original['source']['file_hash'] or \
                current['target']['file_hash'] != original['target']['file_hash'] or \
                current['binding'] != change['target']['device'] or current['service_active'] != change['service_active']:
            raise StandError('The stand configuration changed outside cmprovision; recovery needs attention.')

    def persist(self, change):
        # Recheck each profile immediately before persisting it; the operation gate is still held.
        state = self.load()
        for side in ('source', 'target'):
            self.common.verify_before_confirm(state['original'][side], change[side], state['applied'][side])
            self.common.persist(change[side])

    def restore_original(self, original, change, applied, persisted, checkpoint, partial=None):
        current = self.snapshot(change)
        permitted_bindings = (original['binding'], change['target']['device']) if applied is not None else (original['binding'],)
        if current['binding'] not in permitted_bindings:
            raise StandError('The DHCP/TFTP interface was changed externally; automatic recovery is paused.')
        # Check both profiles before touching either, so an external edit is never overwritten.
        effective_applied = {} if applied is None else applied.copy()
        for side in ('source', 'target'):
            values = [original[side]['values']]
            hashes = [original[side]['file_hash']]
            profile_hashes = [original[side]['profile_hash']]
            if applied is not None:
                values.append(applied[side]['values'])
                profile_hashes.append(applied[side]['profile_hash'])
                if applied[side].get('expected_file_hash') is not None:
                    hashes.append(applied[side]['expected_file_hash'])
            if partial is not None and side in partial:
                values.append(partial[side]['values'])
                profile_hashes.append(partial[side]['profile_hash'])
            if persisted is not None:
                values.append(persisted[side]['values'])
                hashes.append(persisted[side]['file_hash'])
                profile_hashes.append(persisted[side]['profile_hash'])
            if applied is None:
                effective_applied[side] = partial[side] if partial is not None and side in partial else original[side]
            if current[side]['values'] not in values or current[side]['profile_hash'] not in profile_hashes or current[side]['file_hash'] not in hashes:
                raise StandError('A network profile changed externally; automatic recovery is paused.')
        try:
            self.common.rollback_checkpoint(checkpoint)
        except RuntimeError:
            pass
        # Remove the address from the target before restoring it on the source.
        for side in ('target', 'source'):
            self.common.restore_original(original[side], change[side], effective_applied[side],
                                         persisted[side] if persisted else None, checkpoint)
        self.binding.write(original['binding'])
        self.service.reconcile(original['service_active'])
