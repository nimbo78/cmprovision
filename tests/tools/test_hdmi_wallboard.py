import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('wallboard', Path(__file__).resolve().parents[2] / 'tools/hdmi-wallboard.py')
w = importlib.util.module_from_spec(spec)
spec.loader.exec_module(w)


def device(serial='1000000000000001', state='run', phase='write'):
    return dict(serial=serial, name='wlanpi-demo', port='sw01 Gi0/1', phase=phase, state=state, percent=63,
                speed=25 * 1048576, eta=60, cycle=200, detail='Writing image', diagnostics=None, steps=['preinstall', 'write'])


def snapshot():
    return dict(version=1, generated_at=1000, host='wlanpi-da2', project='Test project', clock='12:00 UTC',
                modules=[device()], switches=[], counts=dict(done=0, running=1, failed=0, stale=0))


class SettingsTest(unittest.TestCase):
    def test_off_auto_on_and_both_connectors(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for name in ('card1-HDMI-A-1', 'card1-HDMI-A-2'):
                (root / name).mkdir()
                (root / name / 'status').write_text('disconnected\n')
            self.assertFalse(w.hdmi_connected(root))
            (root / 'card1-HDMI-A-2/status').write_text('connected\n')
            self.assertTrue(w.hdmi_connected(root))
        for mode, connected, expected in [('off', True, False), ('auto', False, False), ('auto', True, True), ('on', False, True)]:
            self.assertEqual(expected, w.wanted(dict(mode=mode), connected))

    def test_invalid_or_missing_settings_fail_closed_and_writes_are_atomic(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'settings.json'
            self.assertEqual('off', w.read_config(path)['mode'])
            path.write_text('{"mode":"on"}')
            self.assertEqual('off', w.read_config(path)['mode'])
            w.atomic_json(path, dict(w.DEFAULTS, mode='auto'))
            self.assertEqual('auto', w.read_config(path)['mode'])
            self.assertEqual(0o644, path.stat().st_mode & 0o777)
        for data in [dict(w.DEFAULTS, mode='on;reboot'), dict(w.DEFAULTS, rotation=True), dict(w.DEFAULTS, rotation=0), dict(w.DEFAULTS, path='/etc/passwd')]:
            with self.assertRaises(ValueError):
                w.valid_config(data)

    def test_watcher_starts_only_connected_auto_and_stops_after_unplug(self):
        states = iter([False, True, False])
        calls = []
        active = [False]

        def systemctl(*args):
            calls.append(args)
            if args[0] == 'start': active[0] = True
            if args[0] == 'stop': active[0] = False
            return active[0] if args[0] == 'is-active' else True

        def sleep(_):
            if len([c for c in calls if c == ('stop',)]) >= 1:
                w.STOP = True

        with tempfile.TemporaryDirectory() as directory, patch.object(w, 'RUNTIME', Path(directory)), \
                patch.object(w, 'read_config', return_value=dict(w.DEFAULTS, mode='auto')), \
                patch.object(w, 'hdmi_connected', side_effect=lambda: next(states, False)), \
                patch.object(w, 'systemctl', side_effect=systemctl), patch.object(w.time, 'sleep', side_effect=sleep):
            w.STOP = False
            w.watch()
            w.STOP = False
        self.assertEqual(1, calls.count(('start',)))
        self.assertGreaterEqual(calls.count(('stop',)), 1)


class RenderingTest(unittest.TestCase):
    def test_escape_sequences_and_unicode_cannot_control_the_terminal(self):
        self.assertNotIn('\x1b', w.safe('\x1b[2J\u009b2J\nmodule\t\u202e'))
        self.assertTrue(all(32 <= ord(c) < 127 for c in w.safe('עברית\n\x1b[31m')))

    def test_all_views_and_device_states_fit_full_hd_and_fallback_terminals(self):
        for cols, rows in [(137, 38), (112, 37), (120, 33), (80, 30), (60, 24), (40, 15)]:
            for view in ('auto', 'desk', 'flow', 'engineer'):
                for state, phase in [('run', 'write'), ('fail', 'failed'), ('ok', 'done'), ('stale', 'write')]:
                    data = snapshot()
                    data['modules'] = [device(state=state, phase=phase)]
                    canvas = w.frame(data, cols, rows, view=view, now=1002)
                    self.assertEqual(rows, len(canvas.cells))
                    self.assertTrue(all(len(row) == cols for row in canvas.cells))
                    self.assertIn('cmprovision' if cols < 100 else 'PROJECT', canvas.plain())

    def test_disconnected_reader_never_leaves_a_healthy_looking_screen(self):
        c = w.frame(snapshot(), 112, 37, now=1011)
        self.assertIn('DATA STALE', c.plain())
        data = snapshot()
        data['modules'][0].update(state='fail', phase='failed', detail='MMC did not initialize')
        c = w.frame(data, 112, 37, view='desk', now=1001)
        self.assertIn('! sw01 Gi0/1: MMC did not initialize', c.plain())

    def test_large_switch_pagination_never_loses_or_reorders_a_port(self):
        ports = [dict(number=i, name='Gi1/0/' + str(i), serials=[], state='free') for i in range(1, 49)]
        switch = dict(blocks=[dict(label='RJ45', rows=2, ports=ports)], answering=True, name='sw48', model='C9200L')
        pages = w.port_pages(switch, 60, 13)
        seen = [p['number'] for page in pages for _, _, bank in page for p in bank['ports']]
        self.assertEqual(list(range(1, 49)), seen)
        self.assertGreater(len(pages), 1)
        # Adjacent entries occupy a column: 1 above 2, then 3 above 4.
        self.assertEqual([1, 2, 3, 4], [p['number'] for p in pages[0][0][2]['ports'][:4]])

    def test_cisco_uplinks_align_with_bottom_row_and_double_digit_labels_fit(self):
        ports = [dict(number=i, name='Gi0/' + str(i), serials=[], state='free') for i in range(1, 13)]
        switch = dict(name='Cisco', model='2960CX', answering=True, blocks=[
            dict(label='RJ45', rows=2, ports=ports[:4]),
            dict(label='RJ45', rows=2, ports=ports[4:8]),
            dict(label='RJ45 uplinks', rows=1, ports=ports[8:10]),
            dict(label='SFP', rows=1, ports=ports[10:]),
        ])
        page = w.port_pages(switch, 90, 12)[0]
        self.assertEqual([0, 0, 3, 3], [y for _, y, _ in page])
        c = w.Canvas(100, 20)
        w.ports(c, 0, 0, 100, 20, [switch], [], 0)
        lines = c.plain().splitlines()
        self.assertTrue(any('|2 FREE ' in line and '|10 FREE|' in line for line in lines))
        self.assertTrue(any('|1 FREE ' in line and '|10 FREE|' not in line for line in lines))


if __name__ == '__main__':
    unittest.main()
