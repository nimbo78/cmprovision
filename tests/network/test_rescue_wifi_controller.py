import os
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]/'tools'))
import rescue_wifi
from rescue_wifi_policy import DEFAULTS
from rescue_wifi_linux import PROFILE_UUID


class FakeLinux:
    def __init__(self):
        self.active, self.management, self.known = False, False, True
        self.count, self.calls = 0, []

    def radios(self):
        if not self.known:
            raise rescue_wifi.RescueError('NetworkManager does not answer.')
        return [dict(mac='02:00:00:00:00:01', device='wlan0', phy='phy0', bus='sdio', ap=True),
                dict(mac='02:00:00:00:00:02', device='wlan1', phy='phy1', bus='pci', ap=True)]

    def profiles(self):
        rows = []
        if self.active:
            rows.append(dict(uuid=PROFILE_UUID, device='wlan0', type='wifi', mode='ap', active=True, addresses=['192.168.240.1/24']))
        if self.management:
            rows.append(dict(uuid='00000000-0000-0000-0000-000000000001', device='wlan1', type='wifi', mode='infrastructure',
                             active=True, addresses=['192.168.68.23/24']))
        return rows

    def networks(self, **options): return ['172.20.0.0/16']
    def binding(self): return 'eth0'
    def check_backend(self): pass
    def wifi_enabled(self): return True
    def clients(self, device): return self.count
    def spare(self, device, connect): self.calls.append('spare-on' if connect else 'spare-off')
    def start(self, config, radio): self.calls.append('start'); self.active = True
    def stop(self): self.calls.append('stop'); self.active = False
    def remove_guard(self): self.calls.append('remove-guard')
    def retire(self): self.stop(); self.calls.append('retire')


@unittest.skipUnless(os.name == 'posix', 'Linux root controller')
class ControllerTest(unittest.TestCase):
    def setUp(self):
        self.assertTrue(hasattr(rescue_wifi, 'Controller'), 'The coordinated rescue controller is missing.')
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.store = rescue_wifi.Store(Path(self.tmp.name), verify_root=False)
        self.store.initialize()
        self.linux, self.now = FakeLinux(), 1000
        self.control = rescue_wifi.Controller(self.store, self.linux, monotonic=lambda:self.now,
                                              wallclock=lambda:1790000000, boot=lambda:'boot-a')

    def configure(self, **changes):
        return self.control.configure(dict(enabled=True, **changes))

    def test_enable_generates_unique_private_password_and_default_ten_minutes(self):
        result = self.configure()
        config = self.store.read(self.store.config)
        self.assertEqual(600, config['delay'])
        self.assertEqual(20, len(config['password']))
        self.assertNotIn(config['password'], str(result))
        self.assertTrue(result['password_configured'])

    def test_ap_starts_after_ten_minutes_and_zero_clients_do_not_stop_it_offline(self):
        self.configure()
        self.control.check()
        self.now = 1599
        self.control.check()
        self.assertNotIn('start', self.linux.calls)
        self.now = 1600
        self.control.check()
        self.assertEqual('start', self.linux.calls[-1])
        self.now = 9000
        self.control.check()
        self.assertTrue(self.linux.active)

    def test_active_configuration_is_staged_without_restarting_or_leaking_password(self):
        self.configure()
        self.control.request('start')
        self.control.check()
        self.assertTrue(self.linux.active)
        self.control.configure({'ssid':'New SSID', 'password':'new-secret-1234567'})
        self.control.check()
        status = self.store.read(self.store.status, public=True)
        self.assertTrue(status['pending_settings'])
        self.assertNotEqual('New SSID', status['ssid'])
        self.assertNotIn('new-secret', str(status))
        self.assertEqual(1, self.linux.calls.count('start'))

    def test_client_drain_prevents_automatic_eviction_after_network_recovery(self):
        self.configure()
        self.control.request('start')
        self.control.check()
        self.now = 2000
        self.linux.management, self.linux.count = True, 1
        self.control.check()
        self.now = 2100
        self.control.check()
        self.assertTrue(self.linux.active)
        self.linux.count = 0
        self.control.check()
        self.now = 2159
        self.control.check()
        self.assertTrue(self.linux.active)
        self.now = 2160
        self.control.check()
        self.assertFalse(self.linux.active)

    def test_pending_maintenance_preserves_existing_ap_and_defers_new_actions(self):
        self.configure()
        directory = self.store.root/'run/cmprovision-network'
        directory.mkdir()
        (directory/'pending').write_text('1')
        self.control.request('start')
        self.control.check()
        self.assertNotIn('start', self.linux.calls)

    def test_unavailable_nm_read_preserves_active_ap(self):
        self.configure()
        self.control.request('start')
        self.control.check()
        self.linux.known = False
        self.control.check()
        self.assertTrue(self.linux.active)
        self.assertNotIn('stop', self.linux.calls)

    def test_pause_stops_ap_but_does_not_disable_configuration(self):
        self.configure()
        self.control.request('start')
        self.control.check()
        self.control.request('pause')
        self.control.check()
        self.assertFalse(self.linux.active)
        self.assertTrue(self.store.read(self.store.config)['enabled'])
        self.now = 1900
        self.control.check()
        self.assertEqual('start', self.linux.calls[-1])

    def test_confirmed_restart_applies_staged_settings_on_the_next_activation(self):
        self.configure()
        self.control.request('start')
        self.control.check()
        self.control.configure({'ssid':'New SSID'})
        try:
            self.control.request('restart')
        except rescue_wifi.RescueError:
            self.fail('The explicit restart action has not been implemented.')
        self.control.check()
        self.assertFalse(self.linux.active)
        self.control.check()
        self.assertEqual(['start', 'stop', 'start'], self.linux.calls)
        self.assertEqual('New SSID', self.store.read(self.store.status, public=True)['ssid'])

    def test_arbitrary_profile_uuid_is_not_accepted_as_wired_management(self):
        with self.assertRaises(rescue_wifi.RescueError):
            self.configure(management_profiles=['00000000-0000-0000-0000-000000000099'])

    def test_disabling_cancels_a_queued_manual_start_before_reenable(self):
        self.configure()
        self.control.request('start')
        self.control.configure({'enabled':False})
        self.control.check()
        self.control.configure({'enabled':True})
        self.control.check()
        self.assertFalse(self.linux.active)


if __name__ == '__main__': unittest.main()
