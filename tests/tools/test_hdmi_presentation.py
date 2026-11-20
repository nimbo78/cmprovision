"""HDMI presentation, terminal ownership, and sensitive-frame regressions."""
import importlib.util
from pathlib import Path
import signal
import sys
import unittest
from unittest.mock import Mock, patch

TOOLS = Path(__file__).resolve().parents[2] / 'tools'
sys.path.insert(0, str(TOOLS))
spec = importlib.util.spec_from_file_location('wallboard', TOOLS / 'hdmi-wallboard.py')
wallboard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wallboard)
import hdmi_presentation as presentation


class AccessAndNetworkTest(unittest.TestCase):
    def test_text_fallback_identifies_the_adjacent_switch_and_manual_selection(self):
        switches = [dict(name=name, blocks=[], answering=True) for name in ('bench-east', 'bench-west')]
        data = {'modules': [], 'generated_at': 1000, 'switches': switches}
        first = wallboard.frame(data, 160, 40, now=1000).plain()
        self.assertIn('Next: bench-west', first)
        second = wallboard.frame(data, 160, 40, now=1000, switch_offset=1).plain()
        self.assertIn('SWITCH 2/2', second)
        self.assertIn('Next: bench-east', second)

    def test_both_renderers_receive_only_approved_access_cards(self):
        ap = dict(ssid='Rescue', active=True)
        token = dict(ssid='Rescue', password='demo-placeholder', expires_at=160, created_at=100)
        data = dict(generated_at=1000, rescue_wifi=ap, station={'uplink': {'address': '192.0.2.8/24'}})
        setup = dict(code='ABCDEFGHIJKLMNOP', host='test-station.local')
        with patch.object(wallboard, 'rescue_access', return_value=token), patch.object(wallboard, 'root_json', return_value=setup):
            self.assertEqual(wallboard.display_cards(data, 1005), (token, None))
            data['rescue_wifi'] = dict(ap, ssid='Other')
            access, details = wallboard.display_cards(data, 1005)
            self.assertIsNone(access)
            self.assertEqual(details, dict(code=setup['code'], host='192.0.2.8', url='http://192.0.2.8/setup'))
            data['rescue_wifi'] = ap
            self.assertIsNone(wallboard.display_cards(data, 1021)[0])
            setup['code'] = 'not a code'
            self.assertEqual(wallboard.display_cards(data, 1021), (None, None))

    def test_shared_network_summary_excludes_rescue_interface_and_credentials(self):
        data = {'station': {'uplink': {'state': 'wired', 'device': 'eth1', 'speed': 1000, 'password': 'private'},
                            'links': [{'name': 'wlan1', 'up': True, 'role': 'backup'}]},
                'rescue_wifi': {'active': True, 'device': 'wlan1'}}
        segments, connected = wallboard.network_segments(data)
        self.assertTrue(connected)
        self.assertEqual(segments, ['UPLINK eth1 / UP 1 Gb/s / no IP'])
        self.assertEqual(wallboard.network_segments({}), ([], False))


class PresentationTest(unittest.TestCase):
    def setUp(self):
        self.console = Mock()
        self.console.ready.return_value = True
        self.output = Mock(size=(1600, 900))
        self.open = Mock(return_value=self.output)
        self.draw = Mock(return_value=object())
        self.show = presentation.Presentation(self.console, self.open, self.draw)
        self.data = {'generated_at': 1000, 'modules': []}
        self.args = dict(selected=0, tick=0, view='desk', paused=False, palette='warm', switch_offset=0,
                         access=None, setup=None)

    def present(self, at=111.25, wall=1001.5, **changes):
        return self.show.present(wallboard, self.data, at, wall, **dict(self.args, **changes))

    def test_renders_once_per_display_second_and_immediately_after_input(self):
        self.assertTrue(self.present())
        self.assertTrue(self.present(at=111.3))
        self.assertEqual(self.draw.call_count, 1)
        self.assertTrue(self.present(at=111.4, switch_offset=1))
        self.assertEqual(self.draw.call_args.kwargs['switch_offset'], 1)
        self.assertTrue(self.present(at=112.5, wall=1002.5, switch_offset=1))
        self.assertEqual(self.draw.call_count, 3)
        self.assertEqual(self.output.show.call_count, 3)

    def test_inactive_vt_never_opens_and_release_discards_old_frame(self):
        self.console.ready.return_value = False
        self.assertFalse(self.present())
        self.open.assert_not_called()
        self.console.ready.return_value = True
        self.assertTrue(self.present())
        self.console.ready.return_value = False
        self.assertFalse(self.present())
        self.output.close.assert_called_once()
        self.console.ready.return_value = True
        self.assertTrue(self.present(at=111.3))
        self.assertEqual(self.draw.call_count, 2)

    def test_visible_hostnames_animate_between_full_frames_and_reset_after_leaving_tty8(self):
        from test_hdmi_graphics import bank, port, switch
        from hdmi_graphics import render_frame
        self.draw.side_effect = render_frame
        name = "wlanpi-warehouse-east-floor03-rack12-radio-sensor-a17"
        self.data["switches"] = [switch("assembly", bank("Copper", 2, [port(1, endpoint=name)]))]
        self.assertTrue(self.present(at=10, wall=1001))
        first = self.output.show.call_args.args[0].copy()
        self.assertTrue(self.present(at=11, wall=1001))
        self.assertEqual(self.output.show.call_count, 1)
        self.assertTrue(self.present(at=12, wall=1001))
        self.assertEqual(self.draw.call_count, 1)
        self.assertEqual(self.output.show.call_count, 2)
        self.assertTrue(self.output.show.call_args.args[0].tobytes() != first.tobytes())
        self.console.ready.return_value = False
        self.assertFalse(self.present(at=12.1, wall=1001))
        self.console.ready.return_value = True
        self.assertTrue(self.present(at=13, wall=1001))
        self.assertTrue(self.output.show.call_args.args[0].tobytes() == first.tobytes())

    def test_revoked_output_falls_back_and_retries_without_spinning(self):
        self.output.show.side_effect = OSError('display unavailable')
        self.assertFalse(self.present())
        self.output.close.assert_called_once()
        self.assertFalse(self.present(at=112))
        self.open.assert_called_once()
        self.output.show.side_effect = None
        self.assertTrue(self.present(at=142))
        self.assertEqual(self.open.call_count, 2)

    def test_reveal_uses_monotonic_deadline_and_disappears_on_the_same_wall_second(self):
        access = {'created_at': 100, 'expires_at': 160}
        self.assertTrue(self.present(at=158.25, access=access))
        self.assertEqual(self.draw.call_args.kwargs['remaining'], 2)
        self.assertTrue(self.present(at=159.25, access=access))
        self.assertEqual(self.draw.call_args.kwargs['remaining'], 1)
        self.assertTrue(self.present(at=160.0))
        self.assertIsNone(self.draw.call_args.kwargs['access'])
        self.assertEqual(self.draw.call_count, 3)

    def test_release_requested_during_paint_never_presents_frame_on_other_vt(self):
        self.console.ready.side_effect = [True, False]
        self.assertFalse(self.present())
        self.output.show.assert_not_called()
        self.output.close.assert_called_once()


class RendererLoopTest(unittest.TestCase):
    def test_normal_entry_preserves_card_keys_and_switch_navigation(self):
        screen = Mock()
        screen.getmaxyx.return_value = (38, 137)
        unit = Mock()
        unit.present.return_value = True
        unit.motion.delay.return_value = 0.25
        reader = Mock()
        data = {'version': 1, 'generated_at': 1000, 'counts': {}, 'switches': [],
                'modules': [{'serial': 'a', 'port': 'One 1'}, {'serial': 'b', 'port': 'Two 1'}]}
        reader.poll.side_effect = [data, None, None, None, None]
        keys = iter([ord(']'), wallboard.curses.KEY_RIGHT, ord(' '), ord('v')])
        def getch():
            key = next(keys, None)
            if key is None:
                wallboard.STOP = True
                return -1
            return key
        screen.getch.side_effect = getch
        with patch.object(wallboard, 'STOP', False), patch.dict(sys.modules, {wallboard.__name__: wallboard}), \
             patch.object(wallboard.curses, 'curs_set'), patch.object(wallboard, 'read_config', return_value=wallboard.DEFAULTS), \
             patch.object(wallboard, 'Reader', return_value=reader), patch.object(wallboard, 'display_cards', return_value=(None, None)), \
             patch.object(presentation.Presentation, 'for_console', return_value=unit), \
             patch.object(wallboard, 'rescue_access', return_value=None), patch.object(wallboard, 'root_json', return_value={}), \
             patch.object(wallboard, 'colors', return_value={'fg': 0}), \
             patch.object(wallboard, 'frame', return_value=wallboard.Canvas(*wallboard.viewport(137, 38)[:2])) as text_frame:
            wallboard.display(screen)
        self.assertFalse(text_frame.called, 'The full ASCII screen should not be painted beneath each graphical frame')
        inputs = [call.kwargs for call in unit.present.call_args_list]
        self.assertEqual([s['switch_offset'] for s in inputs], [0, 1, 0, 0, 0])
        self.assertEqual([s['selected'] for s in inputs], [0, 0, 1, 1, 1])
        self.assertTrue(inputs[-1]['paused'])
        self.assertEqual(inputs[-1]['view'], 'desk')
        unit.close.assert_called_once()
        reader.close.assert_called_once()
class TerminalOwnershipTest(unittest.TestCase):
    def test_display_user_has_drm_access_only_in_the_sandboxed_service(self):
        unit = (TOOLS.parent / 'debian/cmprovision-wallboard-display.service').read_text()
        self.assertIn('User=cmprovision-wallboard\n', unit)
        self.assertIn('SupplementaryGroups=video\n', unit)
        self.assertIn('DevicePolicy=closed\n', unit)
        self.assertIn('DeviceAllow=char-drm rw\n', unit)
        self.assertNotIn('AmbientCapabilities=', unit)

    def test_release_drops_overlay_before_ack_and_does_not_render_until_acquired(self):
        ops = []
        def ioctl(fd, op, value):
            ops.append((op, value))
            return bytes(8) if op == presentation.VT_GETMODE else 0
        with patch.object(presentation.os, 'ttyname', return_value='/dev/tty8'), \
             patch.object(presentation.fcntl, 'ioctl', side_effect=ioctl), \
             patch.object(presentation.signal, 'signal', return_value=signal.SIG_DFL):
            owner = presentation.Terminal(0, lambda: 'tty8')
            close = lambda: ops.append('release-overlay')
            try:
                self.assertTrue(owner.ready(close))
                owner.notify(signal.SIGUSR1, None)
                self.assertFalse(owner.ready(close))
                self.assertLess(ops.index('release-overlay'), ops.index((presentation.VT_RELDISP, 1)))
                self.assertFalse(owner.ready(close))
                owner.notify(signal.SIGUSR2, None)
                self.assertTrue(owner.ready(close))
                self.assertIn((presentation.VT_RELDISP, 2), ops)
                owner.notify(signal.SIGUSR1, None)
                owner.notify(signal.SIGUSR2, None)
                self.assertFalse(owner.ready(close))
                self.assertTrue(owner.ready(close))
            finally:
                owner.close()

    def test_ordinary_terminal_and_unavailable_vt_ioctl_keep_text_mode(self):
        for tty in ('/dev/tty8', '/dev/pts/3'):
            with patch.object(presentation.os, 'ttyname', return_value=tty), \
                 patch.object(presentation.fcntl, 'ioctl', side_effect=PermissionError), \
                 patch.object(presentation.signal, 'signal') as install:
                owner = presentation.Terminal(0, lambda: 'tty8')
                self.assertFalse(owner.ready(Mock()))
                owner.close()
                install.assert_not_called()


if __name__ == '__main__':
    unittest.main()
