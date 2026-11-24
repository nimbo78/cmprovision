"""Linux UART lease and Unix RPC tests. All serial traffic goes through a PTY."""
import base64
from contextlib import contextmanager
from dataclasses import replace
import errno
import hashlib
import json
import os
from pathlib import Path
import select
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "tools"))
import serial_web_broker as web


class BrokerTest(unittest.TestCase):
    owner = "a" * 64

    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.enterContext(patch.object(web.cli, "CACHE", self.root / "cache"))
        self.master, slave = os.openpty()
        self.addCleanup(self.close_master)
        self.line = web.cli.Line(Path(os.ttyname(slave)), os.fstat(slave).st_rdev, "pl2303",
                                 "/sys/devices/usb1/1-2", "00", "cable", "4",
                                 "by-id/usb-cable", "by-path/platform-test")
        os.close(slave)
        self.ports = [self.line]
        (self.root / "proc").mkdir()
        self.attr = self.root / "dev"
        self.attr.write_text(f"{os.major(self.line.rdev)}:{os.minor(self.line.rdev)}")
        self.claims = 0

        @contextmanager
        def claim(line, refresh, writable):
            self.claims += 1
            with web.cli.operator_lock(line, self.attr), web.cli.claim(
                    line, refresh, policies=lambda _: None, proc=self.root / "proc",
                    locks=self.root, runtime=self.root / "guard", writable=writable) as fd:
                yield fd

        self.server = web.Broker(inventory=lambda: self.ports, claim=claim,
                                 busy=lambda line: False)
        self.addCleanup(self.server.shutdown)

    def close_master(self):
        if self.master is not None:
            os.close(self.master)
            self.master = None

    def request(self, op, **body):
        return self.server.request(dict({"op": op, "owner": self.owner}, **body))

    def open(self, **overrides):
        port, = self.request("ports")["ports"]
        fields = dict(port=port["id"], generation=port["generation"],
                      mode="connect", baud=9600, frame="8n1")
        return self.request("open", **(fields | overrides))

    def until(self, session, state):
        end = time.monotonic() + 3
        while time.monotonic() < end:
            reply = self.request("poll", session=session, cursor=0)
            if reply.get("state") == state:
                return reply
            time.sleep(.015)
        self.fail(f"expected {state}, got {reply}")

    def read_peer(self, length):
        data = b""
        while len(data) < length and select.select([self.master], [], [], 1)[0]:
            data += os.read(self.master, length - len(data))
        return data

    def test_passive_inventory_stale_selection_and_cli_lock(self):
        port, = self.request("ports")["ports"]
        self.assertEqual((port["id"], self.claims), (self.line.by_id, 0))
        self.assertEqual(self.open(generation="0" * 64)["error"], "unavailable")
        self.assertEqual(self.open(port="/dev/null")["error"], "unavailable")
        with web.cli.operator_lock(self.line, self.attr):
            lease = self.open()["session"]
            self.until(lease, "busy")
        self.assertEqual(self.claims, 1)

    def test_real_duplex_bytes_exclusion_cursor_sequence_and_owner(self):
        lease = self.open()["session"]
        self.until(lease, "connected")
        self.assertEqual(self.open()["error"], "busy")
        with self.assertRaises(web.cli.Busy), web.cli.operator_lock(self.line, self.attr):
            self.fail("second CLI accepted")
        self.assertEqual(self.request("poll", session=lease, owner="b" * 64, cursor=0)["error"], "expired")
        self.assertEqual(self.request("write", session=lease, seq=1, data="!")["seq_consumed"], True)
        keys = b"\x03\x00\xff\r\n"
        tx = dict(session=lease, seq=2, data=base64.b64encode(keys).decode())
        self.assertTrue(self.request("write", **tx)["ok"])
        replay = self.request("write", **tx)
        self.assertEqual((replay["error"], replay["seq_consumed"]), ("conflict", False))
        self.assertEqual(self.read_peer(len(keys)), keys)
        os.write(self.master, keys)
        for _ in range(100):
            reply = self.request("poll", session=lease, cursor=0)
            if reply["next_cursor"] == len(keys):
                break
            time.sleep(.01)
        self.assertEqual(base64.b64decode(reply["data"]), keys)
        self.assertEqual(self.request("poll", session=lease, cursor=len(keys))["data"], "")
        self.assertTrue(self.request("close", session=lease)["ok"])
        self.assertTrue(self.request("close", session=lease)["ok"])

    def test_wake_sends_one_cr_and_leaves_a_quiet_auto_console_interactive(self):
        lease = self.open(baud="auto", frame="auto", wake=True)["session"]
        self.until(lease, "listening")
        self.assertFalse(select.select([self.master], [], [], .15)[0])
        reply = self.until(lease, "connected")
        self.assertEqual((reply["baud"], reply["frame"]), (9600, "8n1"))
        self.assertEqual(self.read_peer(1), b"\r")
        self.assertTrue(self.request("write", session=lease, seq=1, data="eA==")["ok"])
        self.assertEqual(self.read_peer(1), b"x")
        self.assertFalse(select.select([self.master], [], [], .15)[0])

    def test_speaking_console_is_preserved_and_only_a_login_invitation_allows_enter(self):
        for received, wake in (
            (b"Erase settings? [confirm]", False), (b"Username:", False),
            (b"Press Enter to confirm", False), (b"Boot menu\r\nPlease Press ENTER.", False),
            (b"Please Press ENTER.", False),
            (b"\r\nUser interface con0 is available\r\n\r\nPlease Press ENTER.", True),
            (b"Press RETURN to get started!", True),
        ):
            with self.subTest(received=received):
                lease = self.open(wake=True)["session"]
                self.until(lease, "listening")
                os.write(self.master, received[:5])
                time.sleep(.03)
                os.write(self.master, received[5:])
                reply = self.until(lease, "connected")
                self.assertEqual(base64.b64decode(reply["data"]), received)
                if wake:
                    self.assertEqual(self.read_peer(1), b"\r")
                self.assertFalse(select.select([self.master], [], [], .08)[0])
                self.assertTrue(self.request("close", session=lease)["ok"])

    def test_normal_connect_without_wake_opens_quiet_auto_and_uses_confirmed_settings(self):
        previous = self.open(baud=19200, frame="8n2")["session"]
        self.until(previous, "connected")
        os.write(self.master, b"\r\nswitch-test#")
        until = time.monotonic() + 2
        while self.request("poll", session=previous, cursor=0).get("hostname") != "switch-test":
            self.assertLess(time.monotonic(), until)
            time.sleep(.02)
        self.assertTrue(self.request("close", session=previous)["ok"])
        self.server = web.Broker(inventory=lambda: self.ports, claim=self.server.claim, busy=lambda _: False)
        self.addCleanup(self.server.shutdown)
        lease = self.open(baud="auto", frame="auto", wake=False)["session"]
        reply = self.until(lease, "connected")
        self.assertEqual((reply["baud"], reply["frame"]), (19200, "8n2"))
        self.assertIsNone(reply["hostname"])
        self.assertFalse(select.select([self.master], [], [], .1)[0])

    def test_wake_requires_attached_browser_and_is_cancelled_on_close(self):
        lease = self.open(wake=True)["session"]
        self.until(lease, "listening")
        self.assertTrue(self.request("close", session=lease)["ok"])
        lease = self.open(wake=True)["session"]
        time.sleep(1.4)
        self.until(lease, "connected")
        self.assertFalse(select.select([self.master], [], [], .15)[0])

    def test_identify_rejects_an_active_wake_request(self):
        self.assertEqual(self.open(mode="identify", wake=True).get("error"), "invalid")
        self.assertEqual(self.claims, 0)

    def test_browser_lost_after_first_poll_never_wakes_and_late_rx_cancels_wake(self):
        for late in (b"", b"Delete file? [confirm]"):
            with self.subTest(late=late):
                lease = self.open(wake=True)["session"]
                self.until(lease, "listening")
                time.sleep(1.15)
                self.assertFalse(select.select([self.master], [], [], .05)[0])
                if late:
                    os.write(self.master, late)
                    self.until(lease, "connected")
                    self.assertFalse(select.select([self.master], [], [], .1)[0])
                self.assertTrue(self.request("close", session=lease)["ok"])

    def test_a_known_duplicate_usb_serial_cannot_recover_the_other_cables_wake_identity(self):
        other = replace(self.line, by_path="by-path/other", usb_path="/sys/devices/usb1/1-3", generation="5")
        self.ports.append(other)
        self.assertEqual([p["id"] for p in self.request("ports")["ports"]], [self.line.by_path, other.by_path])
        self.ports[:] = [other]
        self.server = web.Broker(inventory=lambda: self.ports, claim=self.server.claim, busy=lambda _: False)
        self.addCleanup(self.server.shutdown)
        self.assertEqual(self.request("ports")["ports"][0]["id"], other.by_path)
        self.assertFalse(self.open(port=self.line.by_id, wake=True)["ok"])

    def test_noise_rescan_displays_winning_sample_and_remembers_frame_without_arming_a_transfer(self):
        sample = b"\r\nknown-switch#"
        with patch.object(web.Lease, "settings", return_value=(19200, "8n2", sample)):
            lease = self.open(baud="auto", frame="auto", wake=True)["session"]
            self.until(lease, "listening")
            os.write(self.master, b"\xff\x00\xaa\x9c")
            reply = self.until(lease, "connected")
        self.assertIn(sample, base64.b64decode(reply["data"]))
        self.assertFalse(reply["ready"])
        self.assertIsNone(reply["hostname"])
        self.assertEqual(self.server.line_hint(self.line), (19200, "8n2"))
        self.assertFalse(select.select([self.master], [], [], .1)[0])

    def test_identify_is_read_only_and_releases_after_matching_prompt(self):
        lease = self.open(mode="identify")["session"]
        for _ in range(100):
            if self.claims and self.request("poll", session=lease, cursor=0)["state"] == "waiting":
                break
            time.sleep(.01)
        self.assertEqual(self.request("write", session=lease, seq=1, data="eA==")["error"], "conflict")
        os.write(self.master, b"\r\nswitch-test#")
        self.assertEqual(self.until(lease, "identified")["hostname"], "switch-test")
        if select.select([self.master], [], [], 0)[0]:
            with self.assertRaises(OSError) as error:
                os.read(self.master, 128)
            self.assertEqual(error.exception.errno, errno.EIO)

    def test_replug_removal_cannot_reuse_lease_or_send_to_new_generation(self):
        lease = self.open()["session"]
        self.until(lease, "connected")
        self.ports[:] = [replace(self.line, generation="5")]
        self.assertEqual(self.request("write", session=lease, seq=1, data="eA==")["error"], "expired")
        self.until(lease, "closed")
        self.server.leases[lease][1].released.wait(1)
        new = self.open()["session"]
        self.until(new, "connected")
        self.close_master()
        self.until(new, "closed")

    def test_logout_revokes_pending_opens_and_idle_closes(self):
        with patch.object(web, "IDLE_SECONDS", .12):
            lease = self.open()["session"]
            self.until(lease, "connected")
            time.sleep(.3)
            self.until(lease, "closed")
        another = self.open()["session"]
        self.until(another, "connected")
        self.assertTrue(self.request("close_owner")["ok"])
        self.assertEqual(self.open()["error"], "expired")
        self.until(another, "closed")

    def test_partial_writes_preserve_bytes_and_close_discards_unsent_data(self):
        lease = self.open()["session"]
        self.until(lease, "connected")
        write = os.write
        def fragment(fd, data):
            return write(fd, data[:3])
        raw = b"manual input\r"
        with patch.object(web.os, "write", side_effect=fragment):
            self.assertTrue(self.request("write", session=lease, seq=1, data=base64.b64encode(raw).decode())["ok"])
            self.assertEqual(self.read_peer(len(raw)), raw)
        with patch.object(web.os, "write", side_effect=BlockingIOError):
            self.assertTrue(self.request("write", session=lease, seq=2, data="eA==")["ok"])
            self.assertTrue(self.request("close", session=lease)["ok"])
        self.assertFalse(self.server.leases[lease][1].pending)

    def test_unread_overflow_closes_instead_of_dropping_transcript(self):
        lease = self.open()["session"]
        self.until(lease, "connected")
        with patch.object(web, "RX_LIMIT", 32):
            os.write(self.master, b"x" * 33)
            reply = self.until(lease, "closed")
        self.assertEqual(reply["data"], "")
        self.assertIn("unread", reply["message"])

    def test_bulk_copy_completes_over_a_tty_with_one_start_and_no_browser_answers(self):
        lease = self.open()["session"]
        self.until(lease, "connected")
        os.write(self.master, b"\r\nrack#")
        for _ in range(100):
            if self.request("poll", session=lease, cursor=0)["ready"]:
                break
            time.sleep(.01)
        cmd = b"copy running-config startup-config"
        self.assertTrue(self.request("file_preview", session=lease, seq=1, filename="save.cfg", text=cmd.decode())["ok"])
        self.assertTrue(self.request("file_action", session=lease, seq=2, action="start", mode="bulk")["ok"])
        self.assertEqual(self.read_peer(len(cmd)), cmd)
        os.write(self.master, cmd)
        self.assertEqual(self.read_peer(1), b"\r")
        for prompt, reply in ((b"Destination filename [startup-config]?", b"\r"),
                              (b"Overwrite? [y/n]:", b"y")):
            os.write(self.master, b"\r\n" + prompt)
            self.assertEqual(self.read_peer(1), reply)
        os.write(self.master, b"y\r\n[OK]\r\nrack#")
        for _ in range(100):
            job = self.request("poll", session=lease, cursor=0)["transfer"]
            if job["stage"] == "done":
                break
            time.sleep(.01)
        self.assertEqual((job["stage"], job["done"], job["auto_answers"]), ("done", 1, 2))
        self.assertFalse(select.select([self.master], [], [], .1)[0], "extra Enter after a single-key answer")

    def test_real_file_exchange_heartbeats_pause_until_operator_resumes(self):
        lease = self.open()["session"]
        self.until(lease, "connected")
        os.write(self.master, b"\r\nswitch-test#")
        for _ in range(100):
            reply = self.request("poll", session=lease, cursor=0)
            if reply["ready"]:
                break
            time.sleep(.01)
        self.assertEqual(reply["version_command"], "show version")
        first, second = b"show clock", b"show version"
        self.assertTrue(self.request("file_preview", session=lease, seq=1, filename="show.txt",
                                     text=(first + b"\n" + second).decode())["ok"])
        self.assertEqual(self.request("file_page", session=lease, page=0)["rows"][1]["num"], 2)
        with patch.object(web, "HEARTBEAT_SECONDS", .08):
            self.assertTrue(self.request("file_action", session=lease, seq=2, action="start", mode="bulk")["ok"])
            self.assertEqual(self.read_peer(len(first)), first)
            os.write(self.master, first)
            self.assertEqual(self.read_peer(1), b"\r")
            time.sleep(.1)
            os.write(self.master, b"\r\n12:00\r\nswitch-test#")
            self.assertFalse(select.select([self.master], [], [], .25)[0])
        transfer = self.request("poll", session=lease, cursor=0)["transfer"]
        self.assertEqual((transfer["stage"], transfer["done"], transfer["hold"]), ("between", 1, True))
        self.assertTrue(self.request("file_action", session=lease, seq=3, action="next")["ok"])
        self.assertEqual(self.read_peer(len(second)), second)
        self.assertTrue(self.request("file_action", session=lease, seq=4, action="stop")["ok"])
        os.write(self.master, second)
        self.assertFalse(select.select([self.master], [], [], .15)[0])

    def test_close_during_scan_releases_tty_before_immediate_reopen(self):
        reading = threading.Event()
        def sample(*args):
            reading.set()
            time.sleep(.15)
            return b""
        with patch.object(web.cli, "read_window", side_effect=sample):
            lease = self.open(baud="auto")["session"]
            self.assertTrue(reading.wait(1))
            self.assertTrue(self.request("close", session=lease)["ok"])
            new = self.open()["session"]
            self.until(new, "connected")

    def test_unplug_retains_only_confirmed_and_unfinished_source_line_numbers(self):
        lease = self.open()["session"]
        self.until(lease, "connected")
        os.write(self.master, b"\r\nswitch-test#")
        for _ in range(100):
            if self.request("poll", session=lease, cursor=0)["ready"]:
                break
            time.sleep(.01)
        first, second = b"show clock", b"show version"
        self.assertTrue(self.request("file_preview", session=lease, seq=1, filename="private.txt",
                                     text="! comment\nshow clock\n\nshow version")["ok"])
        self.assertTrue(self.request("file_action", session=lease, seq=2, action="start", mode="step")["ok"])
        self.assertEqual(self.read_peer(len(first)), first)
        os.write(self.master, first)
        self.assertEqual(self.read_peer(1), b"\r")
        os.write(self.master, b"\r\n12:00\r\nswitch-test#")
        for _ in range(100):
            if self.request("poll", session=lease, cursor=0)["transfer"]["stage"] == "between":
                break
            time.sleep(.01)
        self.assertTrue(self.request("file_action", session=lease, seq=3, action="next")["ok"])
        self.assertEqual(self.read_peer(len(second)), second)
        self.close_master()
        reply = self.until(lease, "closed")
        self.assertEqual(reply["final_progress"], {"last_done_line": 2, "uncertain_line": 4})
        self.assertIsNone(reply["transfer"])
        self.assertEqual(reply["data"], "")

    def test_same_host_fresh_prompt_advances_seen_time_and_idle_preserves_it(self):
        lease = self.open()["session"]
        self.until(lease, "connected")
        previous = None
        for _ in range(2):
            os.write(self.master, b"\r\nswitch-test#")
            for _ in range(100):
                port, = self.request("ports")["ports"]
                if port["seen_at"] and port["seen_at"] != previous:
                    break
                time.sleep(.01)
            self.assertEqual(port["hostname"], "switch-test")
            self.assertNotEqual(previous, port["seen_at"])
            previous = port["seen_at"]
            time.sleep(.08)
            self.assertEqual(self.request("ports")["ports"][0]["seen_at"], previous)
        self.request("close", session=lease)
        with patch.object(web, "RETAIN_SECONDS", 0):
            self.assertEqual(self.request("ports")["ports"][0]["seen_at"], previous)
        self.ports[:] = [replace(self.line, generation="5")]
        self.assertIsNone(self.request("ports")["ports"][0]["seen_at"])

    def test_audit_correlates_hardware_transitions_without_session_or_terminal_data(self):
        with self.assertLogs("cmprovision.serial", level="INFO") as log:
            key = self.open()["session"]
            self.until(key, "connected")
            self.assertTrue(self.request("write", session=key, seq=1, data="c2VjcmV0")["ok"])
            self.assertEqual(self.read_peer(6), b"secret")
            self.assertTrue(self.request("close", session=key)["ok"])
            identify = self.open(mode="identify")["session"]
            self.until(identify, "waiting")
            os.write(self.master, b"\r\nprivate-host#")
            self.until(identify, "identified")
            self.request("close", session=identify)
            with patch.object(web, "IDLE_SECONDS", .12):
                idle = self.open()["session"]
                self.until(idle, "connected")
                time.sleep(.3)
                self.until(idle, "closed")
            last = self.open()["session"]
            self.until(last, "connected")
            self.close_master()
            self.until(last, "closed")
        events = []
        for lease, mode, states in ((key, "connect", ("connected", "closed")),
                                    (identify, "identify", ("identifying", "identified")),
                                    (idle, "connect", ("connected", "expired")),
                                    (last, "connect", ("connected", "disconnected"))):
            ref = hashlib.sha256(lease.encode("ascii")).hexdigest()[:24]
            events.extend({"event": event, "connection": ref, "mode": mode} for event in states)
        self.assertEqual([json.loads(row.getMessage()) for row in log.records], events)

    def test_manual_baud_auto_frame_identify_keeps_only_confirmed_sample(self):
        seen = []
        def sample(fd, rate, frame, end):
            seen.append((rate, frame))
            return b"<identified-switch>\r\n" if frame == "8n1" else b""
        with patch.object(web.cli, "read_window", side_effect=sample):
            lease = self.open(mode="identify", frame="auto")["session"]
            reply = self.until(lease, "identified")
        self.assertEqual((reply["hostname"], reply["baud"], reply["frame"]),
                         ("identified-switch", 9600, "8n1"))
        self.assertEqual({rate for rate, _ in seen}, {9600})
        self.assertEqual(seen.count((9600, "8n1")), 2)

    def test_unix_rpc_is_single_bounded_json_line(self):
        client, server = socket.socketpair()
        self.addCleanup(client.close)
        worker = threading.Thread(target=web.serve_client, args=(server, self.server))
        worker.start()
        client.sendall(b'{"op":"ports"}\n')
        self.assertIn(b'"ports"', client.recv(16384))
        worker.join(2)
        self.assertFalse(worker.is_alive())

    def test_packaged_isolated_python_serves_inherited_socket_and_ignores_cwd(self):
        lib = self.root / "usr/lib/cmprovision/serial-web"
        commands = self.root / "usr/bin"
        lib.mkdir(parents=True)
        commands.mkdir(parents=True)
        src = Path(web.__file__).parent
        shutil.copyfile(src / "serial-console.py", commands / "cmprovision-console")
        (lib / "serial-console.py").symlink_to("../../../bin/cmprovision-console")
        for name in ("serial_web_broker.py", "serial_transfer.py"):
            shutil.copyfile(src / name, lib / name)
        (self.root / "serial_transfer.py").write_text("raise RuntimeError('unexpected import')")
        path = str(self.root / "activation.sock")
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as listener:
            listener.bind(path)
            listener.listen(2)
            # Like systemd, pass a listening descriptor (3) and validate its
            # actual Unix address. A private tmpdir substitutes for /run here.
            boot = ("import os,runpy,sys; os.dup2(int(sys.argv[2]),3); "
                    "os.environ.update(LISTEN_PID=str(os.getpid()),LISTEN_FDS='1'); "
                    "runpy.run_path(sys.argv[1])['main'](sys.argv[3])")
            child = subprocess.Popen([sys.executable, "-I", "-B", "-c", boot, str(lib / "serial_web_broker.py"),
                                      str(listener.fileno()), path], pass_fds=(listener.fileno(),),
                                     cwd=self.root, env=dict(os.environ, PYTHONPATH=str(self.root)))
            try:
                with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as client:
                    client.settimeout(3)
                    client.connect(path)
                    client.sendall(b'{"op":"poll"}\n')
                    self.assertEqual(json.loads(client.recv(2048))["error"], "invalid")
            finally:
                child.terminate()
                child.wait(timeout=4)
