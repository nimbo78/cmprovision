"""Present a Pillow image on one active HDMI connector's dedicated KMS overlay."""

import ctypes
from contextlib import contextmanager
import errno
import os
from pathlib import Path
import select
import time


DRM_SYS = Path('/sys/class/drm')
ACTIVE_VT = Path('/sys/class/tty/tty0/active')


def _require_wallboard_vt():
    if ACTIVE_VT.read_text().strip() != 'tty8':
        raise OSError(errno.EAGAIN, 'The wallboard VT is inactive')


def _logical_size(width, height):
    if width <= 0 or height <= 0:
        raise OSError(errno.EINVAL, 'Invalid HDMI mode')
    if width <= 2160 and height <= 900:
        return width, height
    if width * 900 > height * 2160:
        return 2160, max(1, height * 2160 // width)
    return max(1, width * 900 // height), 900


class _Master:
    def __init__(self):
        self.drm = ctypes.CDLL('libdrm.so.2', use_errno=True)
        for name in ('drmIsMaster', 'drmSetMaster', 'drmDropMaster'):
            method = getattr(self.drm, name)
            method.argtypes = [ctypes.c_int]
            method.restype = ctypes.c_int

    def drop(self, fd):
        result = self.drm.drmDropMaster(fd)
        if result:
            error = OSError(ctypes.get_errno() or abs(result), 'Cannot release DRM master')
            if self.drm.drmIsMaster(fd):
                # kms++ restores every saved CRTC in Card::~Card. Revoke this open
                # file if DROP_MASTER itself failed; leave its fd number safe to close.
                with open(os.devnull, 'rb', buffering=0) as inert:
                    os.dup2(inert.fileno(), fd, inheritable=False)
            raise error

    @contextmanager
    def hold(self, fd):
        # FPMS may be finishing its own flip on this card. No master is held while waiting.
        deadline = time.monotonic() + 0.075
        while True:
            ctypes.set_errno(0)
            result = self.drm.drmSetMaster(fd)
            if not result:
                break
            error = ctypes.get_errno() or abs(result)
            if error != errno.EBUSY or time.monotonic() >= deadline:
                raise OSError(error, 'Cannot acquire DRM master')
            time.sleep(0.004)
        try:
            yield
        finally:
            self.drop(fd)


def _copy_frame(image, fb):
    pixels = memoryview(image.tobytes('raw', 'BGRX'))
    target = fb.map(0)
    row_bytes = image.width * 4
    if fb.stride(0) == row_bytes:
        target.cast('B')[:] = pixels
        return
    # pykms returns a 2-D memoryview. With pitch padding only each individual
    # row is contiguous; casting the whole map raises TypeError.
    for row in range(image.height):
        offset = row * row_bytes
        target[row:row + 1].cast('B')[:row_bytes] = pixels[offset:offset + row_bytes]


class _HdmiOutput:
    def __init__(self, kms, master, card, connector, status):
        self.kms, self.master, self.card = kms, master, card
        self.connector, self.status = connector, status
        self.crtc = connector.get_current_crtc()
        if self.crtc is None:
            raise OSError(errno.ENODEV, 'HDMI has no active CRTC')
        self.crtc.refresh_props()
        self.mode_id = self.crtc.get_prop_value('MODE_ID')
        self.crtc.refresh()
        if not self.crtc.mode_valid or not self.mode_id or not self._routed():
            raise OSError(errno.ENODEV, 'HDMI requires an active, dedicated CRTC')
        mode = self.crtc.mode
        self.native = (mode.hdisplay, mode.vdisplay)
        self.size = _logical_size(*self.native)
        self.buffers = [kms.DumbFramebuffer(card, *self.size, 'XR24') for _ in range(2)]
        self.front = None
        self.pending = False
        self.plane = self._select_plane()

    def _routed(self):
        if self.status.read_text().strip() != 'connected':
            return False
        self.crtc.refresh_props()
        if not self.crtc.get_prop_value('ACTIVE') or self.crtc.get_prop_value('MODE_ID') != self.mode_id:
            return False
        routed = []
        for connector in self.card.connectors:
            connector.refresh_props()
            if connector.get_prop_value('CRTC_ID') == self.crtc.id:
                routed.append(connector.id)
        return routed == [self.connector.id]

    @staticmethod
    def _plane_state(plane):
        plane.refresh_props()
        return plane.get_prop_value('CRTC_ID'), plane.get_prop_value('FB_ID')

    def _request(self, plane, fb):
        request = self.kms.AtomicReq(self.card)
        request.add_plane(plane, fb, self.crtc, dst=(0, 0, *self.native))
        return request

    def _select_plane(self):
        with self.master.hold(self.card.fd):
            _require_wallboard_vt()
            if not self._routed():
                raise OSError(errno.ENODEV, 'HDMI route changed')
            for plane in self.crtc.possible_planes:
                if (plane.plane_type == self.kms.PlaneType.Overlay
                        and self.kms.PixelFormat.XRGB8888 in plane.formats
                        and self._plane_state(plane) == (0, 0)
                        and self._request(plane, self.buffers[0]).test() == 0):
                    return plane
        raise OSError(errno.ENOSPC, 'No free HDMI overlay capable of scaling XRGB8888 to this mode')

    def show(self, image):
        if self.card is None or image.mode != 'RGB' or image.size != self.size:
            raise OSError(errno.EINVAL, 'HDMI output is closed or frame geometry is invalid')
        if self.pending:
            raise OSError(errno.EBUSY, 'Previous HDMI flip is unconfirmed')
        _require_wallboard_vt()
        back = 1 if self.front == 0 else 0
        try:
            _copy_frame(image, self.buffers[back])
            with self.master.hold(self.card.fd):
                if not self._routed():
                    raise OSError(errno.ENODEV, 'HDMI connector or mode changed')
                owner = (0, 0) if self.front is None else (self.crtc.id, self.buffers[self.front].id)
                if self._plane_state(self.plane) != owner:
                    raise OSError(errno.EBUSY, 'HDMI overlay changed owner')
                request = self._request(self.plane, self.buffers[back])
                _require_wallboard_vt()
                token = self.buffers[back].id
                result = request.commit(token)
                if result:
                    raise OSError(-result if result < -1 else errno.EIO, 'Cannot present the HDMI overlay')
                self.front, self.pending = back, True
            # The synchronous ioctl would keep master through vblank and prevent a
            # simultaneous DPI update. Wait on this card FD after returning master.
            if not select.select([self.card.fd], [], [], 0.5)[0]:
                raise OSError(errno.ETIMEDOUT, 'HDMI page flip timed out')
            if not any(event.type == self.kms.DrmEventType.FLIP_COMPLETE and event.data == token
                       for event in self.card.read_events()):
                raise OSError(errno.EIO, 'Missing HDMI page flip completion')
            self.pending = False
        except (RuntimeError, ValueError, BufferError) as error:
            raise OSError(errno.EIO, str(error)) from error

    def close(self):
        if self.card is None:
            return
        try:
            if self.front is not None:
                with self.master.hold(self.card.fd):
                    owner = (self.crtc.id, self.buffers[self.front].id)
                    if self._routed() and self._plane_state(self.plane) == owner:
                        request = self.kms.AtomicReq(self.card)
                        request.add(self.plane, {'FB_ID': 0, 'CRTC_ID': 0})
                        if request.commit_sync() != 0:
                            raise OSError(errno.EIO, 'Cannot release the HDMI overlay')
        except (RuntimeError, ValueError) as error:
            raise OSError(errno.EIO, str(error)) from error
        finally:
            # RMFB also retires our own buffers if a peer has DRM master at VT switch.
            self.front = None
            self.buffers.clear()
            self.plane = self.crtc = self.connector = self.card = None


def open_output():
    """Open a connected HDMI overlay for tty8; callers can retry after an OSError."""
    _require_wallboard_vt()
    import pykms as kms

    master = _Master()
    error_number, reason = errno.ENODEV, 'No connected HDMI output'
    for status in sorted(DRM_SYS.glob('card[0-9]*-HDMI-*/status')):
        try:
            if status.read_text().strip() != 'connected':
                continue
            node, name = status.parent.name.split('-', 1)
            card = kms.Card('/dev/dri/' + node)
            # The opener of an idle card receives master even without capabilities.
            # An FD opened during a peer's commit may need reopening to qualify.
            if not master.drm.drmIsMaster(card.fd):
                raise OSError(errno.EBUSY, 'DRM master is in use')
            master.drop(card.fd)
            if not card.has_atomic:
                raise OSError(errno.ENOSYS, 'Atomic KMS is unavailable')
            for connector in card.connectors:
                if connector.fullname == name and connector.connected():
                    return _HdmiOutput(kms, master, card, connector, status)
            error_number, reason = errno.ENODEV, 'HDMI connector is unavailable'
        except OSError as error:
            error_number, reason = error.errno or errno.EIO, error.strerror or str(error)
        except (RuntimeError, ValueError) as error:
            error_number, reason = errno.EIO, str(error)
    raise OSError(error_number, reason)
