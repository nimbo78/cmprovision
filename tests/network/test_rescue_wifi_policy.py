"""Public radio policy: no device effects, real timestamps and literal expectations."""
import importlib.util
import sys
from pathlib import Path
import unittest

TOOLS = Path(__file__).resolve().parents[2] / 'tools'
sys.path.insert(0, str(TOOLS))


class PolicyTest(unittest.TestCase):
    def setUp(self):
        if not (TOOLS/'rescue_wifi_policy.py').exists():
            self.fail('Rescue Wi-Fi policy has not been implemented.')
        import rescue_wifi_policy as policy
        self.p = policy
        self.cfg = dict(policy.DEFAULTS, enabled=True, ap_mac='02:00:00:00:00:01', client_mac='02:00:00:00:00:02')
        self.obs = dict(known=True, ready=True, management=False, ap_active=False, radio_busy=False,
                        blocked=False, deferred=False, clients=0, spare_enabled=True, spare_connected=False,
                        other_management=False)

    def step(self, now, state=None, **observed):
        return self.p.decide(self.cfg, dict(self.obs, **observed), state or {}, now, 'boot-a')

    def test_search_is_ten_minutes_and_does_not_start_at_three(self):
        first = self.step(10)
        self.assertEqual('waiting', first['state'])
        self.assertEqual(600, first['remaining'])
        at_three = self.step(190, first)
        self.assertNotEqual('start', at_three['action'])
        before = self.step(609, at_three)
        self.assertNotEqual('start', before['action'])
        self.assertEqual('start', self.step(610, before)['action'])

    def test_cold_spare_attempts_after_thirty_seconds_with_retry_spacing(self):
        first = self.step(100)
        self.assertIsNone(self.step(129, first)['action'])
        attempt = self.step(130, first)
        self.assertEqual('connect_spare', attempt['action'])
        self.assertIsNone(self.step(145, attempt)['action'])
        self.assertEqual('connect_spare', self.step(190, attempt)['action'])

    def test_disabled_spare_does_not_disable_ap(self):
        first = self.step(100, spare_enabled=False)
        self.assertIsNone(self.step(130, first, spare_enabled=False)['action'])
        self.assertEqual('start', self.step(700, first, spare_enabled=False)['action'])

    def test_recovered_spare_client_prevents_ap(self):
        first = self.step(100)
        result = self.step(800, first, management=True, spare_connected=True)
        self.assertEqual('standby', result['state'])
        self.assertIsNone(result['action'])

    def test_a_healthy_client_is_never_replaced(self):
        first = self.step(100)
        self.assertEqual('unavailable', self.step(800, first, radio_busy=True)['state'])

    def test_global_radio_off_is_respected(self):
        first = self.step(100)
        self.assertIsNone(self.step(800, first, blocked=True)['action'])

    def test_unknown_nm_state_restarts_outage_observation(self):
        first = self.step(100)
        unknown = self.step(699, first, known=False)
        self.assertEqual('unavailable', unknown['state'])
        self.assertNotEqual('start', self.step(900, unknown)['action'])

    def test_unknown_read_does_not_stop_working_ap(self):
        result = self.step(1000, ap_active=True, known=False)
        self.assertIsNone(result['action'])
        self.assertEqual('active', result['state'])

    def test_connected_engineer_is_retained_after_recovery(self):
        first = self.step(1000, ap_active=True, management=True, clients=1)
        result = self.step(5000, first, ap_active=True, management=True, clients=1)
        self.assertEqual('recovery', result['state'])
        self.assertIsNone(result['action'])

    def test_disconnect_requires_recovery_then_sixty_seconds_without_clients(self):
        first = self.step(1000, ap_active=True, management=True, clients=1)
        stable = self.step(1060, first, ap_active=True, management=True, clients=1)
        zero = self.step(1080, stable, ap_active=True, management=True)
        self.assertIsNone(self.step(1139, zero, ap_active=True, management=True)['action'])
        self.assertEqual('stop', self.step(1140, zero, ap_active=True, management=True)['action'])

    def test_unknown_association_count_is_not_zero(self):
        first = self.step(1000, ap_active=True, management=True)
        stable = self.step(1060, first, ap_active=True, management=True)
        unknown = self.step(1120, stable, ap_active=True, management=True, clients=None)
        self.assertIsNone(unknown['action'])
        self.assertIsNone(self.step(1121, unknown, ap_active=True, management=True)['action'])

    def test_ap_stays_when_offline_even_without_clients(self):
        result = self.step(90000, ap_active=True)
        self.assertEqual('active', result['state'])
        self.assertIsNone(result['action'])

    def test_recovery_flap_resets_drain(self):
        first = self.step(1000, ap_active=True, management=True)
        stable = self.step(1060, first, ap_active=True, management=True)
        offline = self.step(1120, stable, ap_active=True)
        self.assertIsNone(self.step(1121, offline, ap_active=True, management=True)['action'])

    def test_manual_hold_allows_joining_before_recovery_shutdown(self):
        state = dict(self.step(1000), hold_until=1900)
        self.assertEqual('start', self.step(1001, state, management=True)['action'])
        self.assertIsNone(self.step(1800, state, ap_active=True, management=True)['action'])

    def test_manual_pause_stops_ap_and_expires_without_resetting_whole_search(self):
        state = dict(self.step(1000), pause_until=1900)
        self.assertEqual('stop', self.step(1001, state, ap_active=True)['action'])
        self.assertEqual('paused', self.step(1899, state)['state'])
        self.assertEqual('start', self.step(1900, state)['action'])

    def test_pending_station_operation_defers_actions_without_dropping_ap(self):
        first = self.step(1000)
        self.assertIsNone(self.step(2000, first, deferred=True)['action'])
        self.assertIsNone(self.step(2000, first, ap_active=True, deferred=True)['action'])

    def test_reboot_resets_old_monotonic_deadlines(self):
        state = dict(self.step(1000), hold_until=1900, pause_until=1900, boot_id='old-boot')
        result = self.step(10, state)
        self.assertEqual('waiting', result['state'])
        self.assertEqual(600, result['remaining'])

    def test_disabled_policy_stops_only_owned_ap(self):
        self.cfg['enabled'] = False
        self.assertIsNone(self.step(1000)['action'])
        self.assertEqual('stop', self.step(1000, ap_active=True)['action'])

    def test_default_route_and_internet_are_not_required_for_management(self):
        profiles = [{'uuid':'a', 'device':'wlan1', 'type':'wifi', 'active':True, 'addresses':['192.168.68.23/24']}]
        self.assertTrue(self.p.has_management(profiles, 'eth0', []))
        profiles[0].update(type='vlan', device='eth0.15')
        self.assertFalse(self.p.has_management(profiles, 'eth0', []))
        self.assertTrue(self.p.has_management(profiles, 'eth0', ['a']))

    def test_ap_and_stand_addresses_never_count_as_management(self):
        for profile in [
            {'uuid':'a', 'device':'eth0', 'type':'ethernet', 'active':True, 'addresses':['172.20.0.1/16']},
            {'uuid':'a', 'device':'wlan0', 'type':'wifi', 'mode':'ap', 'active':True, 'addresses':['192.168.240.1/24']},
            {'uuid':'a', 'device':'wlan1', 'type':'wifi', 'active':True, 'addresses':['169.254.5.3/16']},
        ]:
            self.assertFalse(self.p.has_management([profile], 'eth0', ['a']))

    def test_ipv6_ula_is_usable_but_link_local_is_not(self):
        profile = dict(uuid='a', device='wlan1', type='wifi', active=True, addresses=['fd12::2/64'])
        self.assertTrue(self.p.has_management([profile], 'eth0', []))
        profile['addresses'] = ['fe80::2/64']
        self.assertFalse(self.p.has_management([profile], 'eth0', []))

    def test_radio_identity_survives_interface_rename(self):
        radios = [dict(mac='02:00:00:00:00:01', device='wlp1', phy='phy0', bus='sdio', ap=True),
                  dict(mac='02:00:00:00:00:02', device='wlp2', phy='phy1', bus='pci', ap=True)]
        ap, client = self.p.select_radios(radios, self.cfg['ap_mac'], self.cfg['client_mac'])
        self.assertEqual('wlp1', ap['device'])
        self.assertEqual('wlp2', client['device'])
        radios[1]['phy'] = 'phy0'
        with self.assertRaises(ValueError):
            self.p.select_radios(radios, self.cfg['ap_mac'], self.cfg['client_mac'])

    def test_temporarily_missing_configured_client_adapter_still_allows_rescue(self):
        radio = dict(mac='02:00:00:00:00:01', device='wlan0', phy='phy0', bus='sdio', ap=True)
        ap, client = self.p.select_radios([radio], self.cfg['ap_mac'], self.cfg['client_mac'])
        self.assertEqual('wlan0', ap['device'])
        self.assertIsNone(client)


if __name__ == '__main__':
    unittest.main()
