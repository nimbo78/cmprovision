import ctypes
import errno
import importlib.util
import os
import select
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from PIL import Image


class Object:
    def __init__(self, number, **props):
        self.id = number
        self.live = props
        self.refresh_props()

    def refresh_props(self):
        self.props = self.live.copy()

    def get_prop_value(self, name):
        return self.props[name]


class Connector(Object):
    def __init__(self, number, name, crtc):
        super().__init__(number, CRTC_ID=crtc.id if crtc else 0)
        self.fullname, self.crtc = name, crtc

    def connected(self):
        return True

    def get_current_crtc(self):
        return self.crtc


class Buffer:
    def __init__(self, number, width, height, padding):
        self.id, self.width, self.height = number, width, height
        self.step = 2 if padding else 1
        self.pitch = width * 4 * self.step
        self.pixels = bytearray([0x77]) * (self.pitch * height)

    def stride(self, index):
        return self.pitch

    def map(self, index):
        packed = memoryview(self.pixels).cast('B', shape=(self.height * self.step, self.width * 4))
        return packed[::self.step]


class Hardware:
    def __init__(self, folder):
        self.vt = folder / 'active'
        self.vt.write_text('tty8')
        self.status = folder / 'card1-HDMI-A-1' / 'status'
        self.status.parent.mkdir()
        self.status.write_text('connected')
        self.crtc = Object(105, ACTIVE=1, MODE_ID=317)
        self.crtc.mode = SimpleNamespace(hdisplay=8, vdisplay=3)
        self.crtc.mode_valid = True
        self.crtc.refresh = lambda: None
        self.dpi = Connector(49, 'DPI-1', Object(81))
        self.hdmi = Connector(35, 'HDMI-A-1', self.crtc)
        self.primary = self.plane(12, 'primary', 105, 700)
        self.busy = self.plane(13, 'overlay', 81, 701)
        self.other_format = self.plane(14, 'overlay', 0, 0, 'NV12')
        self.overlay = self.plane(15, 'overlay', 0, 0)
        self.crtc.possible_planes = [self.primary, self.busy, self.other_format, self.overlay]
        self.card = SimpleNamespace(fd=9, has_atomic=True, connectors=[self.hdmi, self.dpi], crtcs=[self.crtc],
                                    planes=self.crtc.possible_planes)
        self.mode_tests, self.modesets, self.closed_fbs = [], [], []
        self.master = False
        self.fail_acquire = False
        self.busy_attempts = 0
        self.flips, self.waits = [], []
        self.deliver = True
        self.card.read_events = self.read_events
        self.commit_error = 0
        self.rejected = set()
        self.commits, self.buffers, self.opened = [], [], []
        self.padding = 4
        self.master_lib = SimpleNamespace(
            drmSetMaster=self.call(self.acquire),
            drmDropMaster=self.call(self.drop),
            drmIsMaster=self.call(lambda fd: int(self.master)),
            drmModeCloseFB=lambda fd, fb: self.closed_fbs.append(fb) or 0,
        )
        self.kms = SimpleNamespace(
            Card=self.open_card, DumbFramebuffer=self.buffer, AtomicReq=self.request,
            PlaneType=SimpleNamespace(Overlay='overlay', Primary='primary'), PixelFormat=SimpleNamespace(XRGB8888='XR24'),
            DrmEventType=SimpleNamespace(FLIP_COMPLETE='flip'),
        )

    def hotplug(self):
        self.hdmi.crtc = None
        self.hdmi.live['CRTC_ID'] = 0
        self.crtc.live.update(ACTIVE=0, MODE_ID=0)
        self.primary.live.update(CRTC_ID=0, FB_ID=0)
        self.hdmi.get_possible_crtcs = lambda: [self.crtc]
        self.hdmi.get_default_mode = lambda: SimpleNamespace(hdisplay=8, vdisplay=3, to_blob=lambda card: Object(318))

    @staticmethod
    def plane(number, kind, route, fb, fmt='XR24'):
        obj = Object(number, CRTC_ID=route, FB_ID=fb)
        obj.plane_type, obj.formats = kind, [fmt]
        return obj

    @staticmethod
    def call(fn):
        def invoke(fd):
            assert fd == 9
            return fn(fd)
        return invoke

    def open_card(self, path):
        assert str(path) == '/dev/dri/card1'
        self.opened.append(path)
        self.master = True  # Opening an idle primary DRM node obtains master implicitly.
        return self.card

    def acquire(self, fd):
        if self.fail_acquire or self.busy_attempts:
            self.busy_attempts = max(0, self.busy_attempts - 1)
            ctypes.set_errno(errno.EBUSY)
            return -1
        self.master = True
        return 0

    def drop(self, fd):
        assert self.master
        self.master = False
        return 0

    def buffer(self, card, width, height, fmt):
        assert not self.master and fmt == 'XR24'
        fb = Buffer(1000 + len(self.buffers), width, height, self.padding)
        self.buffers.append(fb)
        return fb

    def readable(self, read, write, exceptional, timeout):
        assert not self.master and read == [self.card.fd] and timeout > 0
        self.waits.append(list(self.flips))
        return (read if self.deliver else [], [], [])

    def read_events(self):
        assert not self.master
        events = [SimpleNamespace(type='flip', data=token) for token in self.flips]
        self.flips.clear()
        return events

    def request(self, card):
        hw = self

        class Request:
            def __init__(self):
                self.changes = []

            def add(self, obj, props):
                assert hw.master
                self.changes.append((obj, props.copy()))

            def add_plane(self, plane, fb, crtc, src=None, dst=None):
                self.add(plane, {'FB_ID': fb.id, 'CRTC_ID': crtc.id, 'dst': dst})

            def test(self, allow_modeset=False):
                assert hw.master
                if allow_modeset:
                    hw.mode_tests.append(list(self.changes))
                return -errno.EINVAL if any(obj.id in hw.rejected for obj, _ in self.changes) else 0

            def commit(self, token, allow_modeset=False):
                result = self.apply(allow_modeset)
                if result == 0:
                    hw.flips.append(token)
                return result

            def commit_sync(self, allow_modeset=False):
                return self.apply(allow_modeset)

            def apply(self, allow_modeset):
                assert hw.master
                assert allow_modeset or all(obj not in (hw.crtc, hw.hdmi, hw.dpi) for obj, _ in self.changes)
                if allow_modeset:
                    hw.modesets.append(list(self.changes))
                hw.commits.extend(self.changes)
                if not hw.commit_error:
                    for obj, props in self.changes:
                        obj.live.update(props)
                return hw.commit_error

        return Request()


class HdmiOutputTest(unittest.TestCase):
    def setUp(self):
        path = Path(__file__).resolve().parents[2] / 'tools/hdmi_output.py'
        spec = importlib.util.spec_from_file_location('hdmi_output', path)
        self.module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.module)
        folder = Path(self.enterContext(tempfile.TemporaryDirectory()))
        self.hw = Hardware(folder)
        self.enterContext(patch.object(self.module, 'DRM_SYS', folder))
        self.enterContext(patch.object(self.module, 'ACTIVE_VT', self.hw.vt))
        self.enterContext(patch.dict(sys.modules, {'pykms': self.hw.kms}))
        self.enterContext(patch.object(ctypes, 'CDLL', return_value=self.hw.master_lib))
        self.enterContext(patch.object(select, 'select', side_effect=self.hw.readable))

    def open(self):
        screen = self.module.open_output()
        self.addCleanup(screen.close)
        self.assertFalse(self.hw.master)
        return screen

    def test_only_hdmi_overlay_receives_a_full_color_frame_then_reveals_the_console(self):
        screen = self.open()
        self.assertEqual(screen.size, (8, 3))
        self.assertEqual(self.hw.commits, [])
        frame = Image.new('RGB', screen.size, (0x11, 0x22, 0x33))
        screen.show(frame)
        first = self.hw.buffers[0]
        second = self.hw.buffers[1]
        self.assertEqual(self.hw.commits, [(self.hw.overlay, {'FB_ID': first.id, 'CRTC_ID': 105, 'dst': (0, 0, 8, 3)})])
        for y in range(3):
            row = first.pixels[y * 64 : (y + 1) * 64]
            self.assertEqual(row[:3], bytes.fromhex('332211'))
            self.assertEqual(row[28:31], bytes.fromhex('332211'))
            self.assertEqual(row[32:64], b'\x77' * 32)
        screen.show(frame)
        self.assertEqual(self.hw.overlay.live['FB_ID'], second.id)
        self.assertFalse(self.hw.master)
        self.hw.vt.write_text('tty1')
        screen.close()
        self.assertEqual(self.hw.commits[-1], (self.hw.overlay, {'FB_ID': 0, 'CRTC_ID': 0}))
        screen.close()
        self.assertEqual(len(self.hw.commits), 3)
        self.assertFalse(self.hw.master)

    def test_logical_buffer_and_full_native_destination(self):
        for native, logical in [((1920, 1080), (1600, 900)), ((3840, 2160), (1600, 900)),
                                ((3440, 1440), (2150, 900)), ((5120, 1440), (2160, 607))]:
            with self.subTest(native=native):
                self.hw.padding = 0
                self.hw.crtc.mode.hdisplay, self.hw.crtc.mode.vdisplay = native
                screen = self.open()
                self.assertEqual(screen.size, logical)
                screen.show(Image.new('RGB', logical))
                self.assertEqual(self.hw.commits[-1][1]['dst'], (0, 0, *native))
                screen.close()
                self.hw.buffers.clear()

    def test_inactive_cloned_and_occupied_outputs_are_left_alone(self):
        cases = [
            (self.hw.hdmi, 'CRTC_ID', 0),
            (self.hw.crtc, 'ACTIVE', 0),
            (self.hw.dpi, 'CRTC_ID', 105),
            (self.hw.overlay, 'CRTC_ID', 81),
            (self.hw.overlay, 'FB_ID', 555),
        ]
        for obj, prop, value in cases:
            with self.subTest(id=obj.id, prop=prop), patch.dict(obj.live, {prop: value}):
                with self.assertRaises(OSError):
                    self.module.open_output()
                self.assertFalse(self.hw.master)
                self.assertEqual(self.hw.commits, [])

    def test_disconnected_hdmi_or_administrators_vt_opens_no_card(self):
        for vt, status in [('tty8', 'disconnected'), ('tty1', 'connected'), ('', 'connected')]:
            self.hw.vt.write_text(vt)
            self.hw.status.write_text(status)
            with self.subTest(vt=vt, status=status), self.assertRaises(OSError):
                self.module.open_output()
        self.assertFalse(self.hw.opened)

    def test_hotplug_lights_an_idle_hdmi_with_the_first_frame_and_releases_only_its_route(self):
        self.hw.hotplug()
        screen = self.open()
        self.assertTrue(self.hw.mode_tests)
        self.assertEqual(self.hw.commits, [])
        frame = Image.new('RGB', screen.size, 'orange')
        screen.show(frame)
        self.assertEqual(self.hw.hdmi.live['CRTC_ID'], 105)
        self.assertEqual(self.hw.crtc.live, {'ACTIVE': 1, 'MODE_ID': 318})
        self.assertEqual(self.hw.primary.live['FB_ID'], self.hw.buffers[0].id)
        screen.show(frame)
        self.assertEqual(self.hw.primary.live['FB_ID'], self.hw.buffers[1].id)
        self.assertEqual(len(self.hw.modesets), 1)
        self.hw.status.write_text('disconnected')
        screen.close()
        self.assertEqual(self.hw.hdmi.live['CRTC_ID'], 0)
        self.assertEqual(self.hw.crtc.live, {'ACTIVE': 0, 'MODE_ID': 0})
        self.assertEqual(self.hw.primary.live['FB_ID'], 0)
        self.assertEqual(self.hw.dpi.live['CRTC_ID'], 81)
        self.assertEqual(self.hw.busy.live, {'CRTC_ID': 81, 'FB_ID': 701})

    def test_hotplug_never_claims_a_busy_or_rejected_route(self):
        self.hw.hotplug()
        for obj, props in [(self.hw.crtc, {'ACTIVE': 1}), (self.hw.crtc, {'MODE_ID': 77}),
                           (self.hw.dpi, {'CRTC_ID': 105}), (self.hw.primary, {'FB_ID': 778}),
                           (self.hw.busy, {'CRTC_ID': 105})]:
            with self.subTest(props=props), patch.dict(obj.live, props), self.assertRaises(OSError):
                self.module.open_output()
        self.hw.rejected.add(self.hw.primary.id)
        with self.assertRaises(OSError):
            self.module.open_output()
        self.assertEqual(self.hw.commits, [])

    def test_hotplug_rechecks_ownership_before_first_frame_and_before_disabling(self):
        self.hw.hotplug()
        screen = self.open()
        frame = Image.new('RGB', screen.size)
        self.hw.vt.write_text('tty1')
        with self.assertRaises(OSError):
            screen.show(frame)
        self.hw.vt.write_text('tty8')
        with patch.dict(self.hw.dpi.live, {'CRTC_ID': 105}), self.assertRaises(OSError):
            screen.show(frame)
        self.assertEqual(self.hw.commits, [])
        screen.show(frame)
        self.hw.primary.live['FB_ID'] = 999
        previous = list(self.hw.commits)
        screen.close()
        self.assertEqual(self.hw.commits, previous)
        self.assertEqual(self.hw.primary.live['FB_ID'], 999)

    def test_hotplug_preserves_scanout_if_a_peer_starts_using_the_same_route(self):
        self.hw.hotplug()
        screen = self.open()
        screen.show(Image.new('RGB', screen.size))
        self.hw.overlay.live.update(CRTC_ID=105, FB_ID=225)
        previous = list(self.hw.commits)
        screen.close()
        self.assertEqual(self.hw.commits, previous)
        self.assertEqual(self.hw.crtc.live['ACTIVE'], 1)
        self.assertEqual(self.hw.closed_fbs, [fb.id for fb in self.hw.buffers])


    def test_late_vt_switch_hot_unplug_mode_change_or_taken_plane_never_presents(self):
        screen = self.open()
        frame = Image.new('RGB', screen.size)
        # Change tty after allocating the frame, then replug and change the DRM routing.
        for entry, new, original in [(self.hw.vt, 'tty1', 'tty8'), (self.hw.status, 'disconnected', 'connected')]:
            entry.write_text(new)
            with self.assertRaises(OSError):
                screen.show(frame)
            entry.write_text(original)
        for obj, changed in [(self.hw.dpi, {'CRTC_ID': 105}), (self.hw.crtc, {'MODE_ID': 318}),
                             (self.hw.overlay, {'FB_ID': 777}), (self.hw.overlay, {'CRTC_ID': 81})]:
            with self.subTest(id=obj.id), patch.dict(obj.live, changed), self.assertRaises(OSError):
                screen.show(frame)
        self.assertEqual(self.hw.commits, [])
        self.assertFalse(self.hw.master)

    def test_another_owner_survives_closing_and_commits_always_drop_master(self):
        screen = self.open()
        frame = Image.new('RGB', screen.size)
        self.hw.fail_acquire = True
        with self.assertRaises(OSError):
            screen.show(frame)
        self.assertFalse(self.hw.master)
        self.hw.fail_acquire = False
        self.hw.commit_error = -errno.ENOSPC
        with self.assertRaises(OSError):
            screen.show(frame)
        self.assertFalse(self.hw.master)
        self.hw.commit_error = 0
        screen.show(frame)
        self.hw.overlay.live.update(CRTC_ID=81, FB_ID=701)
        before = len(self.hw.commits)
        screen.close()
        self.assertEqual(len(self.hw.commits), before)
        self.assertFalse(self.hw.master)

    def test_plane_that_cannot_scale_is_skipped(self):
        self.hw.rejected.add(self.hw.overlay.id)
        second = self.hw.plane(16, 'overlay', 0, 0)
        self.hw.crtc.possible_planes.append(second)
        screen = self.open()
        screen.show(Image.new('RGB', screen.size))
        self.assertIs(self.hw.commits[-1][0], second)

    def test_kms_binding_errors_are_reported_as_unavailable_and_release_master(self):
        with patch.object(self.hw.kms, 'DumbFramebuffer', side_effect=RuntimeError('no memory')), self.assertRaises(OSError):
            self.module.open_output()
        self.assertFalse(self.hw.master)

    def test_wait_for_scanout_happens_without_master(self):
        screen = self.open()
        screen.show(Image.new('RGB', screen.size))
        self.assertEqual(self.hw.waits, [[self.hw.buffers[0].id]])
        self.assertFalse(self.hw.master)

    def test_lost_flip_event_still_releases_the_submitted_frame(self):
        screen = self.open()
        self.hw.deliver = False
        with self.assertRaises(OSError) as caught:
            screen.show(Image.new('RGB', screen.size))
        self.assertEqual(caught.exception.errno, errno.ETIMEDOUT)
        self.assertFalse(self.hw.master)
        self.assertEqual(self.hw.overlay.live['FB_ID'], self.hw.buffers[0].id)
        with self.assertRaises(OSError) as retry:
            screen.show(Image.new('RGB', screen.size))
        self.assertEqual(retry.exception.errno, errno.EBUSY)
        self.assertEqual(len(self.hw.commits), 1)
        screen.close()
        self.assertEqual(self.hw.overlay.live['FB_ID'], 0)

    def test_temporary_fpms_master_contention_retries_the_same_frame(self):
        screen = self.open()
        self.hw.busy_attempts = 2
        screen.show(Image.new('RGB', screen.size))
        self.assertEqual(len(self.hw.commits), 1)
        self.assertFalse(self.hw.master)

    def test_failed_master_release_revokes_the_card_before_its_destructor_can_modeset(self):
        def refuse(fd):
            ctypes.set_errno(errno.EIO)
            return -1

        # Substitute for the kernel's final close; leave real test-process fds intact.
        with patch.object(self.hw.master_lib, 'drmDropMaster', self.hw.call(refuse)), patch.object(os, 'dup2') as revoke:
            with self.assertRaises(OSError):
                self.module.open_output()
            self.assertEqual(revoke.call_args.args[1], self.hw.card.fd)
