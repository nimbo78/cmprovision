import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

TOOLS = Path(__file__).resolve().parents[2]/'tools'
sys.path.insert(0, str(TOOLS))
from rescue_wifi_policy import DEFAULTS


@unittest.skipUnless(os.name == 'posix', 'Linux root helper')
class LinuxTest(unittest.TestCase):
    def setUp(self):
        if not (TOOLS/'rescue_wifi_linux.py').exists():
            self.fail('Linux shared-AP effects have not been implemented.')
        from rescue_wifi import Store, RescueError
        from rescue_wifi_linux import Linux, PROFILE_UUID
        self.Error, self.uuid = RescueError, PROFILE_UUID
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.store = Store(Path(self.tmp.name), verify_root=False)
        self.store.initialize()
        self.calls, self.fail_up = [], False
        self.tables, self.collision, self.backend = [], False, 'nftables'
        self.linux = Linux(self.store, runner=self.run_command)
        self.cfg = dict(DEFAULTS, enabled=True, ssid='cmprovision-test', password='secret-safe-12345678',
                        ap_mac='02:00:00:00:00:01', client_mac='02:00:00:00:00:02', subnet='192.168.240.0/24')
        self.radio = dict(device='wlan0', mac=self.cfg['ap_mac'], phy='phy0', bus='sdio', ap=True)
        (self.store.root/'etc/default/cmprovision-stand-interface').write_text('eth0\n')

    def run_command(self, argv, **kwargs):
        self.calls.append((argv, kwargs.get('input')))
        output, code = '', 0
        if argv[0].endswith('ip'):
            output = json.dumps([{'dst':'192.168.240.0/24'}] if self.collision and 'route' in argv else [])
        elif argv[0].endswith('NetworkManager'):
            output = '[main]\nfirewall-backend='+self.backend+'\n'
        elif argv[0].endswith('nft') and '-j' in argv:
            output = json.dumps({'nftables':self.tables})
        elif argv[0].endswith('nft') and kwargs.get('input'):
            body = kwargs['input']
            if 'add table' in body:
                self.tables = [{'table': {'family':'inet', 'name':'cmprovision_rescue',
                                          'comment':'cmprovision rescue source validation'}}]
            elif 'delete table' in body:
                self.tables = []
        elif argv[-4:-1] == ['connection','up','uuid'] and self.fail_up:
            code = 10
        elif 'connection' in argv and 'show' in argv:
            output = ''
        return subprocess.CompletedProcess(argv, code, stdout=output, stderr='do not expose raw diagnostics')

    def test_shared_profile_contains_no_default_route_and_keeps_psk_out_of_argv(self):
        self.linux.start(self.cfg, self.radio)
        text = self.linux.keyfile.read_text()
        self.assertIn('method=shared', text)
        self.assertIn('address1=192.168.240.1/24', text)
        self.assertIn('autoconnect=false', text)
        self.assertIn('method=disabled', text)
        self.assertIn('psk=secret-safe-12345678', text)
        self.assertEqual(0o600, self.linux.keyfile.stat().st_mode & 0o777)
        self.assertNotIn('secret-safe-12345678', repr(self.calls))
        self.assertFalse(any('cmprovision-dnsmasq' in repr(call) for call in self.calls))

    def test_missing_stand_binding_refuses_to_start(self):
        (self.store.root/'etc/default/cmprovision-stand-interface').unlink()
        with self.assertRaises(self.Error):
            self.linux.start(self.cfg, self.radio)
        self.assertFalse(any('up' in call[0] for call in self.calls))

    def test_route_collision_refuses_to_create_profile(self):
        self.collision = True
        with self.assertRaises(ValueError):
            self.linux.start(self.cfg, self.radio)
        self.assertFalse(self.linux.keyfile.exists())

    def test_disabled_nm_firewall_backend_is_actionable_failure(self):
        self.backend = 'none'
        with self.assertRaises(self.Error):
            self.linux.start(self.cfg, self.radio)
        self.assertFalse(self.linux.keyfile.exists())

    def test_failed_nm_start_is_deactivated_before_our_guard_is_removed(self):
        self.fail_up = True
        with self.assertRaises(self.Error):
            self.linux.start(self.cfg, self.radio)
        down = next(i for i, (argv, _) in enumerate(self.calls) if 'down' in argv)
        remove = next(i for i, (_, body) in enumerate(self.calls) if body and 'delete table' in body)
        self.assertLess(down, remove)

    def test_foreign_table_with_our_name_is_never_flushed(self):
        self.tables = [{'table': {'family':'inet', 'name':'cmprovision_rescue', 'comment':'other owner'}}]
        with self.assertRaises(self.Error):
            self.linux.start(self.cfg, self.radio)
        self.assertFalse(any(body and 'delete table' in body for _, body in self.calls))

    def test_guard_does_not_block_legitimate_routed_endpoint_access(self):
        self.linux.start(self.cfg, self.radio)
        guard = next(body for _, body in self.calls if body and 'add table' in body)
        self.assertIn('192.168.240.0/24', guard)
        self.assertIn('0.0.0.0', guard)
        self.assertIn('hook input', guard)
        self.assertNotIn('hook forward', guard)
        self.assertNotIn('flush ruleset', guard)


if __name__ == '__main__':
    unittest.main()
