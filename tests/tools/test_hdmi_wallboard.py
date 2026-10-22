import importlib.util
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
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
    def test_old_palettes_preserve_unattended_auto_on_upgrade(self):
        for old, new in [('phosphor', 'warm'), ('mono', 'neutral')]:
            data = w.valid_config(dict(w.DEFAULTS, mode='auto', palette=old))
            self.assertEqual(new, data['palette'])
            self.assertTrue(w.wanted(data, True))

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
                patch.object(w, 'configure_console', return_value={'resolution': '1920x1080'}), \
                patch.object(w, 'hdmi_connected', side_effect=lambda: next(states, False)), \
                patch.object(w, 'systemctl', side_effect=systemctl), patch.object(w.time, 'sleep', side_effect=sleep):
            w.STOP = False
            w.watch()
            w.STOP = False
        self.assertEqual(1, calls.count(('start',)))
        self.assertGreaterEqual(calls.count(('stop',)), 1)


class ScalingTest(unittest.TestCase):
    def test_full_hd_ultrawide_and_4k_preserve_readable_rows_and_use_screen_width(self):
        for pixels, cell, grid in [((1920, 1080), (14, 28), (137, 38)),
                                  ((3440, 1440), (20, 36), (172, 40)),
                                  ((3840, 2160), (28, 56), (137, 38))]:
            font = w.font_candidates(pixels)[0]
            self.assertEqual(cell, (font['width'], font['height']))
            self.assertEqual(grid, (pixels[0] // font['width'], pixels[1] // font['height']))
            width, height, ox, oy = w.viewport(*grid)
            self.assertGreaterEqual(width / grid[0], .95)
            self.assertGreaterEqual(height, 36)
            data = snapshot()
            data['modules'][0].update(state='fail', phase='failed', advice='Reseat the module')
            rendered = w.frame(data, width, height, view='engineer', now=1001).plain()
            self.assertIn('NEXT: Reseat the module', rendered)
            self.assertIn('V view', rendered)
            self.assertIn('| cm |', rendered)
            self.assertIn('cmprovision', rendered)

    def test_tall_font_rejection_falls_back_without_retries_or_changing_active_console(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for name in ['Lat15-Terminus28x14.psf.gz', 'Lat15-Terminus32x16.psf.gz']:
                (root / name).touch()
            with patch.object(w, 'RUNTIME', root), patch.object(w, 'FONTS', root), \
                    patch.object(w.subprocess, 'run', side_effect=lambda args, **kw: SimpleNamespace(returncode=int('-d' in args))) as run:
                layout = w.configure_console((3840, 2160))
                self.assertEqual([16, 32], layout['cell'])
                calls = run.call_count
                self.assertGreater(calls, 1)
                self.assertEqual(layout, w.configure_console((3840, 2160)))
                self.assertEqual(calls, run.call_count, 'a failed tall font must not be retried every poll')
                w.configure_console((1920, 1080))
                self.assertGreater(run.call_count, calls, 'a resolution change must select a new font')
                self.assertTrue(all(c.args[0][:3] == ['/usr/bin/setfont', '-C', '/dev/tty8'] for c in run.call_args_list))

    def test_framebuffer_uses_visible_geometry_instead_of_virtual_buffer(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'fb'
            path.touch()
            def ioctl(fd, request, info):
                w.struct.pack_into('=4I', info, 0, 1920, 1080, 1920, 2160)
            with patch.object(w.fcntl, 'ioctl', side_effect=ioctl):
                self.assertEqual((1920, 1080), w.framebuffer_size(path))
            with patch.object(w.fcntl, 'ioctl', side_effect=OSError):
                self.assertIsNone(w.framebuffer_size(path))

    def test_ultrawide_shows_three_cards_and_full_hd_shows_two(self):
        data = snapshot()
        data['modules'] = [dict(device(str(i)), name='DEVICE%d' % i) for i in range(1, 7)]
        for width, columns in [(133, 2), (168, 3)]:
            lines = w.frame(data, width, 38, view='flow', now=1001).plain().splitlines()
            first = next(line for line in lines if 'DEVICE1' in line)
            self.assertEqual(columns, first.count('DEVICE'))


class RenderingTest(unittest.TestCase):
    def test_web_css_is_the_palette_source_and_alpha_text_is_composited(self):
        path = Path(__file__).resolve().parents[2] / 'resources/css/app.css'
        expected = {'warm': ((255, 138, 61), (0, 0, 0)), 'warm-light': ((226, 104, 28), (255, 255, 255)),
                    'neutral': ((129, 140, 248), (11, 17, 32)), 'neutral-light': ((99, 102, 241), (243, 244, 246))}
        for name, (accent, bg) in expected.items():
            palette = w.web_palette(name, path)
            self.assertEqual(accent, palette['accent'])
            self.assertEqual(bg, palette['bg'])
        self.assertEqual((230, 230, 230), w.web_palette('warm', path)['fg'])
        self.assertEqual((138, 138, 138), w.web_palette('warm', path)['dim'])
        self.assertEqual((51, 51, 51), w.web_palette('warm-light', path)['fg'])
        with tempfile.TemporaryDirectory() as directory:
            changed = Path(directory) / 'app.css'
            changed.write_text(path.read_text().replace('--accent: #ff8a3d;', '--accent: #e37021;'))
            self.assertEqual((227, 112, 33), w.web_palette('warm', changed)['accent'], 'future web changes must reach HDMI')

    def test_linux_palette_keeps_exact_rgb_without_bold_selecting_other_slots(self):
        rgb = w.web_palette('warm', Path(__file__).resolve().parents[2] / 'resources/css/app.css')
        with patch.object(w.curses, 'has_colors', return_value=True), patch.object(w.curses, 'start_color'), \
                patch.object(w.curses, 'can_change_color', return_value=True), patch.object(w, 'web_palette', return_value=rgb), \
                patch.object(w.curses, 'init_color') as init, patch.object(w.curses, 'init_pair') as pairs, \
                patch.object(w.curses, 'color_pair', side_effect=lambda number: number << 8):
            inks = w.colors('warm')
        slots = {call.args[0]: tuple(c * 255 // 1000 for c in call.args[1:]) for call in init.call_args_list}
        self.assertEqual(8, len(slots))
        self.assertEqual(rgb['accent'], slots[3])
        self.assertEqual(rgb['bg'], slots[0])
        for index, name in enumerate(('fg', 'dim', 'accent', 'good', 'run', 'fail', 'warn', 'board'), 1):
            self.assertEqual(index << 8, inks[name], 'bold must not bypass our RGB slot')
            self.assertEqual(rgb[name], slots[pairs.call_args_list[index - 1].args[1]])

    def test_non_programmable_terminal_keeps_readable_fallback(self):
        with patch.object(w.curses, 'has_colors', return_value=True), patch.object(w.curses, 'start_color'), \
                patch.object(w.curses, 'can_change_color', return_value=False), patch.object(w.curses, 'init_color') as init, \
                patch.object(w.curses, 'init_pair'), patch.object(w.curses, 'color_pair', return_value=1):
            self.assertIn('accent', w.colors('neutral-light'))
            init.assert_not_called()

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
        data = snapshot()
        data['switches'] = [switch]
        data['modules'] = []
        for cols, rows in [(133, 36), (168, 38)]:
            rendered = w.frame(data, cols, rows, view='desk', now=1001).plain()
            for number in (9, 10, 11, 12):
                self.assertIn('|%s|' % (str(number) + ' FREE').ljust(7), rendered)
            self.assertIn('DEVICE CARD', rendered)


if __name__ == '__main__':
    unittest.main()
