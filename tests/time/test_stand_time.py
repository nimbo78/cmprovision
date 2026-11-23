import json
import os
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools'))
from stand_time import Files, INTENT, LISTENER, OPTIONS, StandTime, TimeError, option_conflict, saved_intent


class ListenerTest(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.files = Files(Path(tmp.name), os.getuid())
        self.files.write('etc/machine-id', b'a' * 32)
        self.files.write(OPTIONS, b'')
        self.listener = ''
        self.fail_restart = False
        self.calls = []
        self.service = StandTime(self.files, self.command)
        self.service.role = lambda: ('eth0.15', '172.20.0.2', 'secondary')
        self.service.dhcp_process = lambda: []

    def command(self, name, *args):
        self.calls.append((name, *args))
        if name == 'ss':
            return self.listener
        if name == 'systemctl':
            if self.fail_restart:
                raise TimeError('test restart failed')
            self.listener = ('UNCONN 0 0 172.20.0.2%eth0.15:123 0.0.0.0:* users:(("chronyd",pid=5,fd=4))'
                             if self.files.read(LISTENER) else '')
        return ''

    def test_secondary_serves_without_dhcp_and_withdrawal_retries_a_failed_close(self):
        self.service.reconcile('chrony', True, True)
        self.assertTrue(self.service.status('chrony', True)['listening'])
        self.assertFalse(self.service.status('chrony', True)['advertising'])
        self.fail_restart = True
        with self.assertRaises(TimeError):
            self.service.reconcile('chrony', True, False)
        self.assertTrue(self.listener)
        self.assertIsNone(self.files.read(INTENT))
        self.fail_restart = False
        self.service.reconcile('chrony', True)
        self.assertFalse(self.listener)
        self.assertEqual(b'', self.files.read(OPTIONS))

    def test_changed_station_or_role_requires_new_consent(self):
        self.service.reconcile('chrony', True, True)
        previous = json.loads(self.files.read(INTENT))
        for field, other in [('role','primary'), ('station', 'b' * 32)]:
            self.files.write(INTENT, json.dumps(dict(previous, **{field: other})).encode())
            self.service.reconcile('chrony', False)
            self.assertIsNone(self.files.read(INTENT))
            self.assertFalse(self.listener)

    def test_competing_socket_is_preserved_and_never_advertised(self):
        self.listener = 'UNCONN 0 0 0.0.0.0:123 0.0.0.0:* users:(("ntpd",pid=3,fd=5))'
        with self.assertRaisesRegex(TimeError, 'already in use'):
            self.service.reconcile('chrony', True, True)
        self.assertIn('ntpd', self.listener)
        self.assertFalse(any(args[0] == 'systemctl' for args in self.calls))

    def test_only_string_machine_ids_can_authorize_a_role(self):
        with self.assertRaises(TimeError):
            saved_intent(dict(version=1, role='primary', station=10**31))

    def test_external_and_tagged_dhcp_options_are_reserved(self):
        for value in ('dhcp-option=42,172.20.0.1', 'dhcp-option-force=tag:device,option:ntp-server,10.0.0.1',
                      'dhcp-option=lab,42,172.20.0.1', 'dhcp-option=042,172.20.0.1', 'dhcp-option-pxe=42,172.20.0.1',
                      'conf-dir=/opt/site', 'dhcp-optsfile=/etc/site.opts'):
            self.assertTrue(option_conflict(value))
        self.assertFalse(option_conflict('# dhcp-option=42,10.0.0.1\ndhcp-option=option:router,172.20.0.1'))
