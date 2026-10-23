import importlib.util
import json
import os
from pathlib import Path
import subprocess
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


class ReaderTest(unittest.TestCase):
    def test_a_replaced_file_is_read_once_and_unusable_ones_are_ignored(self):
        with tempfile.TemporaryDirectory() as directory:
            path, spare = Path(directory) / 'snapshot.json', Path(directory) / 'next'
            reader = w.Reader(path)
            self.assertIsNone(reader.poll(), 'nothing there yet')
            path.write_text(json.dumps(snapshot()))
            self.assertEqual('wlanpi-da2', reader.poll()['host'])
            self.assertIsNone(reader.poll(), 'the same file is not read twice')
            spare.write_text(json.dumps(dict(snapshot(), host='next')))
            os.replace(spare, path)
            self.assertEqual('next', reader.poll()['host'])
            for bad in ('not json', json.dumps(dict(snapshot(), version=2)), json.dumps(dict(snapshot(), modules='x')), json.dumps([])):
                spare.write_text(bad)
                os.replace(spare, path)
                self.assertIsNone(reader.poll(), bad)
            spare.write_bytes(b' ' * (w.MAX_SNAPSHOT + 10))
            os.replace(spare, path)
            self.assertIsNone(reader.poll(), 'a file over the limit is never read')

    def test_the_renderer_starts_no_process_and_has_no_database(self):
        source = Path(w.__file__).read_text()
        display = source[source.index('class Reader:'):source.index('def web_palette(')]
        self.assertNotIn('subprocess', display)
        self.assertNotIn('artisan', display)


class WatcherTest(unittest.TestCase):
    def drive(self, readings, start_ok=(), ticks=12, mode='auto'):
        """Run the watcher for some ticks of a fake clock; returns (systemctl calls, console-switch flag seen at each start)."""
        calls, seen, active, clock = [], [], [False], [0.0]
        results = iter(start_ok)
        it = iter(readings)

        with tempfile.TemporaryDirectory() as directory:
            run = Path(directory)
            flag = run / 'switch-requested'

            def systemctl(*args):
                calls.append(args)
                if args[0] == 'start':
                    seen.append(flag.exists())
                    flag.unlink(missing_ok=True)   # what claim() does
                    active[0] = next(results, True)
                    return active[0]
                if args[0] == 'stop':
                    active[0] = False
                    return True
                return active[0]

            def sleep(seconds):
                clock[0] += seconds
                if clock[0] >= ticks * 2:
                    w.STOP = True

            with patch.object(w, 'RUNTIME', run), patch.object(w, 'read_config', return_value=dict(w.DEFAULTS, mode=mode)), \
                    patch.object(w, 'configure_console', return_value={'resolution': '1920x1080'}), \
                    patch.object(w, 'hdmi_connected', side_effect=lambda: next(it, readings[-1])), \
                    patch.object(w, 'systemctl', side_effect=systemctl), patch.object(w.time, 'sleep', side_effect=sleep), \
                    patch.object(w.time, 'monotonic', side_effect=lambda: clock[0]):
                w.STOP = False
                w.watch()
                w.STOP = False
        return calls, seen

    def test_a_flapping_connector_starts_nothing_and_a_steady_one_starts_once(self):
        calls, _ = self.drive([False, True, False, True, False, True, False])
        self.assertEqual(0, calls.count(('start',)), 'one reading is not a connection')
        calls, seen = self.drive([False, True, True], ticks=8)
        self.assertEqual(1, calls.count(('start',)))
        self.assertEqual([True], seen, 'a new connection asks for the console')

    def test_a_retry_after_a_failed_start_does_not_ask_for_the_console_again(self):
        calls, seen = self.drive([True], start_ok=(False, True), ticks=12)
        self.assertEqual(2, calls.count(('start',)))
        self.assertEqual([True, False], seen, 'only the first start of a connection takes the console')

    def test_a_unit_that_keeps_failing_is_not_hammered(self):
        calls, _ = self.drive([True], start_ok=(False,) * 20, ticks=30)
        self.assertEqual(3, calls.count(('start',)), 'ten seconds apart three times, then a minute')


class ConsoleTest(unittest.TestCase):
    def run_claim(self, directory, switch, blank='600\n'):
        run = Path(directory)
        (run / 'tty8').write_text('')
        (run / 'blank').write_text(blank)
        if switch:
            (run / 'switch-requested').write_text('1')
        with patch.object(w, 'RUNTIME', run), patch.object(w, 'TTY', str(run / 'tty8')), patch.object(w, 'CONSOLEBLANK', run / 'blank'), \
                patch.object(w, 'active_vt', return_value='tty1'), patch.object(w, 'configure_console'), \
                patch.object(w, 'framebuffer_size', return_value=(1920, 1080)), patch.object(w.subprocess, 'run') as run_command:
            w.claim()
        return run_command

    def test_the_console_is_taken_only_when_the_watcher_asked_for_it(self):
        with tempfile.TemporaryDirectory() as directory:
            command = self.run_claim(directory, switch=True)
            command.assert_called_once()
            self.assertEqual(['/usr/bin/chvt', '8'], command.call_args.args[0])
            self.assertFalse((Path(directory) / 'switch-requested').exists(), 'the request is used up')
        with tempfile.TemporaryDirectory() as directory:
            command = self.run_claim(directory, switch=False)
            command.assert_not_called()   # a restart after a crash or an upgrade leaves the administrator on tty1

    def test_the_blanking_interval_the_administrator_had_comes_back_on_release(self):
        with tempfile.TemporaryDirectory() as directory:
            run = Path(directory)
            self.run_claim(directory, switch=False, blank='600\n')
            self.assertEqual('600', (run / 'consoleblank').read_text())
            (run / 'blank').write_text('0\n')   # what the kernel reports once claim has switched the blanking off
            self.run_claim(directory, switch=False, blank='0\n')
            self.assertEqual('600', (run / 'consoleblank').read_text(), 'a restart does not overwrite the remembered value')
            (run / 'tty8').write_text('')
            with patch.object(w, 'RUNTIME', run), patch.object(w, 'TTY', str(run / 'tty8')), patch.object(w, 'CONSOLEBLANK', run / 'blank'), \
                    patch.object(w, 'active_vt', return_value='tty1'):
                w.release()
            self.assertIn('\x1b[9;10]', (run / 'tty8').read_text(), 'ten minutes, as it was')
            self.assertFalse((run / 'consoleblank').exists())


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


class LayoutTest(unittest.TestCase):
    """The header lines up with the logo, the table uses the width, and a text that does not fit scrolls."""

    LONG_PORT = 't-iof-sw01-provision Gi0/1'
    LONG_NAME = 'wlanpi-1-very-long-device-name-sensor0'
    LONG_TEXT = LONG_PORT + ' / ' + LONG_NAME
    LONG_DETAIL = 'The module stopped reporting for a long time, check power and the cable on the port'

    def long_snapshot(self):
        data = snapshot()
        data['modules'] = [dict(device(), port=self.LONG_PORT, name=self.LONG_NAME, state='fail', phase='failed', percent=None, speed=None, detail=self.LONG_DETAIL),
                           dict(device('1000000000000002'), port='sw01 Gi0/2', name='wlanpi-demo')]
        return data

    def row_of(self, canvas, text):
        return next(line for line in canvas.plain().splitlines() if text in line)

    def test_the_name_is_on_the_middle_row_of_the_logo_with_a_two_column_gap(self):
        lines = w.frame(snapshot(), 112, 37, now=1001).plain().splitlines()
        self.assertEqual('.----.', lines[0][:6])
        self.assertTrue(lines[1].startswith('| cm |  cmprovision  PROVISIONING STAND'), lines[1])
        self.assertEqual("'----'", lines[2][:6])
        self.assertEqual('', lines[0][6:].strip()[:0], 'nothing else of the name on the logo rows above and below')
        self.assertNotIn('cmprovision', lines[0] + lines[2])
        self.assertNotIn('PROVISIONING', lines[0] + lines[2])

    def test_a_text_that_fits_stays_still_and_a_longer_one_rests_runs_and_starts_over(self):
        c = w.Canvas(40, 1)
        c.marquee(0, 0, 'short', 10)
        self.assertEqual('short', c.plain())
        text = 'abcdefghijklmnopqrstuvwxyz'      # 26 characters in a column of 10: nine show, the tenth says there is more
        seen = []
        for step in range(2 * w.MARQUEE_REST + 17 + 3):
            c = w.Canvas(40, 1, step=step)
            c.marquee(0, 0, text, 10)
            seen.append(c.plain())
        self.assertEqual('abcdefghi>', seen[0])
        self.assertEqual(['abcdefghi>'] * (w.MARQUEE_REST + 1), seen[:w.MARQUEE_REST + 1], 'it rests at the start')
        self.assertEqual('bcdefghij>', seen[w.MARQUEE_REST + 1], 'then runs one column a step')
        end = 'rstuvwxyz'
        self.assertIn(end, seen, 'the last characters are shown in full')
        self.assertNotIn('>', seen[seen.index(end)], 'with nothing more to come at the end')
        self.assertEqual(seen[0], seen[2 * w.MARQUEE_REST + 17], 'and it starts over')

    def test_a_canvas_knows_whether_anything_scrolls_so_the_screen_is_redrawn_fast_only_then(self):
        c = w.Canvas(40, 2)
        c.marquee(0, 0, 'fits', 10)
        self.assertFalse(c.scrolls)
        c.marquee(0, 1, 'x' * 30, 10)
        self.assertTrue(c.scrolls)

    def test_the_port_and_device_column_grows_to_the_longest_name_when_the_screen_is_wide(self):
        c = w.frame(self.long_snapshot(), 172, 40, view='desk', now=1001)
        self.assertIn(self.LONG_TEXT + ' ', self.row_of(c, 'Gi0/1 / '), 'a 172-column screen shows the whole name, nothing scrolls')
        self.assertFalse(c.scrolls)

    def test_the_table_has_a_rule_under_its_header_across_the_whole_width(self):
        for cols in (172, 112):
            lines = w.frame(self.long_snapshot(), cols, 40, view='desk', now=1001).plain().splitlines()
            head = next(i for i, line in enumerate(lines) if line.startswith('PORT / DEVICE'))
            self.assertEqual('-' * cols, lines[head + 1])
            self.assertTrue(lines[head].split()[-1] == 'RESULT')
            self.assertTrue(lines[head + 2].strip(), 'the first row follows the rule')

    def test_on_a_narrower_screen_the_long_name_scrolls_through_the_column_until_all_of_it_was_seen(self):
        seen = set()
        shown = []
        for step in range(0, 80):
            c = w.frame(self.long_snapshot(), 112, 40, view='desk', now=1001, step=step)
            line = self.row_of(c, 'Gi0/1 / ')
            shown.append(line)
            seen.add(line.split('  ')[0].rstrip('> '))
        text = ' '.join(shown)
        self.assertIn(self.LONG_NAME[-7:] + ' ', text + ' ', 'the end of the device name comes into view')
        self.assertTrue(c.scrolls)
        self.assertIn('>', shown[0])
        # the other module's short text does not move
        self.assertTrue(all('sw01 Gi0/2 / wlanpi-demo' in self.row_of(w.frame(self.long_snapshot(), 112, 40, view='desk', now=1001, step=s), 'Gi0/2 / ') for s in (0, 5, 30)))

    def test_a_long_result_uses_the_rest_of_the_row_and_scrolls_only_when_it_must(self):
        wide = w.frame(self.long_snapshot(), 172, 40, view='desk', now=1001)
        self.assertIn(self.LONG_DETAIL, self.row_of(wide, 'Gi0/1 / '))
        narrow = w.frame(self.long_snapshot(), 112, 40, view='desk', now=1001)
        self.assertNotIn(self.LONG_DETAIL, narrow.plain())

    def test_the_device_card_and_the_alert_line_scroll_too(self):
        data = self.long_snapshot()
        data['modules'][0]['name'] = 'sensor-with-an-extremely-long-hostname-for-the-scroll-test'
        card = [w.frame(data, 112, 40, view='desk', now=1001, step=step).plain().splitlines()[8].split('|')[-2] for step in range(0, 120)]
        self.assertTrue(card[0].strip().startswith('sensor-with-an'))
        self.assertTrue(any('scroll-test' in line for line in card), 'the end of the name of the device on the card comes into view')
        data = self.long_snapshot()
        tail = []
        for step in range(0, 120):
            c = w.frame(data, 100, 40, view='desk', now=1001, step=step)
            tail.append(c.plain().splitlines()[36])   # the alert line, four rows above the bottom
        self.assertTrue(any('the port' in line for line in tail), 'the end of the alert shows up as it runs')


class IdentityTest(unittest.TestCase):
    """A module is its host name or its MAC; the serial is never the name and never shown twice."""

    MAC = 'e4:5f:01:62:9f:d3'

    def card(self, **extra):
        data = snapshot()
        data['modules'] = [dict(device(), mac=self.MAC, **extra)]
        c = w.frame(data, 112, 40, view='desk', now=1001)
        lines = c.plain().splitlines()
        return [line.split('|')[-2].strip() for line in lines[8:12]]

    def test_the_card_of_a_module_with_a_host_name_shows_the_host_name_the_mac_the_port_and_the_serial_last(self):
        self.assertEqual(['wlanpi-sensor1', self.MAC, 'sw01 Gi0/1', 'S/N 1000000000000001'],
                         self.card(name='wlanpi-sensor1', hostname='wlanpi-sensor1'))

    def test_the_card_of_a_module_without_one_is_titled_by_its_mac_and_the_serial_is_not_a_second_title(self):
        card = self.card(name=self.MAC, hostname=None)
        self.assertEqual([self.MAC, 'S/N 1000000000000001', 'sw01 Gi0/1'], card[:3])
        self.assertEqual(1, card.count(self.MAC))

    def test_a_snapshot_of_the_older_shape_still_draws(self):
        # no mac and no hostname keys (the data unit of an earlier version): the serial is under the name as before
        self.assertEqual(['wlanpi-demo', '1000000000000001', 'sw01 Gi0/1'],
                         [line.split('|')[-2].strip() for line in w.frame(snapshot(), 112, 40, view='desk', now=1001).plain().splitlines()[8:11]])

    def test_the_other_views_show_the_same_second_line(self):
        data = snapshot()
        data['modules'] = [dict(device(), mac=self.MAC, hostname=None, name=self.MAC)]
        for view in ('flow', 'engineer'):
            rendered = w.frame(data, 137, 38, view=view, now=1001).plain()
            self.assertIn('S/N 1000000000000001', rendered, view)
            self.assertNotIn('\n1000000000000001', rendered)


class ProgressBarTest(unittest.TestCase):
    def test_the_bar_and_its_percent_fit_the_device_card_at_every_width(self):
        for cols in (100, 112, 137, 172):   # below 100 columns there is no device card
            rendered = w.frame(snapshot(), cols, 38, view='desk', now=1001).plain()
            line = next(line for line in rendered.splitlines() if '[#' in line)
            self.assertIn('] 63%', line, cols)
            self.assertNotIn('~', line, cols)


if __name__ == '__main__':
    unittest.main()
