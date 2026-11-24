#!/usr/bin/python3 -I
"""Socket-activated local UART broker for authenticated cmprovision requests."""
import base64
from collections import Counter
from contextlib import contextmanager, suppress
from dataclasses import replace
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
import logging
import os
from pathlib import Path
import re
import secrets
import select
import signal
import socket
import sys
import termios
import threading
import time


# -I excludes the script directory. Load only the two root-installed siblings.
def sibling(module, filename):
    spec = importlib.util.spec_from_file_location(module, Path(__file__).resolve().with_name(filename))
    loaded = importlib.util.module_from_spec(spec)
    sys.modules[module] = loaded
    spec.loader.exec_module(loaded)
    return loaded


cli = sibling("cmprovision_serial_console", "serial-console.py")
protocol = sibling("cmprovision_serial_transfer", "serial_transfer.py")
IDLE_SECONDS = 12
HEARTBEAT_SECONDS = 2
CONNECT_LISTEN_SECONDS = 1
LOGIN_INVITATION = re.compile(
    rb"[ \t\r\n]*(?:User interface con\d+ is available[ \t]*[\r\n]+[ \t\r\n]*Please press ENTER\."
    rb"|(?:[\w.-]+ con\d+ is now available[ \t]*[\r\n]+[ \t\r\n]*)?Press (?:ENTER|RETURN) to get started[.!]?)"
    rb"[ \t\r\n]*", re.I | re.ASCII)
RETAIN_SECONDS = 30
RX_LIMIT = 128 * 1024
TX_LIMIT = 8192
TX_STALL_SECONDS = 5
# Laravel sends UTF-8 with unescaped Unicode. Quotes and backslashes may
# double the size of a valid file in the envelope.
MAX_WIRE = 2 * 1024 * 1024 + 8192
MAX_LEASES = 16
GENERATION_SALT = secrets.token_bytes(16)
IO_ERRORS = (OSError, termios.error, cli.Unavailable)
ERRORS = {"invalid": "Invalid console request.", "busy": "Console is in use.",
          "expired": "Console session expired.", "conflict": "Console operation out of sequence.",
          "unavailable": "Console is unavailable."}


class Denied(Exception):
    def __init__(self, code, message=None, consumed=False):
        self.code = code
        self.message = message or ERRORS[code]
        self.consumed = consumed


class Disconnected(cli.Unavailable):
    pass


def require(condition, code="invalid"):
    if not condition:
        raise Denied(code)


def token(value):
    return isinstance(value, str) and re.fullmatch("[a-f0-9]{64}", value) is not None


def garbled(data):
    # Ignore terminal controls and split UTF-8 tails. Re-scan only for substantial noise.
    text = data.decode("utf-8", errors="replace")
    noise = sum(c == "\ufffd" or (ord(c) < 32 and c not in "\t\r\n\b\a\x1b") for c in text)
    return noise >= 3 and noise * 5 > len(text)


def generation(line):
    identity = (line.usb_path, line.interface, line.rdev, line.driver,
                line.generation, line.serial, line.usb_instance)
    return hashlib.sha256(GENERATION_SALT + json.dumps(identity).encode()).hexdigest()


@contextmanager
def claim_port(line, refresh, writable):
    with cli.operator_lock(line), cli.claim(line, refresh, writable=writable) as fd:
        yield fd


def port_busy(line):
    try:
        # Neither the serial descriptor nor the operator reservation is acquired.
        info = (Path("/sys/class/tty") / line.node.name / "dev").stat()
        key = f"{os.major(info.st_dev):02x}:{os.minor(info.st_dev):02x}:{info.st_ino}"
        with open("/proc/locks") as locks:
            if any(key in entry.split() for entry in locks):
                return True
        names = (line.node.name, line.by_id, line.by_path)
        if any(os.path.lexists(Path("/run/lock") / ("LCK.." + Path(n).name)) for n in names if n):
            return True
        cli.check_owners(line.rdev, Path("/proc"))
        return False
    except (cli.Busy, *IO_ERRORS):
        return True


class Lease:
    def __init__(self, broker, line, port, mode, baud, frame, key, wake):
        self.broker = broker
        self.line, self.port = line, port
        self.identify = mode == "identify"
        self.wake = wake
        self.last_poll = None
        self.wake_until = None
        self.baud, self.frame = baud, frame
        self.lock = threading.RLock()
        self.state = "detecting"
        self.message = ""
        self.last_contact = time.monotonic()
        self.ended = None
        self.fd = None
        self.released = threading.Event()
        self.seq = 0
        self.engine = protocol.ConsoleProtocol()
        self.rx, self.pending = bytearray(), bytearray()
        self.cursor = 0
        self.file_tx = False
        self.sent_for_echo = 0
        self.last_write = 0
        self.final_progress = None
        self.hostname = self.seen_at = self.platform = None
        self.observations = 0
        self.connection = hashlib.sha256(key.encode("ascii")).hexdigest()[:24]
        self.worker = threading.Thread(target=self.run, daemon=True)

    def audit(self, event):
        logging.getLogger("cmprovision.serial").info(json.dumps(
            {"event": event, "connection": self.connection, "mode": "identify" if self.identify else "connect"}))

    def finish(self, state, message, event=None):
        with self.lock:
            if self.ended is not None:
                return
            self.state, self.message, self.ended = state, message, time.monotonic()
            self.audit(event or state)
            job = self.engine.snapshot()
            if job and job["stage"] not in ("review", "stopped", "done"):
                self.final_progress = {
                    "last_done_line": job["last_done_line"],
                    "uncertain_line": job["line"] if job["stage"] in ("awaiting", "question", "uncertain") else None,
                }
            self.engine.transport_lost(message)
            self.engine = None
            self.pending.clear()
            self.rx.clear()
            if self.fd is not None and not self.identify:
                with suppress(*IO_ERRORS):
                    termios.tcflush(self.fd, termios.TCOFLUSH)

    def live(self):
        now = time.monotonic()
        gap = now - self.last_contact
        if self.ended is None and gap >= IDLE_SECONDS:
            self.finish("closed", "Console session timed out.", "expired")
        elif self.ended is None and gap >= HEARTBEAT_SECONDS:
            transfer = self.engine.snapshot()
            if (transfer and transfer["mode"] in ("bulk", "smart") and not transfer["hold"]
                    and transfer["stage"] in ("awaiting", "between")):
                self.engine.action("pause", now)
        return self.ended is None

    def refresh(self):
        with self.lock:
            if not self.live():
                raise cli.Unavailable("session ended")
        return cli.select_line(self.broker.inventory(), self.port)

    def verify(self):
        try:
            current = self.refresh()
        except cli.Unavailable as exc:
            raise Disconnected("adapter unavailable") from exc
        if current != self.line:
            raise Disconnected("adapter changed")

    def note_identity(self):
        if self.engine.hostname and self.observations != self.engine.prompt_observations:
            self.observations = self.engine.prompt_observations
            self.hostname = self.engine.hostname
            self.seen_at = datetime.now(timezone.utc).isoformat(timespec="milliseconds")
            self.broker.observe(self.line, self.hostname, self.seen_at, self.baud, self.frame)
        self.platform = self.engine.platform

    def settings(self, fd):
        """Use the CLI's passive two-window detector; keep only its winning sample."""
        if self.baud != "auto" and self.frame != "auto":
            return self.baud, self.frame, b""
        samples = {}
        def observe(rate, frame, end):
            with self.lock:
                if not self.live():
                    raise cli.Unavailable("session ended")
            self.verify()
            data = cli.read_window(fd, rate, frame, end)
            if cli.recognizable(data):
                samples[rate, frame] = data
            return data

        frames = cli.AUTO_FRAMES if self.frame == "auto" else (self.frame,)
        rates = cli.RATES if self.baud == "auto" else (self.baud,)
        result = cli.detect_speed(observe, frames=frames, rates=rates)
        if result.status == "detected":
            frame = result.frames[0]
            return result.baud, frame, samples[result.baud, frame]
        return None

    def record(self, data, now):
        self.wake_until = None
        if self.file_tx and self.pending:
            # Never credit the peer with echoing bytes we have yet to write.
            if len(data) > self.sent_for_echo:
                self.finish("closed", "Unexpected input before the command was sent.")
                return
            self.sent_for_echo -= len(data)
        self.engine.feed(data, now)
        if not self.identify:
            if len(self.rx) + len(data) > RX_LIMIT:
                self.finish("closed", "Console output exceeded the unread buffer.")
            else:
                self.rx.extend(data)

    def run(self):
        try:
            self.verify()
            with self.broker.claim(self.line, self.refresh, not self.identify) as fd:
                try:
                    if self.identify:
                        self.audit("identifying")
                    self.run_fd(fd)
                except Disconnected:
                    self.finish("closed", "USB console disconnected.", "disconnected")
                finally:
                    with self.lock:
                        self.fd = None
                        if not self.identify:
                            with suppress(*IO_ERRORS):
                                termios.tcflush(fd, termios.TCOFLUSH)
        except cli.Busy:
            self.finish("busy", ERRORS["busy"])
        except Disconnected:
            self.finish("closed", "USB console disconnected.", "disconnected")
        except (ValueError, *IO_ERRORS):
            self.finish("closed", "USB console disconnected or unavailable.", "unavailable")
        finally:
            self.finish("closed", "Console session ended.")
            self.released.set()

    def listen_on_connect(self, fd):
        hint_baud, hint_frame = self.broker.line_hint(self.line)
        baud = hint_baud if self.baud == "auto" else self.baud
        frame = hint_frame if self.frame == "auto" else self.frame
        cli.set_frame(fd, baud, frame, mark_errors=False)
        poller = select.poll()
        poller.register(fd, select.POLLIN)
        sample = bytearray()
        with self.lock:
            self.verify()
            self.state = "listening"
        deadline = time.monotonic() + CONNECT_LISTEN_SECONDS
        while len(sample) < 8192:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                break
            self.verify()
            events = poller.poll(max(1, min(150, int(remaining * 1000))))
            if not events:
                continue
            if events[0][1] & (select.POLLERR | select.POLLHUP | select.POLLNVAL):
                raise Disconnected("disconnected")
            try:
                data = os.read(fd, 8192 - len(sample))
            except BlockingIOError:
                continue
            if not data:
                raise Disconnected("end of stream")
            sample.extend(data)

        return baud, frame, bytes(sample)

    def run_fd(self, fd):
        direct = not self.identify and self.wake is not None
        if direct:
            choice = self.listen_on_connect(fd)
            sample = choice[2]
            if (self.baud == "auto" or self.frame == "auto") and sample and garbled(sample):
                # Preserve what the operator received. A different peer may use a different frame.
                with self.lock:
                    self.verify()
                    self.rx.extend(sample)
                    self.state = "detecting"
                detected = self.settings(fd)
                choice = detected or (choice[0], choice[1], b"")
                direct = False
        else:
            choice = self.settings(fd)
        while choice is None:
            if self.identify:
                self.finish("closed", "No settings confirmed. Select a manual baud and frame.")
                return
            with self.lock:
                if not self.live():
                    return
                self.state, self.message = "waiting", "Waiting for more output or manual settings."
            # The CLI detector leaves a supported candidate active. Fresh RX
            # triggers another complete scan, while an idle line only waits.
            watch = select.poll()
            watch.register(fd, select.POLLIN)
            while True:
                self.verify()
                events = watch.poll(150)
                if events:
                    if events[0][1] & (select.POLLERR | select.POLLHUP | select.POLLNVAL):
                        raise Disconnected("disconnected")
                    break
            with self.lock:
                if not self.live():
                    return
                self.state, self.message = "detecting", ""
            choice = self.settings(fd)
        baud, frame, sample = choice
        self.verify()
        with self.lock:
            if not self.live():
                return
            if not direct:
                cli.set_frame(fd, baud, frame, mark_errors=False)
                termios.tcflush(fd, termios.TCIFLUSH)
            self.baud, self.frame = baud, frame
            self.fd = fd
            self.state = "waiting" if self.identify else "connected"
            if not self.identify:
                self.audit("connected")
            if direct and sample:
                self.record(sample, time.monotonic())
            if (direct and self.wake and self.last_poll is not None and len(sample) < 8192
                    and (not sample or LOGIN_INVITATION.fullmatch(sample))):
                # Arm only. A live browser must poll again after the listening window.
                self.wake_until = self.last_poll + HEARTBEAT_SECONDS
            if sample and self.identify:
                # The winning RX window can end with a line terminator. Only
                # passive identification may treat its final line as evidence.
                # A writable session requires a fresh prompt at the final frame.
                self.record(sample.rstrip(b"\r\n"), time.monotonic())
            elif sample and not direct:
                # Show the detected prompt, but require fresh input to unlock a transfer.
                self.rx.extend(sample)
                self.broker.observe(self.line, None, None, baud, frame)
        identify_end = time.monotonic() + 5
        verify_at = 0
        poller = select.poll()
        while True:
            now = time.monotonic()
            if now >= verify_at:
                self.verify()
                verify_at = now + .25
            with self.lock:
                if not self.live():
                    return
                if self.pending and now - self.last_write >= TX_STALL_SECONDS:
                    self.finish("closed", "Console output stopped accepting writes.")
                    return
                watch_write = bool(self.pending)
            poller.register(fd, select.POLLIN | (select.POLLOUT if watch_write else 0))
            events = poller.poll(20)
            event = events[0][1] if events else 0
            if event & (select.POLLHUP | select.POLLERR | select.POLLNVAL):
                raise Disconnected("disconnected")
            now = time.monotonic()
            with self.lock:
                if not self.live():
                    return
                if event & select.POLLIN:
                    try:
                        data = os.read(fd, 8192)
                    except BlockingIOError:
                        continue
                    if not data:
                        raise Disconnected("end of stream")
                    self.record(data, now)
                    if self.ended is not None:
                        return
                self.engine.tick(now)
                self.note_identity()
                if self.identify:
                    if self.hostname or now >= identify_end:
                        self.finish("identified" if self.hostname else "closed",
                                    "Identification complete." if self.hostname else "No hostname observed.")
                        return
                    continue
                if self.file_tx and self.pending:
                    transfer = self.engine.snapshot()
                    if not transfer or transfer["stage"] in ("uncertain", "error", "stopped"):
                        self.pending.clear()
                if not self.pending:
                    payload = self.engine.take_output()
                    if len(payload) > TX_LIMIT:
                        self.finish("closed", "Console output queue exceeded its limit.")
                        return
                    self.pending.extend(payload)
                    self.file_tx = bool(payload)
                    self.sent_for_echo = 0
                    self.last_write = now
                if event & select.POLLOUT and self.pending:
                    self.verify()
                    try:
                        written = os.write(fd, self.pending)
                    except BlockingIOError:
                        continue
                    if written <= 0:
                        raise cli.Unavailable("write failed")
                    if self.file_tx:
                        self.sent_for_echo += written
                    del self.pending[:written]
                    self.last_write = now

    def poll(self, cursor):
        require(type(cursor) is int and cursor >= 0)
        if self.ended is None:
            require(self.cursor <= cursor <= self.cursor + len(self.rx), "conflict")
            if self.wake_until is not None:
                deadline, self.wake_until = self.wake_until, None
                if time.monotonic() < deadline and not select.select([self.fd], [], [], 0)[0]:
                    # Late input (even before the worker reads it) vetoes wake.
                    # Never retry a full TX buffer or an ambiguous write.
                    with suppress(BlockingIOError):
                        os.write(self.fd, b"\r")
            del self.rx[:cursor - self.cursor]
            self.cursor = cursor
        data = self.rx[:32768]
        return dict(state=self.state, message=self.message, hostname=self.hostname, seen_at=self.seen_at,
                    platform=self.platform, baud=self.baud, frame=self.frame,
                    ready=bool(self.engine and self.engine.ready and self.state == "connected"),
                    smart_ready=bool(self.engine and self.engine.smart_ready and self.state == "connected"),
                    final_progress=self.final_progress,
                    version_command=self.engine.version_command if self.engine else None,
                    transfer=self.engine.snapshot() if self.engine else None,
                    next_cursor=cursor + len(data), data=base64.b64encode(data).decode("ascii"))

    def operation(self, op, body):
        if op == "close":
            self.finish("closed", "Console closed.")
            # The detector may still be inside its final receive window.
            # The worker needs the lease lock to release its tty.
            if not self.released.wait(1.5):
                raise Denied("busy", "Console is closing. Retry the connection shortly.")
            return {}
        with self.lock:
            self.live()
            if self.ended is None:
                try:
                    self.verify()
                except IO_ERRORS:
                    self.finish("closed", "USB console disconnected or unavailable.", "disconnected")
                else:
                    self.last_contact = time.monotonic()
            if op == "poll":
                reply = self.poll(body.get("cursor"))
                self.last_poll = time.monotonic()
                return reply
            require(self.ended is None, "expired")
            require(self.state == "connected" and not self.identify, "conflict")
            if op == "file_page":
                page = body.get("page")
                require(type(page) is int and page >= 0)
                return self.engine.page(page)
            require(op in ("write", "file_preview", "file_action"))
            seq = body.get("seq")
            require(type(seq) is int and seq == self.seq + 1, "conflict")
            self.seq = seq
            try:
                self.mutate(op, body)
            except Denied as exc:
                exc.consumed = True
                raise
            except UnicodeError:
                raise Denied("invalid", consumed=True) from None
            except ValueError as exc:
                raise Denied("invalid", str(exc), consumed=True) from None
            return {}

    def mutate(self, op, body):
        self.wake_until = None
        if op == "write":
            data = body.get("data")
            require(isinstance(data, str) and 0 < len(data) <= 5464)
            try:
                raw = base64.b64decode(data, validate=True)
            except ValueError:
                raise Denied("invalid") from None
            require(0 < len(raw) <= 4096)
            self.engine.manual(raw)
            if len(self.pending) + len(raw) > TX_LIMIT:
                self.finish("closed", "Keyboard input exceeded the output buffer.")
                raise Denied("expired")
            if not self.pending:
                self.last_write = time.monotonic()
            self.pending.extend(raw)
            self.file_tx = False
        elif op == "file_preview":
            text, name = body.get("text"), body.get("filename")
            require(isinstance(text, str) and len(text.encode()) <= 1048576)
            require(isinstance(name, str) and 0 < len(name) <= 255)
            require(not self.pending, "conflict")
            self.engine.preview(text, name, self.frame)
        else:
            action, mode = body.get("action"), body.get("mode", "smart")
            value, default = body.get("value", ""), body.get("allow_default", False)
            require(isinstance(action, str) and isinstance(value, str) and len(value) <= 16384)
            require(mode in ("bulk", "smart", "step") and type(default) is bool)
            require(not self.pending or action in ("stop", "clear", "pause"), "conflict")
            self.engine.action(action, time.monotonic(), value=value, mode=mode, allow_default=default)
            if action in ("stop", "clear") and self.file_tx:
                self.pending.clear()
                if self.fd is not None:
                    with suppress(*IO_ERRORS):
                        termios.tcflush(self.fd, termios.TCOFLUSH)


class Broker:
    def __init__(self, inventory=cli.inventory, claim=claim_port, busy=port_busy):
        self.read_inventory, self.claim, self.busy = inventory, claim, busy
        self.ambiguous = set()
        self.identity_lock = threading.Lock()
        self.lock = threading.RLock()
        self.leases = {}
        self.revoked = {}
        self.revoke_overflow_until = 0
        self.observed = {}
        self.observed_lock = threading.Lock()

    def inventory(self):
        lines = self.read_inventory()
        counts = Counter((line.serial, line.interface) for line in lines if line.serial)
        result = []
        with self.identity_lock:
            for line in lines:
                if line.serial:
                    identity = (line.serial, line.interface)
                    marker = "duplicate-" + hashlib.sha256(json.dumps(identity).encode()).hexdigest()
                    if counts[identity] > 1:
                        self.ambiguous.add(identity)
                        if not (cli.CACHE / marker).is_file():
                            with suppress(OSError, cli.Unavailable):
                                cli.write_cache_entry(cli.CACHE, marker, True)
                    # Once two cables claimed this serial, neither may inherit its saved consent.
                    if identity in self.ambiguous or (cli.CACHE / marker).is_file():
                        line = replace(line, by_id=None)
                result.append(line)
        return result

    @staticmethod
    def hint_key(line):
        return "web-" + hashlib.sha256(line.by_id.encode()).hexdigest()

    def line_hint(self, line):
        with self.observed_lock:
            previous = self.observed.get(generation(line))
            if previous:
                return previous[2:]
        if line.by_id:
            with suppress(OSError, ValueError, cli.Unavailable):
                cli.private_directory(cli.CACHE)
                with (cli.CACHE / self.hint_key(line)).open() as source:
                    entry = json.loads(source.read(128))
                if isinstance(entry, dict):
                    baud, frame = entry.get("baud"), entry.get("frame")
                    if type(baud) is int and baud in cli.RATES and isinstance(frame, str) and re.fullmatch("[5-8][neo][12]", frame):
                        return baud, frame
        return 9600, "8n1"

    def observe(self, line, host, seen, baud, frame):
        with self.observed_lock:
            gen = generation(line)
            previous = self.observed.pop(gen, None)
            if host is None and previous:
                host, seen = previous[:2]
            self.observed[gen] = host, seen, baud, frame
            if len(self.observed) > 128:
                del self.observed[next(iter(self.observed))]
            if line.by_id and (not previous or previous[2:] != (baud, frame)):
                with suppress(OSError, cli.Unavailable):
                    cli.write_cache_entry(cli.CACHE, self.hint_key(line), {"baud": baud, "frame": frame})

    def sweep(self, now):
        for key, (_, lease) in list(self.leases.items()):
            with lease.lock:
                if lease.released.is_set() and now - lease.ended >= RETAIN_SECONDS:
                    del self.leases[key]
        self.revoked = {user: until for user, until in self.revoked.items() if until > now}

    def list_ports(self):
        lines = self.inventory()
        ports = []
        for line in lines:
            port = line.by_id or line.by_path
            if not port:
                continue
            gen = generation(line)
            occupied = False
            with self.lock:
                for _, lease in self.leases.values():
                    if lease.line != line:
                        continue
                    with lease.lock:
                        occupied |= not lease.released.is_set()
            with self.observed_lock:
                host, seen = self.observed.get(gen, (None, None))[:2]
            ports.append(dict(id=port, generation=gen, usb=Path(line.usb_path).name,
                              driver=line.driver, busy=occupied or self.busy(line), hostname=host, seen_at=seen))
        return {"ports": ports}

    def open(self, owner, body):
        port, gen = body.get("port"), body.get("generation")
        mode, baud, frame = body.get("mode"), body.get("baud"), body.get("frame")
        require(isinstance(port, str) and len(port) <= 263 and token(gen))
        require(mode in ("connect", "identify"))
        wake = body.get("wake")
        require("wake" not in body or type(wake) is bool)
        require(mode == "connect" or not wake)
        require(baud == "auto" or type(baud) is int and baud in cli.RATES)
        require(frame == "auto" or isinstance(frame, str) and re.fullmatch("[5-8][neo][12]", frame))
        line = cli.select_line(self.inventory(), port)
        require(generation(line) == gen, "unavailable")
        require(frame == "auto" or line.driver != "ftdi_sio" or frame[0] in "78")
        with self.lock:
            require(owner not in self.revoked and time.monotonic() >= self.revoke_overflow_until, "expired")
            require(all(l.line.rdev != line.rdev or l.released.is_set()
                        for _, l in self.leases.values()), "busy")
            if len(self.leases) >= MAX_LEASES * 2:
                dead = [k for k, (_, l) in self.leases.items() if l.released.is_set()]
                if dead:
                    del self.leases[dead[0]]
            require(sum(not l.released.is_set() for _, l in self.leases.values()) < MAX_LEASES, "busy")
            key = secrets.token_hex(32)
            lease = Lease(self, line, port, mode, baud, frame, key, wake)
            self.leases[key] = owner, lease
            lease.worker.start()
            return {"session": key}

    def dispatch(self, op, body):
        with self.lock:
            self.sweep(time.monotonic())
        if op == "ports":
            return self.list_ports()
        owner = body.get("owner")
        require(token(owner))
        if op == "open":
            return self.open(owner, body)
        with self.lock:
            if op == "close_owner":
                until = time.monotonic() + RETAIN_SECONDS
                if len(self.revoked) < 512 or owner in self.revoked:
                    self.revoked[owner] = until
                else:
                    # Keep delayed opens revoked even under a burst of logouts.
                    self.revoke_overflow_until = until
                for user, lease in self.leases.values():
                    if user == owner:
                        lease.finish("closed", "Login session ended.", "logout")
                return {}
            key = body.get("session")
            require(token(key), "expired")
            entry = self.leases.get(key)
            require(entry and secrets.compare_digest(entry[0], owner), "expired")
            lease = entry[1]
        return lease.operation(op, body)

    def request(self, body):
        try:
            require(isinstance(body, dict) and isinstance(body.get("op"), str))
            return {"ok": True, **self.dispatch(body["op"], body)}
        except Denied as exc:
            return {"ok": False, "error": exc.code, "message": exc.message, "seq_consumed": exc.consumed}
        except cli.Busy:
            return {"ok": False, "error": "busy", "message": ERRORS["busy"]}
        except IO_ERRORS:
            return {"ok": False, "error": "unavailable", "message": ERRORS["unavailable"]}
        except UnicodeError:
            return {"ok": False, "error": "invalid", "message": ERRORS["invalid"]}
        except ValueError as exc:
            # ConsoleProtocol uses static operator-facing validation messages.
            return {"ok": False, "error": "invalid", "message": str(exc)}

    def shutdown(self):
        with self.lock:
            leases = [lease for _, lease in self.leases.values()]
            for lease in leases:
                lease.finish("closed", "Broker stopped.", "shutdown")
        for lease in leases:
            lease.worker.join()


def serve_client(conn, broker):
    with conn:
        try:
            deadline = time.monotonic() + 3
            buf = bytearray()
            while len(buf) <= MAX_WIRE:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise ValueError
                conn.settimeout(remaining)
                block = conn.recv(min(65536, MAX_WIRE + 1 - len(buf)))
                if not block:
                    raise ValueError
                buf.extend(block)
                if b"\n" in block:
                    break
            if len(buf) > MAX_WIRE or not buf.endswith(b"\n"):
                raise ValueError
            reply = broker.request(json.loads(buf.decode("utf-8")))
        except (ValueError, OSError, RecursionError):
            reply = {"ok": False, "error": "invalid", "message": ERRORS["invalid"]}
        with suppress(OSError):
            conn.settimeout(1)
            conn.sendall(json.dumps(reply, ensure_ascii=False, separators=(",", ":")).encode() + b"\n")


def main(socket_path="/run/cmprovision-serial-web.sock"):
    logging.basicConfig(level=logging.INFO, format="%(message)s")
    if os.environ.get("LISTEN_PID") != str(os.getpid()) or os.environ.get("LISTEN_FDS") != "1":
        raise SystemExit("Expected one systemd socket.")
    with socket.socket(fileno=3) as listener:
        if (listener.family != socket.AF_UNIX or listener.type != socket.SOCK_STREAM
                or listener.getsockname() != socket_path
                or not listener.getsockopt(socket.SOL_SOCKET, socket.SO_ACCEPTCONN)):
            raise SystemExit("Unexpected activation socket.")
        listener.set_inheritable(False)
        listener.settimeout(.25)
        broker = Broker()
        stop = threading.Event()
        slots = threading.BoundedSemaphore(8)
        def process(conn):
            try:
                serve_client(conn, broker)
            finally:
                slots.release()
        for sig in (signal.SIGTERM, signal.SIGINT):
            signal.signal(sig, lambda *_: stop.set())
        try:
            while not stop.is_set():
                try:
                    conn, _ = listener.accept()
                except TimeoutError:
                    continue
                if not slots.acquire(blocking=False):
                    conn.close()
                    continue
                threading.Thread(target=process, args=(conn,), daemon=True).start()
        finally:
            # Let all outstanding requests finish before dropping serial sessions.
            for _ in range(8):
                slots.acquire()
            broker.shutdown()


if __name__ == "__main__":
    main()
