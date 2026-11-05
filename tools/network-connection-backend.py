"""Activate/deactivate saved wired profiles with the existing durable network transaction.

Only autoconnect properties are edited. Secret profile data never enters transaction state.
Changing a connection (unlike an IP reapply) also protects parents of the provisioning VLAN.
"""
import ipaddress
import json
import os
import stat
import uuid


class ConnectionError(RuntimeError):
    pass


class Backend:
    def __init__(self, common):
        self.common = common

    def _read(self, connection, name):
        return self.common.run('nmcli', '--escape', 'no', '-g', name, 'connection', 'show', 'uuid', connection)

    def _active(self):
        rows = self.common.run('nmcli', '--escape', 'no', '-t', '-f', 'UUID,DEVICE', 'connection', 'show', '--active')
        return [line.split(':', 1) for line in rows.splitlines() if ':' in line]

    def _current(self, device):
        values = {connection for connection, interface in self._active() if interface == device}
        if len(values) > 1:
            raise ConnectionError('Multiple active profiles use this interface.')
        return next(iter(values), None)

    def _vlan_record(self, connection):
        directory = os.path.join(os.path.dirname(self.common.files.state_dir), 'vlans')
        path = os.path.join(directory, connection + '.json')
        try:
            info = os.lstat(directory)
        except FileNotFoundError:
            return None
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
            raise ConnectionError('The VLAN registry directory is not secure.')
        try:
            fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        except FileNotFoundError:
            return None
        with os.fdopen(fd, 'r', encoding='utf-8') as source:
            info = os.fstat(source.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077 or info.st_size > 4096:
                raise ConnectionError('The VLAN registry file is not secure.')
            data = json.load(source)
        if not isinstance(data, dict) or data.get('uuid') != connection:
            raise ConnectionError('The VLAN registry file does not match the profile.')
        return path

    def manage_saved(self, request):
        """Create a disconnected Ethernet profile or delete an inactive profile; no live IP edits."""
        with self.common.guard():
            if self.common.load() is not None or self.common.pending() or self.common.active_runs():
                raise ConnectionError('Wait for pending network changes and provisioning runs to finish.')
            if not isinstance(request, dict):
                raise ConnectionError('Invalid saved-profile request.')
            if request.get('operation') == 'create' and set(request) == {'operation', 'device', 'name'}:
                device, name = request['device'], request['name']
                if not isinstance(device, str) or not self.common.IFNAME.fullmatch(device) or \
                        self.common.run('nmcli', '-g', 'GENERAL.TYPE', 'device', 'show', device) != 'ethernet':
                    raise ConnectionError('Choose a managed physical Ethernet interface.')
                if not isinstance(name, str) or not 1 <= len(name.strip()) <= 64 or any(ord(c) < 32 or ord(c) == 127 for c in name):
                    raise ConnectionError('Enter a profile name of 1 to 64 characters without control characters.')
                connection = str(uuid.uuid4())
                self.common.run('nmcli', 'connection', 'add', 'type', 'ethernet', 'ifname', device,
                    'con-name', name.strip(), 'connection.uuid', connection, 'connection.autoconnect', 'no',
                    'ipv4.method', 'disabled', 'ipv6.method', 'disabled')
                return {'created': connection}
            if request.get('operation') == 'delete' and set(request) == {'operation', 'uuid'}:
                connection = request['uuid']
                try:
                    if not isinstance(connection, str) or str(uuid.UUID(connection)) != connection:
                        raise ValueError()
                except ValueError as exc:
                    raise ConnectionError('Invalid NetworkManager profile identifier.') from exc
                device = self._read(connection, 'connection.interface-name')
                profile = self._profile(connection, device, False)
                if any(value == connection for value, _ in self._active()):
                    raise ConnectionError('Disconnect and confirm the connection before deleting its saved profile.')
                for value in self._read(connection, 'ipv4.addresses').split(','):
                    if value.strip() and ipaddress.ip_interface(value.strip()).network.overlaps(self.common.STAND):
                        raise ConnectionError('This saved profile contains the provisioning network and cannot be deleted here.')
                original = self.common.snapshot(profile)
                record = self._vlan_record(connection)
                if any(value == connection for value, _ in self._active()) or self.common.snapshot(profile) != original:
                    raise ConnectionError('The profile changed while it was being checked; try again.')
                self.common.run('nmcli', 'connection', 'delete', 'uuid', connection)
                if record is not None:
                    os.unlink(record)
                return {'deleted': connection}
            raise ConnectionError('Invalid saved-profile operation.')

    def _profile(self, connection, device, autoconnect):
        try:
            if not isinstance(connection, str) or str(uuid.UUID(connection)) != connection:
                raise ValueError()
        except ValueError as exc:
            raise ConnectionError('Invalid NetworkManager profile identifier.') from exc
        if self._read(connection, 'connection.type') not in ('802-3-ethernet', 'vlan'):
            raise ConnectionError('Only Ethernet and VLAN profiles can be managed here.')
        if self._read(connection, 'connection.master') or self._read(connection, 'connection.secondaries'):
            raise ConnectionError('Port/controller and dependent VPN profiles need the system network tools.')
        for family, methods in [('ipv4', ('auto', 'manual', 'disabled', 'link-local')),
                                ('ipv6', ('auto', 'dhcp', 'manual', 'disabled', 'link-local', 'ignore'))]:
            if self._read(connection, family + '.method') not in methods:
                raise ConnectionError('This profile uses an unsupported IP assignment method.')
        bound = self._read(connection, 'connection.interface-name')
        if bound and bound != device:
            raise ConnectionError('The selected profile belongs to another interface.')
        active_devices = {interface for value, interface in self._active() if value == connection}
        if active_devices - {device}:
            raise ConnectionError('The selected profile is active on another interface.')
        filename = self.common._keyfile(connection)
        self.common._hash(filename)
        return {'device': device, 'uuid': connection, 'filename': filename,
                'arguments': ['connection.autoconnect', 'yes' if autoconnect else 'no']}

    def validate(self, request):
        if not isinstance(request, dict) or set(request) - {'kind', 'action', 'device', 'uuid', 'profile'} or \
                not {'kind', 'action', 'device', 'uuid'} <= set(request) or \
                request['kind'] != 'connection' or request['action'] not in ('activate', 'deactivate'):
            raise ConnectionError('Invalid connection request.')
        device = request['device']
        if not isinstance(device, str) or not self.common.IFNAME.fullmatch(device):
            raise ConnectionError('Choose a network interface.')
        activate = request['action'] == 'activate'
        target = self._profile(request['uuid'], device, activate)
        if 'profile' in request:
            if not activate:
                raise ConnectionError('IP settings can only be supplied when connecting a profile.')
            target['arguments'] += self.common.profile.properties(request['profile'])
        kind = self._read(target['uuid'], 'connection.type')
        links = self.common._json('ip', '-j', '-d', 'link', 'show')
        exists = any(link.get('ifname') == device for link in links)
        checkpoint_device = device
        if kind == 'vlan':
            if self._read(target['uuid'], 'connection.interface-name') != device:
                raise ConnectionError('A VLAN profile must be bound to its interface name.')
            parent = self._read(target['uuid'], 'vlan.parent')
            # Keep the supported lifecycle unambiguous: one tagged interface on physical Ethernet.
            if not self.common.IFNAME.fullmatch(parent):
                raise ConnectionError('This VLAN parent must be configured with the system network tools.')
            if self.common.run('nmcli', '-g', 'GENERAL.TYPE', 'device', 'show', parent) != 'ethernet':
                raise ConnectionError('The VLAN needs a managed physical Ethernet parent.')
            if not exists:
                checkpoint_device = parent
        elif not exists or self.common.run('nmcli', '-g', 'GENERAL.TYPE', 'device', 'show', device) != 'ethernet':
            raise ConnectionError('Choose a managed Ethernet interface.')
        if exists and self.common._stand_on(device, include_parents=True):
            raise ConnectionError('This connection carries the provisioning network; move or disable that network first.')
        current = self._current(device)
        if activate and current == target['uuid']:
            raise ConnectionError('The profile is already active.')
        if not activate and current != target['uuid']:
            raise ConnectionError('The selected profile is no longer active.')
        profiles = [target]
        if activate and current:
            profiles.append(self._profile(current, device, False))
        return {'kind': 'connection', 'action': request['action'], 'device': device, 'uuid': target['uuid'],
                'previous_uuid': current, 'checkpoint_device': checkpoint_device, 'profiles': profiles}

    def snapshot(self, change):
        return {profile['uuid']: self.common.snapshot(profile) for profile in change['profiles']}

    def checkpoint(self, change):
        return self.common.checkpoint({'device': change['checkpoint_device']})

    def stage_temporary(self, change):
        for profile in change['profiles']:
            self.common.stage_temporary(profile)
            state = self.common.load()
            state.setdefault('partial', {})[profile['uuid']] = self.common.snapshot(profile)
            self.common.store(state)

    def _up(self, connection, device):
        self.common.activate_profile(connection, device)

    def apply_temporary(self, change):
        if change['action'] == 'activate':
            self._up(change['uuid'], change['device'])
            self.common.apply_temporary(change['profiles'][0])  # address/route conflict checks
        else:
            self.common.run('nmcli', '--wait', '10', 'device', 'disconnect', change['device'])
        expected = change['uuid'] if change['action'] == 'activate' else None
        if self._current(change['device']) != expected:
            raise ConnectionError('The interface did not reach the requested connection state.')

    def verify_before_confirm(self, original, change, applied):
        if applied is None:
            raise ConnectionError('The connection change is incomplete.')
        expected = change['uuid'] if change['action'] == 'activate' else None
        if self._current(change['device']) != expected:
            raise ConnectionError('The active connection changed outside cmprovision.')
        for profile in change['profiles']:
            key = profile['uuid']
            self.common.verify_before_confirm(original[key], profile, applied[key])

    def persist(self, change):
        state = self.common.load()
        self.verify_before_confirm(state['original'], change, state['applied'])
        for profile in change['profiles']:
            self.common.persist(profile)

    def restore_original(self, original, change, applied, persisted, checkpoint, partial=None):
        current = self._current(change['device'])
        if current not in (None, change['previous_uuid'], change['uuid']):
            raise ConnectionError('Another connection was activated externally; automatic recovery is paused.')
        effective = applied if applied is not None else (partial or {})
        # Check every affected profile before changing any of them.
        for profile in change['profiles']:
            key = profile['uuid']
            self.common.check_restore(original[key], profile, effective.get(key), (persisted or {}).get(key))
        for profile in change['profiles']:
            key = profile['uuid']
            self.common.restore_original(original[key], profile, effective.get(key), (persisted or {}).get(key), checkpoint)
        current = self._current(change['device'])
        previous = change['previous_uuid']
        if previous and current != previous:
            self._up(previous, change['device'])
        elif previous is None and current is not None:
            self.common.run('nmcli', '--wait', '10', 'device', 'disconnect', change['device'])
        if self._current(change['device']) != previous:
            raise ConnectionError('The previous connection state could not be restored.')
