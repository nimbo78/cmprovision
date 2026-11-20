"""Coordinate the HDMI overlay with Linux virtual-console switching."""
from collections import deque
import fcntl
import math
import os
import signal
import struct
import sys
from display_marquee import Marquee

# linux/uapi/linux/vt.h. The kernel waits for VT_RELDISP before actually switching.
VT_GETMODE = 0x5601
VT_SETMODE = 0x5602
VT_RELDISP = 0x5605
VT_MODE = struct.Struct('=BBhhh')


class Terminal:
    def __init__(self, fd, active_vt):
        self.fd, self.active_vt = fd, active_vt
        self.previous = None
        self.handlers = {}
        self.pending = deque()
        self.away = False
        try:
            if os.ttyname(fd) != '/dev/tty8':
                return
            previous = fcntl.ioctl(fd, VT_GETMODE, bytes(VT_MODE.size))
            if VT_MODE.unpack(previous)[0] != 0:  # another process owns this VT
                return
            for sig in (signal.SIGUSR1, signal.SIGUSR2):
                self.handlers[sig] = signal.signal(sig, self.notify)
            fcntl.ioctl(fd, VT_SETMODE, VT_MODE.pack(1, 0, signal.SIGUSR1, signal.SIGUSR2, 0))
            self.previous = previous
        except (OSError, ValueError):
            self.close()

    def notify(self, sig, _frame):
        self.pending.append(sig)

    def ready(self, release):
        if self.previous is None:
            return False
        sig = self.pending.popleft() if self.pending else None
        try:
            if sig == signal.SIGUSR1:
                self.away = True
                try:
                    release()
                finally:
                    fcntl.ioctl(self.fd, VT_RELDISP, 1)
            elif sig == signal.SIGUSR2:
                fcntl.ioctl(self.fd, VT_RELDISP, 2)
                self.away = False
        except OSError:
            self.close()
            return False
        return not self.away and self.active_vt() == 'tty8'

    def close(self):
        if self.previous is not None:
            try:
                fcntl.ioctl(self.fd, VT_SETMODE, self.previous)
            except OSError:
                pass  # already released by the kernel on hangup
            self.previous = None
        for sig, handler in self.handlers.items():
            signal.signal(sig, handler)
        self.handlers.clear()


class Presentation:
    def __init__(self, terminal, open_output, render):
        self.terminal = terminal
        self.open_output = open_output
        self.render = render
        self.output = None
        self.last_frame = None
        self.motion = Marquee()
        self.retry_at = 0
        self.last_error = None

    @classmethod
    def for_console(cls, fd, active_vt):
        # Watcher, PHP settings and the original text preview do not import graphics.
        def open_output():
            from hdmi_output import open_output as open_hdmi
            return open_hdmi()

        def render(*args, **kwargs):
            from hdmi_graphics import render_frame
            return render_frame(*args, **kwargs)

        return cls(Terminal(fd, active_vt), open_output, render)

    def drop_output(self):
        output, self.output = self.output, None
        self.last_frame = None
        self.motion = Marquee()
        if output is not None:
            try:
                output.close()
            except (OSError, RuntimeError):
                pass  # the connector or card may already have disappeared

    def present(self, board, data, monotonic, now, *, access=None, setup=None, **view):
        if not self.terminal.ready(self.drop_output):
            self.drop_output()
            return False
        if monotonic < self.retry_at:
            return False
        try:
            if self.output is None:
                self.output = self.open_output()
            remaining = math.ceil(access['expires_at'] - monotonic) if access else None
            if remaining is not None and remaining <= 0:
                access, remaining = None, None
            key = (id(data), int(now), view, remaining, access and access['created_at'], setup)
            if key == self.last_frame:
                if not self.motion.advance(monotonic):
                    return True
                frame = self.motion.image
            else:
                if access or setup:
                    self.motion = Marquee()
                frame = self.render(board, data, self.output.size, now=now, access=access,
                                    setup=setup, remaining=remaining, motion=self.motion, monotonic=monotonic, **view)
            # A switch may have been requested while Pillow was composing this frame.
            if not self.terminal.ready(self.drop_output):
                self.drop_output()
                return False
            self.output.show(frame)
            if self.last_frame is None:
                print('HDMI graphical display active (%dx%d canvas)' % self.output.size, file=sys.stderr, flush=True)
            self.last_frame = key
            self.last_error = None
            return True
        except (OSError, ImportError, RuntimeError, ValueError) as exc:
            self.drop_output()
            self.retry_at = monotonic + 10
            # Driver errors never need credentials, private setup codes, or snapshot contents in the journal.
            error = (type(exc).__name__, getattr(exc, 'errno', None))
            if error != self.last_error:
                print('HDMI text fallback: %s (errno %s)' % error, file=sys.stderr, flush=True)
                self.last_error = error
            return False

    def close(self):
        try:
            self.drop_output()
        finally:
            self.terminal.close()
