"""Privileged NetworkManager transaction backend.

Only an active wired/VLAN profile outside the provisioning interface is accepted. Hardware
acceptance and recovery after physical power loss are still required before release.
"""
import hashlib
import ipaddress
import json
import os
import re
import stat
import subprocess
import time
import uuid


class BackendError(RuntimeError):
    pass


class Backend:
    STAND = ipaddress.ip_network('172.20.0.0/16')
    IFNAME = re.compile(r'^[A-Za-z0-9_.-]{1,15}$')
    DEVICE_PATH = re.compile(r'^/org/freedesktop/NetworkManager/Devices/\d+$')
    CHECKPOINT = re.compile(r'^/org/freedesktop/NetworkManager/Checkpoint/\d+$')

    def __init__(self, files, profile, scheduler, active_check, run=None,
                 connection_dir='/etc/NetworkManager/system-connections'):
        self.files = files
        self.profile = profile
        self.scheduler = scheduler
        self.active_check = active_check
        self.run = run or self._run
        self.connection_dir = connection_dir

    @staticmethod
    def _run(*args):
        environment = dict(os.environ, PATH='/usr/sbin:/usr/bin:/sbin:/bin', LC_ALL='C')
        result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
                                timeout=30, check=False, env=environment)
        if result.returncode:
            raise BackendError('A network command failed: ' + result.stderr.strip()[:200])
        return result.stdout.strip()

    def guard(self): return self.files.guard()
    def load(self): return self.files.load()
    def pending(self): return self.files.pending()
    def store(self, value): self.files.store(value)
    def set_pending(self): self.files.set_pending()
    def clear_state(self): self.files.clear_state()
    def clear_pending(self): self.files.clear_pending()
    def active_runs(self): return self.active_check()
    def schedule_recovery(self, transaction_id): self.scheduler.schedule(transaction_id)
    def cancel_recovery(self, transaction_id): self.scheduler.cancel(transaction_id)

    def _json(self, *args):
        try:
            data = json.loads(self.run(*args))
        except ValueError as exc:
            raise BackendError('The current network configuration could not be read.') from exc
        if not isinstance(data, list):
            raise BackendError('The current network configuration could not be read.')
        return data

    def _keyfile(self, connection):
        lines = self.run('nmcli', '--escape', 'no', '-t', '-f', 'UUID,FILENAME', 'connection', 'show').splitlines()
        matches = [line.partition(':')[2] for line in lines if line.partition(':')[0] == connection]
        if not matches or len(set(matches)) != 1:
            raise BackendError('The saved NetworkManager profile cannot be identified.')
        filename = matches[0]
        if os.path.dirname(filename) != self.connection_dir or not filename.endswith('.nmconnection'):
            raise BackendError('Only persistent NetworkManager keyfile profiles can be edited here.')
        return filename

    def _file_bytes(self, filename, parent):
        if os.path.dirname(filename) != parent or not filename.endswith('.nmconnection'):
            raise BackendError('The NetworkManager profile path is not secure.')
        directory = os.lstat(parent)
        if not stat.S_ISDIR(directory.st_mode) or directory.st_uid != 0 or directory.st_mode & 0o022:
            raise BackendError('The NetworkManager profile directory is not secure.')
        fd = os.open(filename, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077 or info.st_size > 1048576:
                raise BackendError('The saved NetworkManager profile is not secure.')
            with os.fdopen(fd, 'rb', closefd=False) as source:
                return source.read()
        finally:
            os.close(fd)

    @staticmethod
    def _stable_keyfile_hash(data):
        # NetworkManager updates this timestamp on its own when a connection is activated.
        # Keep every other byte (including comments) so administrative edits are still detected.
        section = b''
        retained = []
        for line in data.splitlines(keepends=True):
            if line.startswith(b'[') and line.rstrip().endswith(b']'):
                section = line.strip()
            if section == b'[connection]' and line.startswith(b'timestamp='):
                continue
            retained.append(line)
        return hashlib.sha256(b''.join(retained)).hexdigest()

    def _hash(self, filename):
        return self._stable_keyfile_hash(self._file_bytes(filename, self.connection_dir))

    def _expected_persisted_hash(self, change):
        lines = self.run('nmcli', '--escape', 'no', '-t', '-f', 'UUID,FILENAME', 'connection', 'show').splitlines()
        matches = [line.partition(':')[2] for line in lines if line.partition(':')[0] == change['uuid']]
        if not matches or len(set(matches)) != 1:
            raise BackendError('The active profile cannot be identified.')
        active = matches[0]
        if active == change['filename']:
            return self._hash(active)
        data = self._file_bytes(active, '/run/NetworkManager/system-connections')
        parts = data.split(b'\n[.nmmeta]\n')
        if len(parts) != 2:
            # A different keyfile format is not unsafe: recovery just cannot predict a saved hash.
            return None
        metadata = parts[1].splitlines()
        expected = {b'shadowed-storage=' + change['filename'].encode('utf-8'), b'shadowed-owned=true'}
        if set(metadata) != expected or len(metadata) != len(expected):
            return None
        return self._stable_keyfile_hash(parts[0])

    def _stand_on(self, device, include_parents=False):
        addresses = self._json('ip', '-j', '-4', 'address', 'show')
        links = self._json('ip', '-j', '-d', 'link', 'show')
        by_name = {link.get('ifname'): link for link in links}
        by_index = {link.get('ifindex'): link for link in links}
        for interface in addresses:
            try:
                on_stand = any(ipaddress.ip_address(a['local']) in self.STAND for a in interface.get('addr_info', [])
                               if a.get('family') == 'inet')
            except (KeyError, ValueError) as exc:
                raise BackendError('A network interface address could not be checked.') from exc
            if not on_stand:
                continue
            if interface.get('ifname') == device:
                return True
            current = by_name.get(interface.get('ifname'))
            visited = set()
            while current is not None:
                name = current.get('ifname')
                if include_parents and name == device:
                    return True
                # IP-only reapply on a physical parent does not replace its VLAN child.
                # Keep checking unsupported topologies, but do not lock the parent's
                # independent untagged management network merely because a child is a stand.
                if name in visited:
                    raise BackendError('A network interface has an invalid parent chain.')
                visited.add(name)
                # A veth's link_index names its peer, not a parent. Only VLANs have a parent
                # relationship we understand here; do not follow arbitrary link indices.
                kind = current.get('linkinfo', {}).get('info_kind')
                if kind in ('bridge', 'bond', 'team', 'vrf'):
                    raise BackendError('A bridged or bonded provisioning network needs manual network management.')
                if kind != 'vlan':
                    break
                parent = current.get('link')
                current = by_name.get(parent) if parent else by_index.get(current.get('link_index'))
        return False

    def _check_stand_routes(self):
        addresses = self._json('ip', '-j', '-4', 'address', 'show')
        stand_devices = set()
        for interface in addresses:
            for address in interface.get('addr_info', []):
                try:
                    if address.get('family') == 'inet' and ipaddress.ip_address(address['local']) in self.STAND:
                        stand_devices.add(interface['ifname'])
                except (KeyError, ValueError) as exc:
                    raise BackendError('A network interface address could not be checked.') from exc
        rules = self._json('ip', '-j', '-4', '-N', 'rule', 'show')
        for route in self._json('ip', '-j', '-4', '-N', 'route', 'show', 'table', 'all'):
            destination = route.get('dst', 'default')
            try:
                network = ipaddress.ip_network('0.0.0.0/0' if destination == 'default' else destination, strict=False)
            except ValueError as exc:
                raise BackendError('An IPv4 route could not be checked.') from exc
            table = str(route.get('table', 'main'))
            custom_table = table not in ('main', 'local', '254', '255')
            # A source-specific management table is legitimate. A catch-all policy table
            # with a default route can steal sensor traffic even though main has /16.
            selected = not custom_table or any(str(rule.get('table')) == table and
                self._rule_may_select_stand(rule) for rule in rules)
            affects_stand = network.overlaps(self.STAND) and selected and (
                network.prefixlen >= self.STAND.prefixlen or custom_table)
            if affects_stand and route.get('dev') not in stand_devices:
                raise BackendError('A route on another interface could divert provisioning traffic.')

    def _rule_may_select_stand(self, rule):
        # iproute2 JSON uses src/dst; missing selectors mean all. Unknown/inverted
        # selectors fail closed. Marks/interfaces cannot prove the stand is excluded.
        if rule.get('not') or rule.get('flags'):
            return True
        for key in ('src', 'dst'):
            selector = rule.get(key, 'all')
            if selector == 'all':
                continue
            try:
                if '/' not in selector and key + 'len' in rule:
                    selector += '/' + str(rule[key + 'len'])
                if not ipaddress.ip_network(selector, strict=False).overlaps(self.STAND):
                    return False
            except ValueError as exc:
                raise BackendError('A routing rule could not be checked.') from exc
        return True

    def validate(self, request):
        if not isinstance(request, dict) or set(request) != {'uuid', 'device', 'profile'}:
            raise BackendError('Invalid network change request.')
        device, connection = request['device'], request['uuid']
        if not isinstance(device, str) or not self.IFNAME.fullmatch(device):
            raise BackendError('Invalid network interface.')
        try:
            if not isinstance(connection, str) or str(uuid.UUID(connection)) != connection:
                raise ValueError()
        except ValueError as exc:
            raise BackendError('Invalid NetworkManager profile identifier.') from exc
        if self.run('nmcli', '-g', 'GENERAL.CON-UUID', 'device', 'show', device) != connection:
            raise BackendError('The selected profile is no longer active on this interface.')
        active = self.run('nmcli', '--escape', 'no', '-t', '-f', 'UUID,DEVICE', 'connection', 'show', '--active').splitlines()
        devices = {line.partition(':')[2] for line in active if line.partition(':')[0] == connection}
        if devices != {device}:
            raise BackendError('This profile is active on more than one interface and cannot be edited here.')
        if self.run('nmcli', '-g', 'GENERAL.TYPE', 'device', 'show', device) not in ('ethernet', 'vlan'):
            raise BackendError('Only Ethernet and VLAN profiles can be edited here.')
        if self._stand_on(device):
            raise BackendError('This interface carries the provisioning network; use the stand network transaction.')
        args = self.profile.properties(request['profile'])
        ipv4 = request['profile'].get('ipv4', {})
        for address in ipv4.get('addresses', []):
            if ipaddress.ip_interface(address).network.overlaps(self.STAND):
                raise BackendError('The address overlaps the provisioning network.')
        for route in ipv4.get('routes', []):
            network = ipaddress.ip_network(route['destination'], strict=False)
            if network.prefixlen >= self.STAND.prefixlen and network.overlaps(self.STAND):
                raise BackendError('This route overlaps the provisioning network.')
        filename = self._keyfile(connection)
        self._hash(filename)
        return {'uuid': connection, 'device': device, 'arguments': args, 'filename': filename}

    def snapshot(self, change):
        values = [[name, self.run('nmcli', '--escape', 'no', '-g', name, 'connection', 'show', 'uuid', change['uuid'])]
                  for name in change['arguments'][::2]]
        # `profile` excludes live DHCP/device state. Root reads secret values only into a pipe to
        # hash the complete profile; neither the values nor the command output are stored or sent
        # to the browser. A hidden/unavailable secret would make conflict detection incomplete.
        full = self.run('nmcli', '--show-secrets', '--escape', 'no', '-t', '-f', 'profile',
                        'connection', 'show', 'uuid', change['uuid'])
        if '<hidden>' in full:
            raise BackendError('The complete network profile could not be checked safely.')
        full = '\n'.join(line for line in full.splitlines() if not line.startswith('connection.timestamp:'))
        return {'values': values, 'file_hash': self._hash(change['filename']),
                'expected_file_hash': self._expected_persisted_hash(change),
                'profile_hash': hashlib.sha256(full.encode('utf-8')).hexdigest()}

    def checkpoint(self, change):
        device = self.run('nmcli', '--escape', 'no', '-g', 'GENERAL.DBUS-PATH', 'device', 'show', change['device'])
        if not self.DEVICE_PATH.fullmatch(device):
            raise BackendError('NetworkManager did not provide a device identifier.')
        result = self.run('busctl', 'call', 'org.freedesktop.NetworkManager', '/org/freedesktop/NetworkManager',
                          'org.freedesktop.NetworkManager', 'CheckpointCreate', 'aouu', '1', device, '120', '0')
        match = re.fullmatch(r'o "(/org/freedesktop/NetworkManager/Checkpoint/\d+)"', result)
        if not match:
            raise BackendError('NetworkManager did not create a checkpoint.')
        return match.group(1)

    def rollback_checkpoint(self, checkpoint):
        if not self.CHECKPOINT.fullmatch(checkpoint):
            raise BackendError('Invalid NetworkManager checkpoint.')
        self.run('busctl', 'call', 'org.freedesktop.NetworkManager', '/org/freedesktop/NetworkManager',
                 'org.freedesktop.NetworkManager', 'CheckpointRollback', 'o', checkpoint)

    def commit_checkpoint(self, checkpoint):
        if not self.CHECKPOINT.fullmatch(checkpoint):
            raise BackendError('Invalid NetworkManager checkpoint.')
        self.run('busctl', 'call', 'org.freedesktop.NetworkManager', '/org/freedesktop/NetworkManager',
                 'org.freedesktop.NetworkManager', 'CheckpointDestroy', 'o', checkpoint)

    def stage_temporary(self, change):
        self.run('nmcli', 'connection', 'modify', '--temporary', 'uuid', change['uuid'], *change['arguments'])

    def activate_profile(self, connection, device):
        links = self._json('ip', '-j', 'link', 'show')
        kind = self.run('nmcli', '-g', 'connection.type', 'connection', 'show', 'uuid', connection)
        # NM creates a missing VLAN from the saved interface binding. ifname requires
        # an already existing device, but is necessary to pin unbound Ethernet profiles.
        suffix = ['ifname', device] if any(link.get('ifname') == device for link in links) or kind != 'vlan' else []
        self.run('nmcli', '--wait', '20', 'connection', 'up', 'uuid', connection, *suffix)

    def apply_temporary(self, change):
        self.run('nmcli', 'device', 'reapply', change['device'])
        self.wait_addresses(change)

    def wait_addresses(self, change, allow_stand=False, check_routes=True, wait_dhcp=True):
        requested = dict(zip(change['arguments'][::2], change['arguments'][1::2]))
        deadline = time.monotonic() + 20
        while time.monotonic() < deadline:
            # DHCP might assign a conflicting stand address despite no static address in the request.
            if not allow_stand and self._stand_on(change['device']):
                raise BackendError('The interface acquired an address on the provisioning network.')
            ready = True
            for family in (4, 6):
                prefix = 'ipv' + str(family) + '.'
                method = requested.get(prefix + 'method')
                if method is None or (family == 6 and method in ('auto', 'dhcp', 'link-local')):
                    continue
                devices = self._json('ip', '-j', '-' + str(family), 'address', 'show', 'dev', change['device'])
                actual = {a['local'] for device in devices for a in device.get('addr_info', [])
                          if a.get('scope') == 'global' and 'tentative' not in a.get('flags', [])}
                expected = {str(ipaddress.ip_interface(a.strip()).ip) for a in requested.get(prefix + 'addresses', '').split(',') if a.strip()}
                if method in ('manual', 'auto', 'dhcp') and not expected.issubset(actual):
                    ready = False
                elif wait_dhcp and method == 'auto' and family == 4 and not actual:
                    ready = False
                elif method == 'disabled' and actual:
                    ready = False
            if ready:
                # A DHCP lease can introduce routes not present in the submitted profile.
                if check_routes:
                    self._check_stand_routes()
                return
            time.sleep(.2)
        raise BackendError('The interface did not acquire the requested addresses in time.')

    def verify_before_confirm(self, original, change, applied):
        current = self.snapshot(change)
        if applied is None or current != applied or current['file_hash'] != original['file_hash']:
            raise BackendError('The profile changed outside cmprovision. Recovery needs an administrator.')

    def persist(self, change):
        self.run('nmcli', 'connection', 'modify', 'uuid', change['uuid'], *change['arguments'])

    def check_restore(self, original, change, applied, persisted):
        current = self.snapshot(change)
        allowed_values = [original['values']]
        allowed_profiles = [original['profile_hash']]
        if applied is not None:
            allowed_values.append(applied['values'])
            allowed_profiles.append(applied['profile_hash'])
        if persisted is not None:
            allowed_values.append(persisted['values'])
            allowed_profiles.append(persisted['profile_hash'])
        known_hashes = [original['file_hash']]
        if persisted is not None:
            known_hashes.append(persisted['file_hash'])
        elif applied is not None and applied.get('expected_file_hash') is not None:
            known_hashes.append(applied['expected_file_hash'])
        if current['values'] not in allowed_values or current['profile_hash'] not in allowed_profiles or current['file_hash'] not in known_hashes:
            # A power cut between saving the profile and recording its new hash can also land here.
            # Keep the marker and state rather than overwriting a possible external administrator edit.
            raise BackendError('The profile changed outside cmprovision; automatic recovery is paused.')

    def restore_original(self, original, change, applied, persisted, checkpoint, partial=None):
        self.check_restore(original, change, applied, persisted)
        try:
            self.rollback_checkpoint(checkpoint)
        except BackendError:
            pass  # NetworkManager may have restarted and discarded the checkpoint.
        values = [part for pair in original['values'] for part in pair]
        # Restore the persistent profile after checking its hash. A temporary modification
        # can leave an override in /run after recovery, making the next change ambiguous.
        self.run('nmcli', 'connection', 'modify', 'uuid', change['uuid'], *values)
        # After a reboot or unplug the device may not exist or the profile may not be active;
        # its restored persistent keyfile is still sufficient for the next activation.
        devices = self._json('ip', '-j', 'link', 'show')
        if any(device.get('ifname') == change['device'] for device in devices):
            active = self.run('nmcli', '-g', 'GENERAL.CON-UUID', 'device', 'show', change['device'])
            if active == change['uuid']:
                self.run('nmcli', 'device', 'reapply', change['device'])
        restored = self.snapshot(change)
        if restored['values'] != original['values'] or restored['profile_hash'] != original['profile_hash']:
            raise BackendError('NetworkManager did not restore the original profile.')
