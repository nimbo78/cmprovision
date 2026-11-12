"""Fixed privileged rescue Wi-Fi control. No root access to application data/SQLite."""
from contextlib import contextmanager
import fcntl
import json
import os
from pathlib import Path
import stat
import tempfile
import secrets
import string
import sys
import time

from rescue_wifi_policy import DEFAULTS, validate_config, choose_subnet, select_radios, has_management, decide


class RescueError(RuntimeError):
    pass


class Store:
    LIMIT = 65536

    def __init__(self, root=Path('/'), verify_root=True):
        self.root, self.verify_root = Path(root), verify_root
        self.config = self.root/'etc/default/cmprovision-rescue-wifi.json'
        self.runtime = self.root/'run/cmprovision'
        self.state = self.runtime/'rescue-wifi-state.json'
        self.status = self.runtime/'rescue-wifi.json'
        self.radio_lock = self.runtime/'uplink-spare.lock'

    def directory(self, directory):
        relative = directory.relative_to(self.root)
        current = self.root
        for name in relative.parts:
            current = current/name
            try:
                current.mkdir(mode=0o755)
            except FileExistsError:
                pass
            info = current.lstat()
            if not stat.S_ISDIR(info.st_mode) or info.st_mode & 0o022 or self.verify_root and info.st_uid != 0:
                raise RescueError('The rescue Wi-Fi state directory is not secure.')

    def initialize(self):
        self.directory(self.config.parent)
        self.directory(self.runtime)

    def _check(self, info, public=False):
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or \
                info.st_mode & (0o022 if public else 0o077) or self.verify_root and info.st_uid != 0:
            raise RescueError('A rescue Wi-Fi state file is not secure.')

    def read_text(self, path, public=False):
        self.directory(path.parent)
        try:
            fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        except FileNotFoundError:
            return None
        except OSError as error:
            raise RescueError('A rescue Wi-Fi state file could not be opened safely.') from error
        try:
            info = os.fstat(fd)
            self._check(info, public)
            if info.st_size > self.LIMIT:
                raise RescueError('A rescue Wi-Fi state file is too large.')
            with os.fdopen(fd, 'r', encoding='utf-8', closefd=False) as source:
                return source.read(self.LIMIT + 1)
        except (ValueError, UnicodeError) as error:
            raise RescueError('A rescue Wi-Fi state file is invalid.') from error
        finally:
            os.close(fd)

    def read(self, path, public=False):
        text = self.read_text(path, public)
        if text is None:
            return {}
        try:
            value = json.loads(text)
            if type(value) is not dict:
                raise ValueError()
            return value
        except ValueError as error:
            raise RescueError('A rescue Wi-Fi state file is invalid.') from error

    def write(self, path, value, public=False):
        self.write_text(path, json.dumps(value, ensure_ascii=False, allow_nan=False), public)

    def write_text(self, path, text, public=False):
        self.directory(path.parent)
        if os.path.lexists(path):
            self._check(path.lstat(), public)
        body = text.encode('utf-8')
        if len(body) > self.LIMIT:
            raise RescueError('Rescue Wi-Fi state is too large.')
        fd, temporary = tempfile.mkstemp(prefix='.rescue-', dir=path.parent)
        try:
            with os.fdopen(fd, 'wb') as destination:
                destination.write(body + b'\n')
                destination.flush()
                os.fchmod(destination.fileno(), 0o644 if public else 0o600)
                os.fsync(destination.fileno())
            os.replace(temporary, path)
        finally:
            if os.path.lexists(temporary):
                os.unlink(temporary)

    @contextmanager
    def lock(self):
        self.directory(self.runtime)
        try:
            fd = os.open(self.radio_lock, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o644)
        except OSError as error:
            raise RescueError('The radio lock could not be opened safely.') from error
        try:
            self._check(os.fstat(fd), public=True)
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as error:
                raise RescueError('Another Wi-Fi operation is running; try again.') from error
            yield
        finally:
            os.close(fd)

    def pending(self):
        return any(os.path.lexists(self.root/name) for name in (
            'run/cmprovision-network/pending', 'run/cmprovision-backups/pending.json',
            'run/cmprovision-updates/pending.json', 'run/cmprovision-power/pending.json'))

    @contextmanager
    def gates(self):
        """Existing station order: backup mutations -> power -> network -> radio, all nonblocking."""
        descriptors = []
        try:
            for name in ('run/cmprovision-backups/mutations.lock', 'run/cmprovision-power/control.lock',
                         'run/cmprovision-network/provisioning.lock'):
                path = self.root/name
                if not os.path.lexists(path):
                    continue
                fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
                descriptors.append(fd)
                self._check(os.fstat(fd), public=True)
                try:
                    fcntl.flock(fd, fcntl.LOCK_SH | fcntl.LOCK_NB)
                except BlockingIOError as error:
                    raise RescueError('A station operation is in progress; retry shortly.') from error
            yield
        finally:
            for fd in reversed(descriptors):
                os.close(fd)


class Controller:
    def __init__(self, store, linux, monotonic=time.monotonic, wallclock=time.time, boot=None):
        self.store, self.linux = store, linux
        self.monotonic, self.wallclock = monotonic, wallclock
        self.boot = boot or (lambda: (store.root/'proc/sys/kernel/random/boot_id').read_text().strip())

    def config(self):
        return validate_config(self.store.read(self.store.config))

    def configure(self, values):
        if type(values) is not dict or set(values) - set(DEFAULTS):
            raise RescueError('Invalid rescue Wi-Fi settings.')
        with self.store.gates(), self.store.lock():
            config = dict(self.config(), **values)
            if not config['password']:
                config['password'] = ''.join(secrets.choice(string.ascii_letters + string.digits) for _ in range(20))
            if not config['ssid']:
                config['ssid'] = 'cmprovision-' + (os.uname().nodename[-10:].replace('.', '-') or 'station') + '-rescue'
            if config['enabled']:
                ap, client = select_radios(self.linux.radios(), config['ap_mac'], config['client_mac'])
                config['ap_mac'] = ap['mac']
                config['client_mac'] = client['mac'] if client else config['client_mac']
                if ap['device'] == self.linux.binding():
                    raise RescueError('The flashing interface cannot be the rescue AP.')
                self.linux.check_backend()
                # Active AP changes are staged; its current route must not reject a harmless settings save.
                state = self.store.read(self.store.state)
                exclude = ap['device'] if state.get('active_config') else None
                config['subnet'] = choose_subnet(self.linux.networks(exclude_device=exclude), config['subnet'])
            config = validate_config(config)
            if config['management_profiles']:
                eligible = {p['uuid'] for p in self.linux.profiles() if p['type'] in ('ethernet', 'vlan')}
                if not set(config['management_profiles']).issubset(eligible):
                    raise RescueError('Select existing wired or VLAN profiles for management.')
            self.store.write(self.store.config, config)
            if not config['enabled']:
                state = self.store.read(self.store.state)
                for key in ('hold_until', 'pause_until', 'restart_requested', 'lost_since', 'healthy_since', 'idle_since', 'retry_after'):
                    state.pop(key, None)
                self.store.write(self.store.state, state)
            return {'saved':True, 'password_configured':bool(config['password'])}

    def request(self, action):
        if action not in ('start', 'pause', 'resume', 'restart'):
            raise RescueError('Unknown rescue Wi-Fi action.')
        with self.store.gates(), self.store.lock():
            if not self.config()['enabled']:
                raise RescueError('Enable and configure rescue Wi-Fi first.')
            state = self.store.read(self.store.state)
            if state.get('boot_id') != self.boot():
                state = {'boot_id':self.boot()}
            state['hold_until'] = self.monotonic() + 900 if action in ('start', 'restart') else 0
            state['pause_until'] = self.monotonic() + 900 if action == 'pause' else 0
            state['restart_requested'] = action == 'restart'
            state.pop('retry_after', None)
            self.store.write(self.store.state, state)
            return {'queued':True}

    def owns(self):
        try:
            return self.config()['enabled'] or bool(self.store.read(self.store.state).get('active_config'))
        except (ValueError, RescueError):
            # Malformed policy must never release a possibly active AP to the legacy disconnect timer.
            return True

    def busy(self):
        state = self.store.read(self.store.state)
        return bool(state.get('active_config') or self.config()['enabled'] and state.get('radio_in_use'))

    def _observed(self, config, state):
        from rescue_wifi_linux import PROFILE_UUID
        result = {'known':True, 'ready':True, 'management':False, 'ap_active':bool(state.get('active_config')),
                  'radio_busy':False, 'blocked':False, 'deferred':self.store.pending(), 'clients':None,
                  'spare_enabled':True, 'spare_connected':False, 'other_management':False}
        radios, profiles, ap = [], [], None
        try:
            radios, profiles = self.linux.radios(), self.linux.profiles()
            result['ap_active'] = any(p['uuid'] == PROFILE_UUID and p['active'] for p in profiles)
            active_config = state.get('active_config') if result['ap_active'] else None
            effective = active_config or config
            ap, _ = select_radios(radios, effective['ap_mac'], effective['client_mac'])
            stand = self.linux.binding()
            if stand == ap['device']:
                raise RescueError('The AP radio is assigned to flashing.')
            result['management'] = has_management(profiles, stand, config['management_profiles'])
            result['other_management'] = has_management([p for p in profiles if p['device'] != ap['device']],
                                                       stand, config['management_profiles'])
            result['uplink_device'] = next((p['device'] for p in profiles if p['device'] != ap['device'] and
                                           has_management([p], stand, config['management_profiles'])), '')
            result['spare_connected'] = any(p['active'] and p['device'] == ap['device'] and p['uuid'] != PROFILE_UUID for p in profiles)
            result['radio_busy'] = any(p['active'] and p['device'] == ap['device'] and p['uuid'] != PROFILE_UUID and
                                      (p['type'] != 'wifi' or p.get('mode') not in ('', 'infrastructure')) for p in profiles)
            result['blocked'] = not self.linux.wifi_enabled()
            text = self.store.read_text(self.store.root/'etc/default/cmprovision-uplink', public=True) or ''
            result['spare_enabled'] = not any(line.strip() == 'SPARE=no' for line in text.splitlines())
            if result['ap_active']:
                result['clients'] = self.linux.clients(ap['device'])
        except (RescueError, ValueError, OSError) as error:
            result.update(known=False, ready=False, unavailable_reason='ap_not_ready')
        return result, ap, radios

    def _publish(self, config, state, observed, ap=None):
        active = bool(observed.get('ap_active'))
        effective = state.get('active_config') or config
        address = str(__import__('ipaddress').ip_network(effective['subnet']).network_address + 1) if effective['subnet'] else None
        public = {'schema':1, 'checked_at':int(self.wallclock()), 'enabled':config['enabled'],
                  'state':state.get('state', 'off'), 'reason':state.get('reason', 'disabled'),
                  'ssid':effective['ssid'], 'address':address, 'device':ap['device'] if ap else None,
                  'clients':observed.get('clients'), 'remaining':state.get('remaining'), 'active':active,
                  'pending_settings':active and effective != config}
        self.store.write(self.store.status, public, public=True)
        if ap and observed.get('known') and (config['enabled'] or active or state.get('action') == 'stop'):
            legacy_path = self.store.runtime/'uplink-spare'
            previous = dict(line.split('=', 1) for line in (self.store.read_text(legacy_path, public=True) or '').splitlines() if '=' in line)
            owns_ap = active or state.get('action') == 'start'
            legacy_state = 'waiting' if owns_ap else 'off' if not observed.get('spare_enabled', True) else \
                'active' if observed.get('spare_connected') else 'standby' if observed.get('other_management') else 'waiting'
            now = int(self.wallclock())
            since = previous.get('since', '') if previous.get('state') == legacy_state else ''
            since = since if since.isdigit() else str(now)
            lost = previous.get('lost_since', '') if legacy_state == 'active' else ''
            lost = lost if lost.isdigit() else str(now) if legacy_state == 'active' else ''
            body = f"state={legacy_state}\nsince={since}\nspare={ap['device']}\nuplink={observed.get('uplink_device', '')}\nup_since=\nlost_since={lost}\nrescue_ap={int(owns_ap)}\n"
            self.store.write_text(legacy_path, body, public=True)
        return public

    def check(self):
        with self.store.gates(), self.store.lock():
            config, old = self.config(), self.store.read(self.store.state)
            if not config['enabled'] and not old.get('active_config'):
                return self._publish(config, {'state':'off', 'reason':'disabled'}, {})
            observed, ap, _ = self._observed(config, old)
            state = decide(config, observed, old, self.monotonic(), self.boot())
            state['radio_in_use'] = bool(observed['ap_active'] or observed['spare_connected']) if observed['known'] else bool(old.get('radio_in_use'))
            if old.get('restart_requested') and old.get('boot_id') == self.boot():
                state['restart_requested'] = True
                if observed['ap_active'] and observed['known'] and not observed['deferred']:
                    state.update(action='stop', reason='manual_restart')
            if observed['ap_active']:
                state['active_config'] = old.get('active_config') or config
                if observed['known'] and not observed['deferred']:
                    try:
                        choose_subnet(self.linux.networks(inactive=False, exclude_device=ap['device']), state['active_config']['subnet'])
                    except ValueError:
                        state.update(action='stop', reason='subnet_conflict')
            action = state['action']
            if action == 'start':
                state['active_config'] = config
                self.store.write(self.store.state, state)  # ownership survives worker interruption during NM activation
                self._publish(config, state, observed, ap)
                try:
                    self.linux.start(config, ap)
                    observed.update(ap_active=True, clients=0)
                    state.update(state='active', reason='management_unavailable', failures=0)
                    state.pop('retry_after', None)
                    state.pop('restart_requested', None)
                except (RescueError, ValueError) as error:
                    failures = min(int(old.get('failures', 0)) + 1, 4)
                    state.update(state='fault', reason='start_failed', failures=failures,
                                 retry_after=self.monotonic() + (30, 60, 120, 300)[failures - 1])
                    # Preserve ownership until an observation confirms the AP is down.
            elif action == 'stop':
                self.linux.stop()
                state.pop('active_config', None)
                observed.update(ap_active=False, clients=0)
                state['radio_in_use'] = False
                state['state'] = 'paused' if state.get('pause_until', 0) > self.monotonic() else 'standby' if config['enabled'] else 'off'
            elif action in ('connect_spare', 'disconnect_spare'):
                try:
                    self.linux.spare(ap['device'], action == 'connect_spare')
                except RescueError:
                    pass  # keep observing; inability to join a client SSID is the reason this feature exists
            if not observed['ap_active'] and action != 'start':
                state.pop('active_config', None)
            self.store.write(self.store.state, state)
            return self._publish(config, state, observed, ap)

    def status(self):
        config = self.config()
        settings = {key:value for key, value in config.items() if key != 'password'}
        settings['password_configured'] = bool(config['password'])
        radios, choices, readiness = [], [], None
        try:
            radios = self.linux.radios()
            choices = [p for p in self.linux.profiles() if p['type'] in ('ethernet', 'vlan')]
            readiness = None
            self.linux.binding()
            if config['enabled']:
                select_radios(radios, config['ap_mac'], config['client_mac'])
        except (RescueError, OSError, ValueError) as error:
            readiness = str(error)
        return {'settings':settings, 'status':self.store.read(self.store.status, public=True),
                'radios':radios, 'management_profiles':choices, 'readiness_error':readiness}

    def reveal(self):
        config = self.config()
        active = self.store.read(self.store.state).get('active_config')
        return {'ssid':config['ssid'], 'password':config['password'],
                'active':{'ssid':active['ssid'], 'password':active['password']} if active else None}

    def retire(self):
        with self.store.gates(), self.store.lock():
            config = self.config()
            if config['enabled'] or self.store.read(self.store.state).get('active_config') or \
                    getattr(self.linux, 'keyfile', self.store.root/'nonexistent').exists():
                self.linux.retire()
            config['enabled'] = False
            self.store.write(self.store.config, config)
            self.store.write(self.store.state, {'boot_id':self.boot(), 'state':'off', 'reason':'disabled'})
            self._publish(config, {'state':'off', 'reason':'disabled'}, {})
            return {'retired':True}


def main():
    from rescue_wifi_linux import Linux
    if os.geteuid() != 0 or len(sys.argv) != 2:
        print(json.dumps({'error':'Use the installed privileged rescue Wi-Fi helper.'}))
        return 2
    store = Store()
    try:
        store.initialize()
        linux = Linux(store)
        control = Controller(store, linux)
        action = sys.argv[1]
        if action in ('owns', 'radio-busy'):
            return 0 if (control.owns() if action == 'owns' else control.busy()) else 1
        if action == 'wake':
            linux.run('/usr/bin/systemctl', '--no-block', 'start', 'cmprovision-rescue-wifi.service')
            return 0
        if action == 'configure':
            raw = sys.stdin.read(16385)
            if len(raw) > 16384:
                raise RescueError('Rescue Wi-Fi request is too large.')
            result = control.configure(json.loads(raw))
        elif action in ('start', 'pause', 'resume', 'restart'):
            result = control.request(action)
        elif action == 'status':
            result = control.status()
        elif action == 'reveal':
            result = control.reveal()
        elif action == 'check':
            result = control.check()
        elif action == 'retire':
            result = control.retire()
        else:
            raise RescueError('Unknown rescue Wi-Fi action.')
        if action in ('configure', 'start', 'pause', 'resume', 'restart'):
            linux.run('/usr/bin/systemctl', '--no-block', 'start', 'cmprovision-rescue-wifi.service', check=False)
        print(json.dumps(result, ensure_ascii=False))
        return 0
    except (ValueError, OSError, RescueError) as error:
        # No raw command output or configuration payloads are printed.
        print(json.dumps({'error':str(error) if isinstance(error, (ValueError, RescueError)) else 'Rescue Wi-Fi state is unavailable.'}))
        return 1
