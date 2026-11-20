"""The physical link and PoE readings shared by both HDMI renderers."""
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools'))
import hdmi_port_info as ports


class PortReadingsTest(unittest.TestCase):
    def test_link_uses_only_operational_state_and_reports_measured_speed(self):
        for row, expected in [
            ({'link_up': True, 'speed': 2500}, ('2.5G', 'good')),
            ({'link_up': True, 'speed': 100}, ('100M', 'good')),
            ({'link_up': True, 'speed': 0}, ('Up', 'good')),
            ({'link_up': False, 'speed': 10, 'poe_state': 'delivering'}, ('Down', 'muted')),
            ({'state': 'module', 'speed': 1000}, ('?', 'muted')),
            ({'link_up': True, 'speed': float('nan')}, ('Up', 'good')),
            ({'link_up': True, 'speed': True}, ('Up', 'good')),
        ]:
            with self.subTest(row=row):
                self.assertEqual(expected, ports.link_reading(row))

    def test_poe_state_takes_precedence_and_zero_alone_does_not_identify_idle(self):
        for state, watts, expected in [
            ('delivering', 6.5, ('6.5 W', 'good')), ('delivering', 0, ('On', 'good')),
            ('delivering', None, ('On', 'good')), ('searching', 0, ('Idle', 'muted')),
            ('disabled', 0, ('Off', 'muted')), ('fault', 2, ('Fault', 'bad')),
            ('test', 0, ('Test', 'warn')), (None, 5, ('5 W', 'good')),
            (None, 0, ('?', 'muted')), (None, None, ('?', 'muted')),
            (None, float('inf'), ('?', 'muted')), (None, True, ('?', 'muted')),
        ]:
            with self.subTest(state=state, watts=watts):
                row = {'link_up': False, 'poe_state': state, 'watts': watts}
                self.assertEqual(expected, ports.power_reading(row))

    def test_explicit_endpoint_and_empty_endpoint_are_authoritative(self):
        observed = {'name': 'other-device'}
        self.assertEqual(ports.port_endpoint({'endpoint': 'sensor-01 +1'}, observed=observed), 'sensor-01 +1')
        self.assertEqual(ports.port_endpoint({'endpoint': None}, observed=observed), '')
        self.assertEqual(ports.port_endpoint({'endpoint': 'sensor\n01'}), 'sensor 01')

    def test_older_snapshots_use_the_directly_assigned_member_and_station_evidence(self):
        row = {'state': 'module'}
        members = [{'hostname': 'sensor-a'}, {'name': 'sensor-b'}]
        self.assertEqual(ports.port_endpoint(row, modules=members), 'sensor-a +1')
        self.assertEqual(ports.port_endpoint({'state': 'recognized'}, observed={'name': 'marked-sensor'}), 'marked-sensor')
        self.assertEqual(ports.port_endpoint({'state': 'unrecognized'}, observed={'mac': '02:00:00:00:00:01'}), '02:00:00:00:00:01')
        self.assertEqual(ports.port_endpoint({'state': 'uplink', 'server': True}, 'current'), 'current')
        self.assertEqual(ports.port_endpoint({'state': 'uplink', 'station': {'name': 'peer'}}), 'peer')
        self.assertEqual(ports.port_endpoint({'state': 'free', 'station': {'name': 'stale'}, 'poe_state': 'delivering'}), '')

    def test_free_port_in_an_older_snapshot_does_not_keep_a_stale_name(self):
        port = {'state': 'free', 'link_up': False, 'poe_state': 'delivering'}
        self.assertEqual(ports.port_endpoint(port, modules=[{'name': 'previous-sensor'}]), '')


if __name__ == '__main__':
    unittest.main()
