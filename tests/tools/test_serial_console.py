import contextlib
import importlib.util
import io
import grp
import json
import os
from pathlib import Path
import pwd
import select
import signal
import shutil
import subprocess
import sys
import tempfile
import termios
import threading
import time
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[2] / "tools/serial-console.py"
console = None
if SCRIPT.exists():
    spec = importlib.util.spec_from_file_location("serial_console", SCRIPT)
    console = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = console
    spec.loader.exec_module(console)


class SerialConsoleTest(unittest.TestCase):
    def test_connect_exists_as_an_operator_command(self):
        result = subprocess.run([sys.executable, SCRIPT, "connect", "--help"], capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(b"--port", result.stdout)

    def test_sustained_invalid_input_requests_redetection_but_console_text_does_not(self):
        noise = console.SerialNoise()
        for chunk in (b"switch#\r\n" * 20, "состояние сети\r\n".encode() * 8,
                      b"\x1b[31mERROR\x1b[0m\r\n" * 8, b"\xff" * 24, b"normal output\r\n" * 10):
            self.assertFalse(noise.feed(chunk))
        self.assertTrue(any(noise.feed(b"\xff\x00") for _ in range(48)))

    def test_waiting_probe_retries_after_new_data_and_returns_only_confirmed_speed(self):
        replies = iter([console.Result("inconclusive", 9600), console.Result("detected", 115200)])
        with self.claim() as fd:
            def scan(sample, hint):
                result = next(replies)
                if result.status == "inconclusive":
                    os.write(self.master, b"new-device\r\n")
                return result
            with patch.object(console, "detect_speed", side_effect=scan):
                result = console.wait_for_speed(fd, "8n1", None)
            self.assertEqual(result, console.Result("detected", 115200))

    def test_waiting_probe_releases_silent_line_when_operator_disappears(self):
        reader, output = os.pipe()
        os.close(reader)
        self.addCleanup(os.close, output)
        with self.claim() as fd, patch.object(console, "detect_speed", return_value=console.Result("silent")):
            with self.assertRaisesRegex(console.Unavailable, "session closed"):
                console.wait_for_speed(fd, "8n1", None, output=output)

    @unittest.skipUnless(shutil.which("picocom"), "picocom is required")
    def test_one_operator_session_reopens_real_picocom_after_new_device_output(self):
        control, keyboard = os.openpty()
        self.addCleanup(os.close, control)
        self.addCleanup(os.close, keyboard)
        original = termios.tcgetattr(keyboard)
        account = pwd.getpwnam("nobody") if os.geteuid() == 0 else None
        if account:
            os.fchown(self.slave, account.pw_uid, account.pw_gid)
        pid = os.fork()
        if pid == 0:
            try:
                for target in (0, 1, 2):
                    os.dup2(keyboard, target)
                if account:
                    os.setgroups([])
                    os.setgid(account.pw_gid)
                    os.setuid(account.pw_uid)
                def probe(cmd, stdout):
                    assert cmd[:4] == ["/usr/bin/sudo", "/usr/bin/cmprovision-console", "detect", "--wait"]
                    fd = os.open(self.node, os.O_RDONLY | os.O_NONBLOCK | os.O_NOCTTY)
                    saved = termios.tcgetattr(fd)
                    try:
                        result = console.wait_for_speed(fd, "8n2", None)
                    finally:
                        termios.tcsetattr(fd, termios.TCSANOW, saved)
                        os.close(fd)
                    return subprocess.CompletedProcess(cmd, 0, str(result.baud).encode())
                # The station's sudo boundary and USB inventory are represented by local PTYs.
                relay = console.picocom_session
                with patch.object(console, "inventory", return_value=[self.line]), \
                        patch.object(subprocess, "run", side_effect=probe), \
                        patch.object(console, "picocom_session", lambda path, baud, frame: relay(self.node, baud, frame)):
                    code = console.connect(self.line, self.line.by_id, "cisco", "8n2")
            except BaseException:
                import traceback
                traceback.print_exc()
                code = 2
            os._exit(code)
        finished = False
        stop = threading.Event()
        source_rate = termios.B9600
        def target():
            # A PTY normally delivers bytes at every speed. Model output decoded at the target rate.
            while not stop.wait(0.05):
                if source_rate and termios.tcgetattr(self.slave)[4] == source_rate:
                    os.write(self.master, b"target-switch#\r\n")
        sender = threading.Thread(target=target)
        sender.start()
        def receive(fd, needle, announce=False):
            seen = bytearray()
            end = time.monotonic() + 16
            while needle not in seen and time.monotonic() < end:
                if announce:
                    os.write(self.master, b"sw-console#\r\n")
                if select.select([fd], [], [], 0.1)[0]:
                    seen.extend(os.read(fd, 4096))
            self.assertIn(needle, seen)
        try:
            for baud in (9600, 115200, 38400):
                receive(control, f"Connected at {baud} baud".encode())
                source_rate = None
                end = time.monotonic() + 5
                while termios.tcgetattr(self.slave)[4] != getattr(termios, f"B{baud}") and time.monotonic() < end:
                    time.sleep(0.01)
                self.assertEqual(termios.tcgetattr(self.slave)[4], getattr(termios, f"B{baud}"))
                receive(control, b"sw-console#", announce=True)
                os.write(control, b"hello")
                receive(self.master, b"hello")
                if baud == 9600:
                    source_rate = termios.B115200
                    os.write(self.master, b"\xff\x00" * 80)
                elif baud == 115200:
                    source_rate = termios.B38400
                    os.write(control, b"\x1d")
                    if select.select([self.master], [], [], 0.1)[0]:
                        self.assertNotIn(b"\x1d", os.read(self.master, 4096))
            os.write(control, b"\x01\x18")
            end = time.monotonic() + 5
            while time.monotonic() < end:
                done, status = os.waitpid(pid, os.WNOHANG)
                if done:
                    finished = True
                    self.assertEqual(os.waitstatus_to_exitcode(status), 0)
                    break
                time.sleep(0.01)
            self.assertTrue(finished)
            self.assertEqual(termios.tcgetattr(keyboard), original)
        finally:
            stop.set()
            sender.join()
            if not finished:
                os.kill(pid, signal.SIGTERM)
                os.waitpid(pid, 0)

    def setUp(self):
        self.assertIsNotNone(console, "The station has no USB console command")
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.proc = self.root / "proc"
        self.fds = self.proc / str(os.getpid()) / "fd"
        self.fds.mkdir(parents=True)
        self.master, self.slave = os.openpty()
        self.addCleanup(os.close, self.master)
        self.addCleanup(os.close, self.slave)
        self.node = Path(os.ttyname(self.slave))
        self.line = console.Line(self.node, os.fstat(self.slave).st_rdev, "ftdi_sio",
                                 "/sys/devices/usb1/1-2", "00", "adapter", "4",
                                 "by-id/usb-test", "by-path/platform-test")

    def claim(self, **options):
        args = dict(refresh=lambda: self.line, policies=lambda line: None,
                    proc=self.proc, locks=self.root, runtime=self.root / "guard")
        return console.claim(self.line, **(args | options))

    def test_current_reader_keeps_line_and_termios(self):
        original = termios.tcgetattr(self.slave)
        (self.fds / str(self.slave)).symlink_to(self.node)
        with self.assertRaises(console.Busy), self.claim():
            self.fail("existing terminal lost ownership")
        self.assertEqual(termios.tcgetattr(self.slave), original)
        os.write(self.master, b"hello\n")
        self.assertEqual(os.read(self.slave, 24), b"hello\n")

    def test_uucp_lock_and_reserved_services_refuse_before_open(self):
        marker = self.root / ("LCK.." + self.node.name)
        marker.touch()
        with self.assertRaises(console.Busy), self.claim():
            self.fail("picocom lock ignored")
        marker.unlink()
        def reserved(line):
            raise console.Busy("reserved by getty")
        with self.assertRaises(console.Busy), self.claim(policies=reserved):
            self.fail("guest reader reached a reserved terminal")

    def test_kernel_getty_and_modem_ports_are_reserved(self):
        address = f"c{os.major(self.line.rdev)}:{os.minor(self.line.rdev)}"
        metadata, active = self.root / address, self.root / "active"
        active.write_text("")
        metadata.write_text("E:ID_MM_PORT_TYPE_AT_PRIMARY=1\n")
        def check():
            return console.reserved_services(self.line, udev=self.root, active_console=active)
        with self.assertRaises(console.Busy):
            check()
        metadata.write_text("E:ID_MM_CANDIDATE=1\n")
        active.write_text(self.node.name)
        with self.assertRaises(console.Busy):
            check()
        active.write_text("")
        idle = "ActiveState=inactive\nUnitFileState=disabled"
        with patch.object(console, "command", return_value="ActiveState=inactive\nUnitFileState=enabled"):
            with self.assertRaises(console.Busy):
                check()
        managed = json.dumps({"modem-list": ["/org/freedesktop/ModemManager1/Modem/0"]})
        modem = json.dumps({"modem": {"generic": {"ports": [self.node.name + " (at)"]}}})
        with patch.object(console, "command", side_effect=[idle, idle, "active", managed, modem]):
            with self.assertRaises(console.Busy):
                check()
        with patch.object(console, "command", side_effect=[idle, idle, "active", "garbled"]):
            with self.assertRaises(console.Unavailable):
                check()
        with patch.object(console, "command", side_effect=[idle, idle, "inactive"]):
            check()

    def test_owner_arriving_after_open_prevents_first_line_change(self):
        checks = 0
        def late_owner():
            nonlocal checks
            checks += 1
            if checks == 2:
                (self.fds / str(self.slave)).symlink_to(self.node)
            return self.line
        with self.assertRaises(console.Busy), self.claim(refresh=late_owner):
            self.fail("late owner was ignored")

    def test_exclusive_session_is_released_after_error_and_restores_attributes(self):
        original = termios.tcgetattr(self.slave)
        with self.assertRaises(InterruptedError):
            with self.claim() as fd:
                console.set_frame(fd, 115200, "8n1")
                self.assertEqual(termios.tcgetattr(fd)[4], termios.B115200)
                self.assertFalse(termios.tcgetattr(fd)[3] & termios.ECHO)
                self.assertFalse(termios.tcgetattr(fd)[0] & (termios.IXON | termios.IXOFF))
                with self.assertRaises(console.Busy), self.claim():
                    self.fail("second detector entered")
                raise InterruptedError("cancel")
        self.assertEqual(termios.tcgetattr(self.slave), original)
        with self.claim():
            pass

    def test_replug_changed_identity_and_invisible_proc_refuse(self):
        from dataclasses import replace
        with self.assertRaises(console.Unavailable), self.claim(refresh=lambda: replace(self.line, generation="5")):
            self.fail("stale USB identity accepted")
        with self.assertRaises(console.Unavailable), self.claim(proc=self.root / "missing"):
            self.fail("ownership was not established")

    def test_replaced_adapter_is_rejected_before_opening_new_terminal(self):
        from dataclasses import replace
        with patch.object(console.os, "open", wraps=os.open) as opened:
            with self.assertRaises(console.Unavailable), self.claim(refresh=lambda: replace(self.line, generation="12")):
                self.fail("replaced terminal opened")
            self.assertFalse(any(call.args[0] == self.node for call in opened.call_args_list))

    def test_stable_selection_excludes_colliding_serials_and_arbitrary_paths(self):
        from dataclasses import replace
        sibling = replace(self.line, usb_path="other", by_id=None, by_path="by-path/other")
        ports = console.unique_ids([self.line, sibling])
        for invalid in ("by-id/usb-test", "/dev/ttyUSB0", "../ttyUSB0"):
            with self.subTest(invalid=invalid), self.assertRaises(console.Unavailable):
                console.select_line(ports, invalid)
        self.assertEqual(console.select_line(ports, "by-path/platform-test").usb_path, self.line.usb_path)
        separate_interface = replace(sibling, interface="01", by_id="by-id/usb-test-if01")
        self.assertEqual(console.unique_ids([self.line, separate_interface])[0].by_id, self.line.by_id)
        no_serial = replace(self.line, serial="")
        self.assertIsNone(console.unique_ids([no_serial])[0].by_id)

    def test_inventory_checks_driver_sysfs_devnum_and_stable_links(self):
        dev, sysfs = self.root / "dev", self.root / "sys"
        dev.mkdir()
        tty = sysfs / "class/tty/ttyUSB1"
        usb = sysfs / "devices/platform/usb1/1-2"
        interface = usb / "1-2:1.0"
        device = interface / "ttyUSB1"
        driver = sysfs / "bus/usb-serial/drivers/ftdi_sio"
        for folder in (tty, device, driver, dev / "serial/by-id", dev / "serial/by-path"):
            folder.mkdir(parents=True)
        (tty / "device").symlink_to(device)
        (device / "driver").symlink_to(driver)
        for path, value in [(usb / "idVendor", "0403"), (usb / "serial", "abc"), (usb / "devnum", "10"),
                            (device / "port_number", "1"), (interface / "bInterfaceNumber", "00")]:
            path.write_text(value)
        rdev = os.fstat(self.slave).st_rdev
        (tty / "dev").write_text(f"{os.major(rdev)}:{os.minor(rdev)}")
        node = dev / "ttyUSB1"
        node.touch()
        (dev / "serial/by-id/usb-test-if00-port1").symlink_to(node)
        (dev / "serial/by-path/test-port1").symlink_to(node)
        lstat = Path.lstat
        with patch.object(Path, "lstat", lambda p: os.fstat(self.slave) if p == node else lstat(p)):
            line, = console.inventory(sysfs, dev)
            self.assertEqual((line.by_id, line.by_path, line.interface, line.generation),
                             ("by-id/usb-test-if00-port1", "by-path/test-port1", "00.1", "10"))
            (tty / "dev").write_text("0:0")
            self.assertFalse(console.inventory(sysfs, dev))
            (tty / "dev").write_text(f"{os.major(rdev)}:{os.minor(rdev)}")
            (device / "driver").unlink()
            driver.rename(driver.with_name("cdc_acm"))
            (device / "driver").symlink_to(driver.with_name("cdc_acm"))
            self.assertFalse(console.inventory(sysfs, dev))

    def test_sampling_discovers_each_speed_only_with_fresh_confirmation(self):
        for speed in (9600, 115200, 19200, 38400, 57600):
            with self.subTest(speed=speed):
                calls = []
                def sample(baud, end):
                    calls.append(baud)
                    return b"\x1b[32mLinux version 6.12.2-arm64\x1b[0m\r\n" if baud == speed else b"\xfe\0>>"
                report = console.detect_speed(sample, hint=38400)
                self.assertEqual((report.status, report.baud), ("detected", speed))
                self.assertEqual(calls[0], 38400)
                self.assertEqual(calls.count(speed), 2)

    def test_standard_uboot_banner_confirms_without_other_output(self):
        for banner in (b"U-Boot 2024.10 (Oct 07 2024 - 14:01:00 +0000)\r\n",
                       b"U-Boot SPL 2025.01-rc2\r\n"):
            with self.subTest(banner=banner):
                result = console.detect_speed(lambda speed, end: banner if speed == 57600 else b"")
                self.assertEqual((result.status, result.baud), ("detected", 57600))

    def test_ftdi_rejects_unavailable_data_width_before_claiming_line(self):
        from argparse import Namespace
        for frame in ("5n1", "6n1"):
            args = Namespace(operation="detect", port=self.line.by_id, target=None, profile=frame)
            with self.subTest(frame=frame), patch.object(console, "inventory", return_value=[self.line]), \
                    patch.object(console.os, "geteuid", return_value=0), \
                    patch.object(console, "claim", side_effect=AssertionError("unsupported FTDI frame opened")):
                with self.assertRaisesRegex(console.Unavailable, "FTDI"):
                    console.run(args)

    def test_silently_changed_driver_speed_or_stop_bits_disqualify_sample(self):
        with self.claim() as fd:
            apply = termios.tcsetattr
            for field in ("speed", "stop"):
                def changed(handle, when, attrs):
                    effective = list(attrs)
                    if field == "speed":
                        effective[4] = effective[5] = termios.B19200
                    else:
                        effective[2] &= ~termios.CSTOPB
                    apply(handle, when, effective)
                with self.subTest(field=field), patch.object(termios, "tcsetattr", side_effect=changed):
                    with self.assertRaises(console.Unavailable):
                        console.set_frame(fd, 9600, "8n2")

    def test_silence_noise_one_burst_and_tied_rates(self):
        outcomes = [
            ({}, "silent", None),
            ({9600: [b"\xfe\xff\0", b">"]}, "inconclusive", None),
            ({9600: [b"show clock\r\n"]}, "inconclusive", None),
            ({9600: [b"\xff" * 80 + b"\nlucky#\n"] * 2}, "inconclusive", None),
            ({9600: [b"switch01#"]}, "inconclusive", 9600),
            ({9600: [b"switch01#"], 19200: [b"switch01#"]}, "inconclusive", None),
        ]
        for samples, status, baud in outcomes:
            with self.subTest(samples=samples):
                def sample(rate, end):
                    return samples[rate].pop(0) if samples.get(rate) else b""
                report = console.detect_speed(sample, hint=115200)
                self.assertEqual((report.status, report.baud), (status, baud))

    def test_only_fresh_bytes_reach_sampler_and_terminal_sends_no_echo(self):
        with self.claim() as fd:
            events = []
            flush = termios.tcflush
            def arrivals(handle, which):
                flush(handle, which)
                events.append(which)
                os.write(self.master, b"old-speed#\n" if len(events) == 1 else b"new-speed#\r\n")
            with patch.object(termios, "tcflush", side_effect=arrivals):
                data = console.read_window(fd, 9600, "8n1", time.monotonic() + 0.35)
            self.assertEqual(data, b"new-speed#\r\n")
            self.assertFalse(select.select([self.master], [], [], 0)[0], "bytes transmitted back to target")

    def test_hangup_reports_unavailable(self):
        master, slave = os.openpty()
        self.addCleanup(os.close, slave)
        os.close(master)
        with self.assertRaises((console.Unavailable, termios.error, OSError)):
            console.read_window(slave, 9600, "8n1", time.monotonic() + 1)

    def test_overall_deadline_prevents_another_observation(self):
        with patch.object(time, "monotonic", side_effect=[0.0, 0.0, 12.1]):
            examined = []
            def sample(baud, end):
                examined.append((baud, end))
                return b""
            with self.assertRaises(console.Unavailable):
                console.detect_speed(sample)
        self.assertEqual(examined, [(9600, 1.5)])

    def test_manual_output_omits_raw_console_and_uses_selected_path(self):
        from argparse import Namespace
        args = Namespace(operation="detect", port=self.line.by_id, target=None, profile="cisco")
        def samples(fd, rate, frame, end):
            return b"switch01#\nprivate console contents\n" if rate == 9600 else b""
        out = io.StringIO()
        with patch.object(console, "inventory", return_value=[self.line]), \
                patch.object(console.os, "geteuid", return_value=0), \
                patch.object(console, "claim", return_value=contextlib.nullcontext(self.slave)), \
                patch.object(console, "read_window", side_effect=samples), contextlib.redirect_stdout(out):
            self.assertEqual(console.run(args), 0)
        self.assertIn("detected", out.getvalue())
        self.assertIn("/dev/serial/by-id/usb-test", out.getvalue())
        self.assertNotIn("private console", out.getvalue())

    def test_metadata_hint_is_bound_to_target_frame_and_port(self):
        from dataclasses import replace
        cache = self.root / "cache"
        console.remember(self.line, "cisco", "switchA", 19200, cache)
        self.assertEqual(console.last_known(self.line, "cisco", "switchA", cache)[0], 19200)
        for line, profile, target in [(self.line, "linux", "switchA"), (self.line, "cisco", "switchB"),
                                      (replace(self.line, usb_path="other"), "cisco", "switchA")]:
            self.assertIsNone(console.last_known(line, profile, target, cache))

    def test_manual_command_preserves_framing_and_one_quoted_path(self):
        args = console.picocom_arguments("/dev/serial/by-id/port with spaces", 9600, "8n2")
        self.assertEqual(args[-1], "/dev/serial/by-id/port with spaces")
        self.assertEqual(args[args.index("--stopbits") + 1], "2")
        self.assertEqual(args[args.index("--baud") + 1], "9600")

    @unittest.skipUnless(shutil.which("picocom"), "picocom is optional on package-only installations")
    def test_unprivileged_manual_picocom_session_on_pty(self):
        control, terminal = os.openpty()
        self.addCleanup(os.close, control)
        self.addCleanup(os.close, terminal)
        credentials = {}
        if os.geteuid() == 0:
            account = pwd.getpwnam("nobody")
            group = grp.getgrnam("dialout")
            os.fchown(self.slave, 0, group.gr_gid)
            os.fchmod(self.slave, 0o660)
            credentials = dict(user=account.pw_uid, group=account.pw_gid, extra_groups=[group.gr_gid])
        args = console.picocom_arguments(str(self.node), 9600, "8n2")
        with subprocess.Popen(args, stdin=terminal, stdout=terminal, stderr=terminal, **credentials) as session:
            def expect(needle):
                received = bytearray()
                end = time.monotonic() + 4
                while needle not in received and session.poll() is None:
                    if not select.select([control], [], [], max(0, end - time.monotonic()))[0]:
                        break
                    received.extend(os.read(control, 1024))
                self.assertIn(needle, received)
            try:
                expect(b"Terminal ready")
                readers = self.root / "picocom-process"
                readers.mkdir()
                (readers / str(session.pid)).symlink_to(Path("/proc") / str(session.pid))
                with self.assertRaises(console.Busy), self.claim(proc=readers):
                    self.fail("the detector took the terminal from picocom")
                os.write(self.master, b"test-switch#\r\n")
                expect(b"test-switch#")
                os.write(control, b"\x01\x18")
                self.assertEqual(session.wait(timeout=4), 0)
            finally:
                if session.poll() is None:
                    session.kill()
