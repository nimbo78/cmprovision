import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock


spec = importlib.util.spec_from_file_location('station_time', Path(__file__).resolve().parents[2] / 'tools/station-time.py')
time_control = importlib.util.module_from_spec(spec)
spec.loader.exec_module(time_control)


class RequestTest(unittest.TestCase):
    def test_only_exact_bounded_requests_are_accepted(self):
        for request in (None, [], {}, {'action': 'status', 'path': '/tmp/x'}, {'action': 'set-time'},
                        {'action': ['status']}, {'action': 'set-timezone', 'timezone': '../UTC'},
                        {'action': 'set-timezone', 'timezone': 'Mars/Olympus'},
                        {'action': 'set-ntp', 'enabled': 'yes', 'servers': []},
                        {'action': 'set-ntp', 'enabled': 1, 'servers': []},
                        {'action': 'set-ntp', 'enabled': True, 'servers': 'pool.ntp.org'},
                        {'action': 'set-ntp', 'enabled': True, 'servers': ['a.test'] * 9}):
            with self.subTest(request=request), self.assertRaises(time_control.TimeError):
                time_control.parse_request(json.dumps(request).encode())
        with self.assertRaises(time_control.TimeError):
            time_control.parse_request(b' ' * 8193)
        self.assertEqual({'action': 'status'}, time_control.parse_request(b'{"action":"status"}'))
        self.assertEqual('Asia/Jerusalem', time_control.parse_request(
            b'{"action":"set-timezone","timezone":"Asia/Jerusalem"}')['timezone'])

    def test_servers_accept_names_and_ipv4_ipv6_and_reject_injection(self):
        good = ['time.example.test', '172.20.0.2', '2001:db8::123']
        self.assertEqual(good, time_control.parse_request(json.dumps(
            dict(action='set-ntp', enabled=True, servers=good)).encode())['servers'])
        for server in ('-x', 'a\n[Time]\nNTP=evil.test', 'a;b', '$(id)', 'https://time.test',
                       'host:123', '1.2.3.999', '::not:ip', 'a' * 254, '', 123, None, '_bad.test'):
            with self.subTest(server=server), self.assertRaises(time_control.TimeError):
                time_control.parse_request(json.dumps(dict(action='set-ntp', enabled=True, servers=[server])).encode())


class FakeSystem:
    def __init__(self):
        self.calls = []
        self.zone = 'Etc/UTC'
        self.enabled = True
        self.active = True
        self.other = False
        self.missing = False
        self.fail = None
        self.fail_forever = False

    def __call__(self, command, **kwargs):
        self.calls.append(command)
        self_test = command[0] in ('/usr/bin/systemctl', '/usr/bin/timedatectl')
        if not self_test or kwargs.get('shell'):
            raise AssertionError('Unsafe command: ' + repr(command))
        if self.fail and self.fail in command:
            if not self.fail_forever:
                self.fail = None
            return subprocess.CompletedProcess(command, 1, '', 'private system output')
        if command[0].endswith('timedatectl'):
            if 'show-timesync' in command:
                if not self.active:
                    return subprocess.CompletedProcess(command, 1, '', 'not running')
                text = ('SystemNTPServers=time.example.test\nLinkNTPServers=172.20.0.9\n'
                        'FallbackNTPServers=0.debian.pool.ntp.org\nServerName=time.example.test\nServerAddress=192.0.2.10\n')
            elif 'show' in command:
                text = f'Timezone={self.zone}\nCanNTP=yes\nNTP={"yes" if self.active else "no"}\nNTPSynchronized={"yes" if self.active else "no"}\n'
            elif 'set-timezone' in command:
                self.zone = command[-1]
                text = ''
            elif 'set-ntp' in command:
                self.enabled = self.active = command[-1] == 'true'
                text = ''
            else:
                raise AssertionError(command)
        elif 'show' in command:
            is_timesyncd = 'systemd-timesyncd.service' in command
            found = not self.missing if is_timesyncd else self.other and 'chrony.service' in command
            text = f'LoadState={"loaded" if found else "not-found"}\nActiveState={"active" if found and self.active else "inactive"}\nUnitFileState={"enabled" if self.enabled else "disabled"}\n'
        else:
            text = ''
            if 'enable' in command: self.enabled = True
            if 'disable' in command: self.enabled = False
            if 'restart' in command or 'start' in command: self.active = True
            if 'stop' in command: self.active = False
        return subprocess.CompletedProcess(command, 0, text, '')


class ControllerTest(unittest.TestCase):
    def setUp(self):
        patch = mock.patch.object(time_control, 'ROOT_UID', os.geteuid())
        patch.start()
        self.addCleanup(patch.stop)
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.etc = self.root / 'etc'
        self.etc.mkdir()
        self.system = FakeSystem()
        self.controller = time_control.Controller(etc=self.etc, runtime=self.root/'run', run=self.system)
        self.config = self.etc/'systemd/timesyncd.conf.d/90-cmprovision.conf'

    def tearDown(self):
        self.temp.cleanup()

    def request(self, **values):
        return self.controller.handle(time_control.parse_request(json.dumps(values).encode()))

    def test_status_is_read_only_and_reports_actual_link_and_selected_servers(self):
        value = self.request(action='status')
        self.assertEqual('Etc/UTC', value['timezone'])
        self.assertTrue(value['ntp_enabled'])
        self.assertTrue(value['synchronized'])
        self.assertTrue(value['ntp_available'])
        self.assertEqual(['172.20.0.9'], value['link_servers'])
        self.assertEqual('time.example.test', value['server_name'])
        self.assertFalse(self.config.exists())
        self.assertFalse((self.root/'run').exists())
        self.assertTrue(all('show' in call or 'show-timesync' in call for call in self.system.calls))

    def test_missing_or_conflicting_provider_is_not_replaced(self):
        for attr in ('missing', 'other'):
            with self.subTest(attr=attr):
                setattr(self.system, attr, True)
                state = self.request(action='status')
                self.assertFalse(state['ntp_available'])
                with self.assertRaises(time_control.TimeError):
                    self.request(action='set-ntp', enabled=True, servers=['time.test'])
                self.assertFalse(self.config.exists())
                setattr(self.system, attr, False)

    def test_timezone_is_independent_of_missing_ntp_provider(self):
        self.system.missing = True
        self.assertEqual('Asia/Jerusalem', self.request(action='set-timezone', timezone='Asia/Jerusalem')['timezone'])
        self.assertFalse(self.config.exists())

    def test_custom_list_is_atomic_and_reset_preserves_admin_defaults(self):
        admin = self.etc/'systemd/timesyncd.conf'
        admin.parent.mkdir()
        admin.write_text('[Time]\nNTP=admin.test\n')
        self.request(action='set-ntp', enabled=True, servers=['172.20.0.2', 'time.example.test'])
        self.assertIn('NTP=\nNTP=172.20.0.2 time.example.test\n', self.config.read_text())
        self.assertIn('FallbackNTP=\n', self.config.read_text())
        self.assertEqual(0o644, self.config.stat().st_mode & 0o777)
        self.request(action='set-ntp', enabled=True, servers=[])
        self.assertFalse(self.config.exists())
        self.assertEqual('[Time]\nNTP=admin.test\n', admin.read_text())

    def test_disabled_ntp_saves_list_without_restarting_or_starting(self):
        self.request(action='set-ntp', enabled=False, servers=['time.example.test'])
        self.assertFalse(self.system.active)
        self.assertTrue(self.config.exists())
        self.assertFalse(any('restart' in call or 'start' in call for call in self.system.calls))

    def test_failures_restore_config_and_previous_disabled_service_state(self):
        self.system.enabled = self.system.active = False
        self.config.parent.mkdir(parents=True)
        old = '[Time]\nNTP=old.test\n'
        self.config.write_text(old)
        self.system.fail = 'set-ntp'
        with self.assertRaisesRegex(time_control.TimeError, 'restored'):
            self.request(action='set-ntp', enabled=True, servers=['new.test'])
        self.assertEqual(old, self.config.read_text())
        self.assertFalse(self.system.enabled)
        self.assertFalse(self.system.active)

    def test_failed_restart_removes_new_config_and_restores_running_service(self):
        self.system.fail = 'restart'
        with self.assertRaisesRegex(time_control.TimeError, 'restored'):
            self.request(action='set-ntp', enabled=True, servers=['new.test'])
        self.assertFalse(self.config.exists())
        self.assertTrue(self.system.active)
        self.assertTrue(self.system.enabled)

    def test_failed_timezone_update_restores_previous_zone(self):
        self.system.fail = 'set-timezone'
        with self.assertRaisesRegex(time_control.TimeError, 'restored'):
            self.request(action='set-timezone', timezone='Asia/Jerusalem')
        self.assertEqual('Etc/UTC', self.system.zone)

    def test_failed_recovery_is_explicit_and_does_not_leak_command_output(self):
        self.system.fail, self.system.fail_forever = 'set-ntp', True
        with self.assertRaisesRegex(time_control.TimeError, 'recover') as error:
            self.request(action='set-ntp', enabled=True, servers=['new.test'])
        self.assertNotIn('private system output', str(error.exception))

    def test_untrusted_paths_and_symlinks_are_never_followed(self):
        victim = self.root/'victim'
        victim.write_text('unchanged')
        self.config.parent.mkdir(parents=True)
        self.config.symlink_to(victim)
        with self.assertRaises(time_control.TimeError):
            self.request(action='set-ntp', enabled=True, servers=['new.test'])
        self.assertEqual('unchanged', victim.read_text())
        self.config.unlink()
        self.config.parent.chmod(0o777)
        with self.assertRaises(time_control.TimeError):
            self.request(action='set-ntp', enabled=True, servers=[])

    def test_pending_update_blocks_mutations_but_not_status(self):
        pending = self.root/'pending.json'
        pending.write_text('{}')
        self.controller.update_pending = pending
        self.request(action='status')
        with self.assertRaises(time_control.TimeError):
            self.request(action='set-timezone', timezone='Asia/Jerusalem')


if __name__ == '__main__':
    unittest.main()
