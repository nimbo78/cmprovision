#!/usr/bin/python3
"""Fixed station time controller. No application database or caller-selected commands."""
import fcntl
import ipaddress
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import time
from zoneinfo import available_timezones

ROOT_UID = 0


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


def parse_request(raw):
    if len(raw) > 8192:
        raise TimeError('The time settings request is too large.')
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise TimeError('Duplicate request fields are not allowed.')
            result[key] = value
        return result
    try:
        request = json.loads(raw, object_pairs_hook=unique)
    except (ValueError, TypeError, RecursionError) as error:
        raise TimeError('Invalid time settings request.') from error
    allowed = {'status': {'action'}, 'set-timezone': {'action', 'timezone'},
               'set-ntp': {'action', 'enabled', 'servers'}}
    if not isinstance(request, dict) or not isinstance(request.get('action'), str) \
            or request['action'] not in allowed or set(request) != allowed[request['action']]:
        raise TimeError('Unknown time settings action or fields.')
    if request['action'] == 'set-timezone':
        if not isinstance(request['timezone'], str) or request['timezone'] not in available_timezones() \
                or request['timezone'] in ('localtime', 'posixrules'):
            raise TimeError('Choose an installed IANA time zone.')
    if request['action'] == 'set-ntp':
        if type(request['enabled']) is not bool:
            raise TimeError('NTP enabled must be a boolean.')
        request['servers'] = valid_servers(request['servers'])
    return request


class Controller:
    UNIT = 'systemd-timesyncd.service'
    OTHER_UNITS = ('chrony.service', 'chronyd.service', 'ntp.service', 'ntpsec.service', 'openntpd.service')

    def __init__(self, etc=Path('/etc'), runtime=Path('/run/cmprovision-time'), run=subprocess.run):
        self.etc, self.runtime, self.run = Path(etc), Path(runtime), run
        self.config = self.etc/'systemd/timesyncd.conf.d/90-cmprovision.conf'
        self.update_pending = Path('/run/cmprovision-updates/pending.json')

    def command(self, program, *arguments, required=True):
        try:
            result = self.run(['/usr/bin/'+program, *arguments], timeout=5, capture_output=True,
                              text=True, check=False, env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin',
                              'LANG': 'C.UTF-8', 'SYSTEMD_PAGER': '', 'SYSTEMD_COLORS': '0'})
        except (OSError, subprocess.TimeoutExpired) as error:
            raise TimeError('The system time service did not answer.') from error
        if result.returncode and required:
            raise TimeError('The system time service rejected the change.')
        return result.stdout if result.returncode == 0 else ''

    @staticmethod
    def properties(text):
        return dict(line.split('=', 1) for line in text.splitlines() if '=' in line)

    @staticmethod
    def secure_directory(path):
        info = path.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != ROOT_UID or info.st_mode & 0o022:
            raise TimeError('The time configuration directory is not secure.')

    def config_directory(self, create=False):
        self.secure_directory(self.etc)
        for path in (self.etc/'systemd', self.config.parent):
            if not os.path.lexists(path):
                if not create:
                    return False
                path.mkdir(mode=0o755)
            self.secure_directory(path)
        return True

    def read_config(self):
        if not self.config_directory() or not os.path.lexists(self.config):
            return None
        try:
            fd = os.open(self.config, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
        except OSError as error:
            raise TimeError('The managed NTP configuration is not a safe regular file.') from error
        with os.fdopen(fd, 'rb') as source:
            info = os.fstat(source.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_uid != ROOT_UID or info.st_mode & 0o022 or info.st_size > 8192:
                raise TimeError('The managed NTP configuration is not a safe regular file.')
            return source.read(8193)

    def write_config(self, data):
        self.config_directory(create=True)
        self.read_config()  # Reject unsafe existing files before replacement or deletion.
        if data is None:
            self.config.unlink(missing_ok=True)
            return
        fd, name = tempfile.mkstemp(prefix='.cmprovision-time-', dir=self.config.parent)
        try:
            with os.fdopen(fd, 'wb') as output:
                output.write(data)
                output.flush()
                os.fchmod(output.fileno(), 0o644)
                os.fsync(output.fileno())
            os.replace(name, self.config)
        finally:
            if os.path.exists(name):
                os.unlink(name)

    def unit(self, name):
        return self.properties(self.command('systemctl', 'show', name, '--no-pager',
            '--property=LoadState', '--property=ActiveState', '--property=UnitFileState', required=False))

    def status(self):
        clock = self.properties(self.command('timedatectl', 'show', '--all', '--no-pager'))
        unit = self.unit(self.UNIT)
        reason = None
        if unit.get('LoadState') != 'loaded' or unit.get('UnitFileState') not in ('enabled', 'disabled'):
            reason = 'NTP editing requires an installed, unmasked systemd-timesyncd service.'
        elif any(self.unit(name).get('LoadState') == 'loaded' for name in self.OTHER_UNITS):
            reason = 'Another time synchronization service is installed. Its settings are managed outside cmprovision.'
        sync = self.properties(self.command('timedatectl', 'show-timesync', '--all', '--no-pager', required=False)) if reason is None else {}
        managed = self.read_config()
        servers = []
        if managed:
            try:
                for line in managed.decode('utf-8').splitlines():
                    if line.startswith('NTP='):
                        servers = valid_servers(line[4:].split())
            except (UnicodeError, TimeError) as error:
                raise TimeError('The managed NTP configuration is invalid.') from error
        return dict(available=True, timezone=clock.get('Timezone', 'UTC'),
            timezones=sorted(available_timezones() - {'localtime', 'posixrules'}),
            timestamp=int(time.time()), ntp_available=reason is None, ntp_reason=reason,
            ntp_enabled=unit.get('UnitFileState') == 'enabled' if reason is None else clock.get('NTP') == 'yes',
            ntp_active=clock.get('NTP') == 'yes',
            synchronized={'yes': True, 'no': False}.get(clock.get('NTPSynchronized')),
            server_name=sync.get('ServerName') or None, server_address=sync.get('ServerAddress') or None,
            system_servers=sync.get('SystemNTPServers', '').split(), link_servers=sync.get('LinkNTPServers', '').split(),
            fallback_servers=sync.get('FallbackNTPServers', '').split(), managed_servers=servers,
            provider='systemd-timesyncd' if reason is None else None)

    def handle(self, request):
        request = parse_request(json.dumps(request).encode())  # Also enforce schema for non-CLI callers.
        if request['action'] == 'status':
            return self.status()
        if self.update_pending.exists():
            raise TimeError('Wait for the station update to finish before changing time settings.')
        if not os.path.lexists(self.runtime):
            self.runtime.mkdir(mode=0o755)
        self.secure_directory(self.runtime)
        fd = os.open(self.runtime/'control.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != ROOT_UID or info.st_mode & 0o077:
                raise TimeError('The time settings lock is not secure.')
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as error:
                raise TimeError('Another time settings change is running.') from error
            if self.update_pending.exists():
                raise TimeError('Wait for the station update to finish before changing time settings.')
            previous = self.status()
            if request['action'] == 'set-timezone':
                return self.timezone(request['timezone'], previous)
            if not previous['ntp_available']:
                raise TimeError(previous['ntp_reason'])
            return self.ntp(request['enabled'], request['servers'], previous)
        finally:
            os.close(fd)

    def timezone(self, zone, previous):
        try:
            self.command('timedatectl', 'set-timezone', zone)
            current = self.status()
            if current['timezone'] != zone:
                raise TimeError('The selected time zone was not applied.')
            return current
        except (TimeError, OSError) as error:
            try:
                self.command('timedatectl', 'set-timezone', previous['timezone'])
            except TimeError as recovery:
                raise TimeError('The time zone could not be changed or recovered. Inspect the station from its console.') from recovery
            raise TimeError('The time zone change failed; the previous time zone was restored.') from error

    def ntp(self, enabled, servers, previous):
        old = self.read_config()
        data = ('# Managed by cmprovision. Empty server list restores system defaults.\n[Time]\nNTP=\nNTP='
                + ' '.join(servers) + '\nFallbackNTP=\n').encode() if servers else None
        try:
            self.write_config(data)
            self.command('timedatectl', 'set-ntp', 'true' if enabled else 'false')
            if enabled:
                self.command('systemctl', 'restart', self.UNIT)
            return self.status()
        except (TimeError, OSError) as error:
            try:
                self.write_config(old)
                self.command('timedatectl', 'set-ntp', 'true' if previous['ntp_enabled'] else 'false')
                self.command('systemctl', 'restart' if previous['ntp_active'] else 'stop', self.UNIT)
            except (TimeError, OSError) as recovery:
                raise TimeError('NTP settings could not be changed or recovered. Inspect the station from its console.') from recovery
            raise TimeError('The NTP change failed; the previous configuration and service state were restored.') from error


def main():
    try:
        if os.geteuid() != 0 or sys.argv[1:]:
            raise TimeError('This fixed controller requires root and accepts no command arguments.')
        result = Controller().handle(parse_request(sys.stdin.buffer.read(8193)))
        print(json.dumps({'ok': True, 'result': result}))
        return 0
    except (TimeError, OSError, ValueError, RecursionError) as error:
        print(json.dumps({'ok': False, 'error': str(error) if isinstance(error, TimeError) else 'The time settings request could not be completed.'}))
        return 1


if __name__ == '__main__':
    sys.exit(main())
