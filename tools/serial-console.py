#!/usr/bin/python3 -I
"""Identify a local USB console and listen for a baud rate. Linux only."""
import argparse
from collections import Counter
from contextlib import contextmanager
from dataclasses import dataclass, replace
from datetime import datetime, timezone
import errno
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import select
import shlex
import shutil
import signal
import stat
import struct
import subprocess
import sys
import tempfile
import termios
import time
import tty


RATES = (9600, 115200, 19200, 38400, 57600)
PROFILES = {"cisco": "8n2", "linux": "8n1"}
CACHE = Path("/var/cache/cmprovision-console")


class Busy(Exception):
    pass


class Unavailable(Exception):
    pass


@dataclass(frozen=True)
class Line:
    node: Path
    rdev: int
    driver: str
    usb_path: str
    interface: str
    serial: str
    generation: str
    by_id: str | None
    by_path: str | None


def unique_ids(lines):
    counts = Counter((p.serial, p.interface) for p in lines)
    return [p if p.serial and counts[p.serial, p.interface] == 1 else replace(p, by_id=None)
            for p in lines]


def inventory(sysfs=Path("/sys"), dev=Path("/dev")):
    aliases = {}
    for kind in ("by-id", "by-path"):
        for link in sorted((dev / "serial" / kind).glob("*")):
            if link.is_symlink() and link.name.isascii() and link.name.isprintable():
                aliases.setdefault((link.resolve(), kind), f"{kind}/{link.name}")
    lines = []
    for tty in sorted((sysfs / "class/tty").glob("ttyUSB[0-9]*")):
        if not re.fullmatch(r"ttyUSB[0-9]+", tty.name):
            continue
        try:
            node = dev / tty.name
            info = node.lstat()
            address = f"{os.major(info.st_rdev)}:{os.minor(info.st_rdev)}"
            if not stat.S_ISCHR(info.st_mode) or (tty / "dev").read_text().strip() != address:
                continue
            device = (tty / "device").resolve(strict=True)
            if not device.is_relative_to(sysfs / "devices"):
                continue
            driver = (device / "driver").resolve(strict=True).name
            if driver not in ("ftdi_sio", "pl2303"):
                continue
            interface = next(p for p in device.parents if (p / "bInterfaceNumber").is_file())
            usb = next(p for p in interface.parents if (p / "idVendor").is_file())
            port = device / "port_number"
            channel = (interface / "bInterfaceNumber").read_text().strip()
            if port.is_file():
                channel += "." + port.read_text().strip()
            serial = (usb / "serial").read_text().strip() if (usb / "serial").is_file() else ""
            generation = (usb / "devnum").read_text().strip()
            lines.append(Line(node, info.st_rdev, driver, str(usb), channel, serial, generation,
                              aliases.get((node, "by-id")), aliases.get((node, "by-path"))))
        except (OSError, UnicodeError, StopIteration):
            continue  # A hot-unplug can remove any part of the sysfs tree.
    return unique_ids(lines)


def select_line(lines, identifier):
    matches = [line for line in lines if identifier and identifier in (line.by_id, line.by_path)]
    if len(matches) != 1:
        raise Unavailable("choose a current, unique by-id or by-path entry from list")
    return matches[0]


def command(args):
    try:
        return subprocess.run(args, check=True, capture_output=True, text=True,
                              timeout=2).stdout.strip()
    except (OSError, subprocess.SubprocessError, UnicodeError) as exc:
        raise Unavailable("could not verify station services") from exc


def reserved_services(line, udev=Path("/run/udev/data"), active_console=Path("/sys/class/tty/console/active")):
    properties = (udev / f"c{os.major(line.rdev)}:{os.minor(line.rdev)}").read_text()
    if re.search(r"^E:ID_MM_(?:PORT_TYPE_[A-Z0-9_]+|DEVICE_PROCESS)=1$", properties, re.M):
        raise Busy("ModemManager port")
    if line.node.name in active_console.read_text().split():
        raise Busy("kernel console")
    for prefix in ("serial-getty", "getty"):
        state = command(["/usr/bin/systemctl", "show", "-p", "ActiveState,UnitFileState",
                         f"{prefix}@{line.node.name}.service"]).splitlines()
        if "ActiveState=inactive" not in state or any(s.startswith("UnitFileState=enabled") for s in state):
            raise Busy("reserved getty")
    state = command(["/usr/bin/systemctl", "show", "-p", "ActiveState", "--value", "ModemManager.service"])
    if state in ("inactive", "failed"):
        return
    if state != "active":
        raise Unavailable("modem service is changing state")
    try:
        modems = json.loads(command(["/usr/bin/mmcli", "-L", "-J"]))["modem-list"]
        if not isinstance(modems, list):
            raise ValueError
        for modem in modems:
            if not isinstance(modem, str) or not re.fullmatch(r"/org/freedesktop/ModemManager1/Modem/\d+", modem):
                raise ValueError
            info = json.loads(command(["/usr/bin/mmcli", "-m", modem, "-J"]))["modem"]["generic"]
            ports = info["ports"]
            if not isinstance(ports, list) or not all(isinstance(p, str) and p.split() for p in ports):
                raise ValueError
            if any(p.split()[0] == line.node.name for p in ports):
                raise Busy("assigned to a modem")
    except (KeyError, TypeError, ValueError) as exc:
        raise Unavailable("could not verify modem ports") from exc


def check_owners(rdev, proc, own_fd=None):
    try:
        for process in proc.iterdir():
            if not process.name.isdecimal():
                continue
            try:
                for entry in (process / "fd").iterdir():
                    if process.name == str(os.getpid()) and entry.name == str(own_fd):
                        continue
                    try:
                        info = entry.stat()
                    except FileNotFoundError:
                        continue
                    if stat.S_ISCHR(info.st_mode) and info.st_rdev == rdev:
                        raise Busy("another process has the terminal open")
            except (FileNotFoundError, ProcessLookupError):
                continue
    except OSError as exc:
        raise Unavailable("cannot verify all terminal owners") from exc


def private_directory(folder):
    folder.mkdir(mode=0o700, exist_ok=True)
    info = folder.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
        raise Unavailable("unsafe console state directory")


@contextmanager
def claim(line, refresh, policies=reserved_services, proc=Path("/proc"),
          locks=Path("/run/lock"), runtime=Path("/run/cmprovision-console")):
    private_directory(runtime)
    name = f"{os.major(line.rdev)}-{os.minor(line.rdev)}"
    with (runtime / name).open("a") as guard:
        try:
            fcntl.flock(guard, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as exc:
            raise Busy("another detector is using the terminal") from exc
        policies(line)
        names = [line.node.name] + [Path(alias).name for alias in (line.by_id, line.by_path) if alias]
        if any(os.path.lexists(locks / ("LCK.." + name)) for name in names):
            raise Busy("terminal lock exists")
        check_owners(line.rdev, proc)
        if refresh() != line:
            raise Unavailable("adapter changed before opening the terminal")
        try:
            fd = os.open(line.node, os.O_RDONLY | os.O_NOCTTY | os.O_NONBLOCK | os.O_NOFOLLOW)
        except OSError as exc:
            if exc.errno == errno.EBUSY:
                raise Busy("terminal is exclusive") from exc
            raise
        try:
            opened = os.fstat(fd)
            if not stat.S_ISCHR(opened.st_mode) or opened.st_rdev != line.rdev or refresh() != line:
                raise Unavailable("adapter changed during discovery")
            # TIOCGEXCL is present in Linux asm-generic but absent in some Python builds.
            exclusive = fcntl.ioctl(fd, getattr(termios, "TIOCGEXCL", 0x80045440), bytes(4))
            if struct.unpack("i", exclusive)[0]:
                raise Busy("another terminal holds exclusive access")
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as exc:
                raise Busy("terminal is locked") from exc
            fcntl.ioctl(fd, termios.TIOCEXCL)
            try:
                check_owners(line.rdev, proc, own_fd=fd)
                original = termios.tcgetattr(fd)
                try:
                    yield fd
                finally:
                    termios.tcsetattr(fd, termios.TCSANOW, original)
            finally:
                fcntl.ioctl(fd, termios.TIOCNXCL)
        finally:
            os.close(fd)


def set_frame(fd, baud, frame):
    attrs = termios.tcgetattr(fd)
    attrs[0] = attrs[1] = attrs[3] = 0
    attrs[2] &= ~(termios.CSIZE | termios.PARENB | termios.PARODD | termios.CSTOPB |
                  termios.CRTSCTS | getattr(termios, "CMSPAR", 0))
    attrs[2] |= termios.CLOCAL | termios.CREAD | getattr(termios, "CS" + frame[0])
    if frame[1] != "n":
        attrs[2] |= termios.PARENB
        if frame[1] == "o":
            attrs[2] |= termios.PARODD
    if frame[2] == "2":
        attrs[2] |= termios.CSTOPB
    attrs[4] = attrs[5] = getattr(termios, "B" + str(baud))
    attrs[6][termios.VMIN] = attrs[6][termios.VTIME] = 0
    termios.tcsetattr(fd, termios.TCSANOW, attrs)
    actual = termios.tcgetattr(fd)
    frame_bits = (termios.CSIZE | termios.PARENB | termios.PARODD | termios.CSTOPB |
                  termios.CRTSCTS | getattr(termios, "CMSPAR", 0))
    if (actual[4:6] != attrs[4:6] or (actual[2] ^ attrs[2]) & frame_bits or
            actual[0] & (termios.IXON | termios.IXOFF) or
            actual[3] & (termios.ECHO | termios.ECHONL | termios.ICANON)):
        raise Unavailable("driver did not apply the requested serial settings")


def read_window(fd, baud, frame, end):
    set_frame(fd, baud, frame)
    termios.tcflush(fd, termios.TCIFLUSH)
    time.sleep(min(0.15, max(0.0, end - time.monotonic())))
    termios.tcflush(fd, termios.TCIFLUSH)  # Discard late USB transfers at the old speed.
    poller = select.poll()
    poller.register(fd, select.POLLIN)
    data = bytearray()
    while len(data) < 4096:
        remaining = end - time.monotonic()
        if remaining <= 0:
            break
        for _, event in poller.poll(max(1, int(remaining * 1000))):
            if event & (select.POLLERR | select.POLLHUP | select.POLLNVAL):
                raise Unavailable("USB console disconnected")
            try:
                chunk = os.read(fd, 4096 - len(data))
            except BlockingIOError:
                continue
            if not chunk:
                raise Unavailable("USB console closed")
            data.extend(chunk)
    return bytes(data)


CONSOLE_TEXT = re.compile(
    rb"(?:[A-Za-z][A-Za-z0-9_.-]{1,62}(?:\([A-Za-z0-9_-]{1,32}\))?[>#]"
    rb"|[A-Za-z0-9_.-]{1,64} login:"
    rb"|Linux version \d+\.\d+[ -~]*"
    rb"|\[\s*\d+\.\d+\]\s+[A-Za-z0-9][ -~]{5,}"
    rb"|U-Boot (?:SPL )?20\d{2}\.\d{2}(?:[-+.][A-Za-z0-9._+-]+)?(?: [ -~]+)?"
    rb"|(?:Cisco IOS|systemd\[\d+\]:) [ -~]{4,}"
    rb"|(?:[A-Za-z0-9 *.:-]+:\s+)?%[A-Z0-9_]+-[0-7]-[A-Z0-9_]+: [ -~]{4,})")


def recognizable(data):
    clean = re.sub(rb"\x1b\[[0-9;]{0,32}m", b"", data[:4096])
    noise = sum(byte not in b"\t\n\r" and not 32 <= byte <= 126 for byte in clean)
    if noise * 20 > len(clean):
        return False
    return any(CONSOLE_TEXT.fullmatch(line.strip(b" \t")) for line in clean.splitlines()[:32])


@dataclass(frozen=True)
class Result:
    status: str
    baud: int | None = None


def detect_speed(sample, hint=None):
    end = time.monotonic() + 12.0
    candidates = []
    any_bytes = False
    def observe(rate):
        now = time.monotonic()
        if now >= end:
            raise Unavailable("detection timed out")
        return sample(rate, min(end, now + 1.5))
    order = [hint] if hint in RATES else []
    for rate in dict.fromkeys([*order, *RATES]):
        data = observe(rate)
        any_bytes |= bool(data)
        if recognizable(data):
            candidates.append(rate)
    if len(candidates) == 1:
        rate = candidates[0]
        return Result("detected" if recognizable(observe(rate)) else "inconclusive", rate)
    return Result("inconclusive" if any_bytes else "silent")


def cache_key(line, profile, target):
    fields = (line.usb_path, line.interface, line.driver, line.serial, profile, target)
    return hashlib.sha256(json.dumps(fields).encode()).hexdigest()


def last_known(line, profile, target, folder=CACHE):
    if not target:
        return None
    try:
        private_directory(folder)
        entry = json.loads((folder / cache_key(line, profile, target)).read_text())
        stamp = datetime.fromisoformat(entry["at"])
        if entry["baud"] in RATES and stamp.tzinfo is not None:
            return entry["baud"], stamp.isoformat(timespec="seconds")
    except (OSError, KeyError, TypeError, ValueError, Unavailable):
        pass
    return None


def remember(line, profile, target, baud, folder=CACHE):
    if target:
        private_directory(folder)
        with tempfile.NamedTemporaryFile("w", dir=folder, delete=False) as output:
            path = Path(output.name)
            try:
                json.dump({"baud": baud, "at": datetime.now(timezone.utc).isoformat()}, output)
                output.flush()
                path.replace(folder / cache_key(line, profile, target))
            finally:
                path.unlink(missing_ok=True)


def picocom_arguments(path, baud, frame):
    return ["picocom", "--baud", str(baud), "--databits", frame[0],
            "--parity", frame[1], "--stopbits", frame[2], "--flow", "n", str(path)]


def wait_for_speed(fd, frame, hint, output=1):
    """Keep ownership during silence; fresh RX is the trigger for another scan."""
    poller = select.poll()
    poller.register(fd, select.POLLIN)
    poller.register(output, 0)  # Caller gone: HUP/ERR still wake a silent line.
    while True:
        print("Listening for the baud rate (Ctrl+C to exit)…", file=sys.stderr, flush=True)
        result = detect_speed(lambda rate, end: read_window(fd, rate, frame, end), hint)
        if result.status == "detected":
            return result
        print(f"{result.status}: waiting for more console output…", file=sys.stderr, flush=True)
        set_frame(fd, hint or RATES[0], frame)
        for ready, events in poller.poll():
            if ready == output:
                raise Unavailable("operator session closed")
            if events & (select.POLLHUP | select.POLLNVAL | select.POLLERR):
                raise Unavailable("USB console disconnected")


class SerialNoise:
    """Two adjacent noisy windows; receive packet boundaries have no meaning."""
    def __init__(self):
        self.pending = bytearray()
        self.previous_noisy = False

    def feed(self, data):
        self.pending.extend(data)
        while len(self.pending) >= 24:
            block = self.pending[:24].decode("utf-8", errors="replace")
            del self.pending[:24]
            bad = sum(c == "\ufffd" or ord(c) < 32 and c not in "\r\n\t\b\a\f\x1b" for c in block)
            noisy = bad > 12
            if self.previous_noisy and noisy:
                return True
            self.previous_noisy = noisy
        return False


def write_all(fd, data):
    view = memoryview(data)
    while view:
        view = view[os.write(fd, view):]


def picocom_session(path, baud, frame, keyboard=0, screen=1):
    """Relay our own unprivileged picocom. True requests a new probe."""
    args = picocom_arguments(path, baud, frame)
    args[1:1] = ["--quiet"]
    settings = termios.tcgetattr(keyboard)
    master, terminal = os.openpty()
    child = None
    try:
        tty.setraw(terminal)
        tty.setraw(keyboard)
        child = subprocess.Popen(args, stdin=terminal, stdout=terminal, stderr=terminal,
                                 start_new_session=True)
        os.close(terminal)
        terminal = None
        noise = SerialNoise()
        while True:
            readable, _, _ = select.select([master, keyboard], [], [])
            if master in readable:
                try:
                    data = os.read(master, 4096)
                except OSError as exc:
                    if exc.errno != errno.EIO:
                        raise
                    data = b""
                if not data:
                    if child.wait() != 0:
                        raise Unavailable("picocom closed with an error")
                    return False
                if noise.feed(data):
                    return True
                write_all(screen, data)
            if keyboard in readable:
                keys = os.read(keyboard, 4096)
                if not keys:
                    return False
                if b"\x1d" in keys:  # Ctrl+] is local, including during a paste.
                    return True
                write_all(master, keys)
    finally:
        try:
            if child is not None and child.poll() is None:
                child.terminate()
                try:
                    child.wait(timeout=2)
                except subprocess.TimeoutExpired:
                    child.kill()
                    child.wait()
        finally:
            os.close(master)
            if terminal is not None:
                os.close(terminal)
            termios.tcsetattr(keyboard, termios.TCSAFLUSH, settings)


def connect(line, port, profile, frame):
    if os.geteuid() == 0:
        raise Unavailable("run connect from your operator account without sudo")
    if not os.isatty(0) or not os.isatty(1):
        raise Unavailable("connect requires an interactive terminal")
    if not shutil.which("picocom"):
        raise Unavailable("install the Debian picocom package")
    command = ["/usr/bin/sudo", "/usr/bin/cmprovision-console", "detect", "--wait",
               "--port", port, "--profile", profile]
    while True:
        if select_line(inventory(), port) != line:
            raise Unavailable("USB adapter changed; select it again")
        try:
            probe = subprocess.run(command, stdout=subprocess.PIPE)
        finally:
            termios.tcflush(0, termios.TCIFLUSH)  # Never replay input typed during detection.
        if probe.returncode:
            return 1
        speeds = {str(rate).encode(): rate for rate in RATES}
        baud = speeds.get(probe.stdout.strip())
        if not baud or select_line(inventory(), port) != line:
            raise Unavailable("could not confirm the adapter and its speed")
        print(f"Connected at {baud} baud. Ctrl+] to rescan, Ctrl+A then Ctrl+X to exit.",
              file=sys.stderr, flush=True)
        if not picocom_session(Path("/dev/serial") / port, baud, frame):
            return 0


def run(args):
    ports = inventory()
    if args.operation == "list":
        for p in ports:
            if p.by_path or p.by_id:
                print(f"{p.driver}  interface {p.interface}  {p.by_id or '-'}  {p.by_path or '-'}")
        if not any(p.by_path or p.by_id for p in ports):
            print("No supported USB consoles found.")
        return 0
    line = select_line(ports, args.port)
    frame = PROFILES.get(args.profile, args.profile)
    if args.operation == "connect":
        return connect(line, args.port, args.profile, frame)
    if os.geteuid() != 0:
        raise Unavailable("detect requires a station administrator (sudo)")
    if line.driver == "ftdi_sio" and frame[0] not in "78":
        raise Unavailable("FTDI UARTs require 7 or 8 data bits")
    refresh = lambda: select_line(inventory(), args.port)
    known = last_known(line, args.profile, args.target)
    with claim(line, refresh) as fd:
        hint = known[0] if known else None
        if getattr(args, "wait", False):
            result = wait_for_speed(fd, frame, hint)
        else:
            result = detect_speed(lambda baud, end: read_window(fd, baud, frame, end), hint)
        if refresh() != line:
            raise Unavailable("adapter changed during detection")
    waiting = getattr(args, "wait", False)
    if result.status == "detected":
        try:
            remember(line, args.profile, args.target, result.baud)
        except (OSError, Unavailable):
            print("Could not save the optional speed hint.", file=sys.stderr if waiting else sys.stdout)
    if waiting:
        print(result.baud)
        return 0
    print(result.status)
    print(f"Selected frame {frame.upper()}, flow control off")
    if known:
        print(f"Previous confirmation {known[0]} baud at {known[1]}")
    if result.status == "detected":
        print(f"Confirmed {result.baud} baud in two receive windows")
    elif result.baud:
        print(f"Possible {result.baud} baud from one window")
    elif result.status == "silent":
        print("No bytes were read in the sampling windows.")
    speed = result.baud or (known[0] if known else (9600 if args.profile == "cisco" else 115200))
    print("Manual session:", shlex.join(picocom_arguments(Path("/dev/serial") / args.port, speed, frame)))
    if not shutil.which("picocom"):
        print("Install the Debian picocom package to open the manual session.")
    return 0 if result.status == "detected" else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="operation", required=True)
    commands.add_parser("list", help="list stable local USB console identifiers")
    detect = commands.add_parser("detect", help="listen to one selected console")
    session = commands.add_parser("connect", help="open picocom and automatically recheck its baud rate")
    for sub in (detect, session):
        sub.add_argument("--port", required=True, help="by-id/... or by-path/... from list")
        sub.add_argument("--profile", default="linux", help="linux (8N1), cisco (8N2), or an explicit frame")
    detect.add_argument("--wait", action="store_true", help="wait for fresh data and print the confirmed baud rate")
    detect.add_argument("--target", help="optional equipment label for a line-specific speed hint")
    args = parser.parse_args()
    if args.operation in ("detect", "connect"):
        args.profile = args.profile.lower()
        if args.profile not in PROFILES and not re.fullmatch(r"[5-8][neo][12]", args.profile):
            parser.error("unknown framing profile")
        if getattr(args, "target", None) and not re.fullmatch(r"[A-Za-z0-9_.-]{1,64}", args.target):
            parser.error("invalid target label")
        def interrupt(signum, _frame):
            raise Unavailable("detection timed out" if signum == signal.SIGALRM else "detection interrupted")
        for signum in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP, signal.SIGALRM):
            signal.signal(signum, interrupt)
        if args.operation == "detect" and not args.wait:
            signal.setitimer(signal.ITIMER_REAL, 12)
    try:
        return run(args)
    except Busy as exc:
        print("busy:", exc, file=sys.stderr if getattr(args, "wait", False) else sys.stdout)
    except Unavailable as exc:
        print("unavailable:", exc, file=sys.stderr if getattr(args, "wait", False) else sys.stdout)
    except (OSError, termios.error, UnicodeError):
        print("unavailable: could not verify or read the selected terminal",
              file=sys.stderr if getattr(args, "wait", False) else sys.stdout)
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
