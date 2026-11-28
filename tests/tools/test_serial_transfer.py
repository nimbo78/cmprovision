import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "tools"))
from serial_transfer import ConsoleProtocol


VRP_VERSION = (b"Huawei Versatile Routing Platform Software\r\n"
               b"VRP (R) software, Version 5.170 (S5731 V200R022C00)\r\n"
               b"HUAWEI S5731-H24T4XC Routing Switch uptime is 72 days")
YUN_VERSION = (b"Huawei YunShan OS\r\nVersion 1.24.1.1 (S5700 V600R024C10)\r\n"
               b"CloudEngine S5735-S-V2 uptime is 1 day\r\n"
               b"S5735-S24T4XE-V2(Master) 1 : uptime is 1 day")
YUN_INDUSTRIAL_VERSION = (b"Huawei YunShan OS\r\nVersion 1.22.1.1 (S5700 V600R022C10SPC500)\r\n"
                          b"HUAWEI CloudEngine S5735I-S-V2 uptime is 1 day\r\n"
                          b"Patch Version: V600R022SPH180\r\n"
                          b"S5735I-S8U4XN-V2(Master) 1 : uptime is 1 day")


class SerialTransferTest(unittest.TestCase):
    def setUp(self):
        self.p = ConsoleProtocol()
        self.now = 1

    def rx(self, data):
        self.now += .1
        self.p.feed(data, self.now)
        self.now += .1
        self.p.tick(self.now)

    def read_version(self, output, prompt):
        self.p.manual(b"display version\r")
        self.rx(b"display version\r\n" + output + b"\r\n" + prompt)

    def start(self, text, prompt=b"switch#", mode="step", allow=False, version=None):
        self.rx(prompt)
        if version is not None:
            self.read_version(version, prompt)
        self.p.preview(text, "commands.cfg", "8n1")
        self.p.action("start", self.now, mode=mode, allow_default=allow)

    def submit(self, line):
        self.assertEqual(self.p.take_output(), line)
        self.rx(line[:1])
        if len(line) > 1:
            self.assertEqual(self.p.take_output(), b"")
            self.rx(line[1:])
        self.assertEqual(self.p.take_output(), b"\r")

    def test_cisco_echo_then_fresh_expected_view_then_next(self):
        self.start("! comment\rconfigure terminal\r\n interface Gi1/1\nend")
        self.submit(b"configure terminal")
        self.assertEqual(self.p.snapshot()["line"], 2)
        self.assertEqual(self.p.snapshot()["done"], 0)
        self.rx(b"\r\nEnter configuration commands.\r\nswitch(config)#")
        self.assertEqual(self.p.snapshot()["stage"], "between")
        self.p.action("next", self.now)
        self.submit(b" interface Gi1/1")
        self.rx(b"\r\nswitch(config-if)#")
        self.p.action("next", self.now)
        self.submit(b"end")
        self.rx(b"\r\nswitch#")
        self.assertEqual(self.p.snapshot()["stage"], "done")

    def test_preflight_whole_file_and_source_numbers(self):
        self.rx(b"<core-sw>")
        for bad in ["bad\tline", "header login information ^", "# text", "!Software Version 2",
                    "interface GE1/0/1\n#\nvlan 3", "banner motd ^", "cmd\x85next"]:
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                self.p.preview("display clock\n" + bad, "c", "8n1")
        for bad in ["description café", "x" * 1048577, "\ud800"]:
            with self.assertRaises(ValueError):
                self.p.preview(bad, "c", "7e1")
        self.p.preview("\ufeff! ignored\r\ndisplay clock\r\n\n display version", "c", "8n1")
        self.assertEqual(self.p.page(0), {"pages": 1, "rows": [
            {"num": 2, "line": "display clock"}, {"num": 4, "line": " display version"}]})
        self.assertEqual(self.p.take_output(), b"")

    def test_mismatch_never_submits_or_replays(self):
        self.start("show clock\nshow version", mode="smart")
        self.assertEqual(self.p.take_output(), b"show clock")
        self.rx(b"sho clock")
        self.assertEqual(self.p.snapshot()["stage"], "uncertain")
        self.rx(b"\r\nswitch#")
        for action in ["start", "next", "correct", "enter"]:
            with self.assertRaises(ValueError):
                self.p.action(action, self.now, value="show clock")
        self.assertEqual(self.p.take_output(), b"")

    def test_foreign_prompt_unknown_question_or_diagnostic_holds(self):
        for output in [b"other#", b"Password:", b"switch(config)#", b"%LINK-3-UPDOWN\r\nswitch#",
                       b"Warning: damaged\r\n<rack-sw>", b"[*rack-sw]", b"[rack-sw-vlan3]"]:
            with self.subTest(output=output):
                self.setUp()
                hw = b"<rack-sw>" if b"rack-sw" in output else b"switch#"
                self.start("test\nnext", hw, mode="smart", version=VRP_VERSION if hw.startswith(b"<") else None)
                self.submit(b"test")
                self.rx(b"\r\n" + output)
                self.assertEqual(self.p.snapshot()["stage"], "uncertain")
                self.assertEqual(self.p.take_output(), b"")

    def test_huawei_view_rename_rejection_and_explicit_edit(self):
        self.start("system-view\ninterface GigabitEthernet 0/0/1\nquit\nsysname new-sw\nreturn\ndisplay clok", b"<rack-east-01>")
        for i, (line, prompt) in enumerate([
                (b"system-view", b"[rack-east-01]"),
                (b"interface GigabitEthernet 0/0/1", b"[rack-east-01-GE0/0/1]"),
                (b"quit", b"[rack-east-01]"), (b"sysname new-sw", b"[new-sw]"),
                (b"return", b"<new-sw>")]):
            if i:
                self.p.action("next", self.now)
            self.submit(line)
            self.rx(b"\r\n" + prompt)
            self.assertEqual(self.p.snapshot()["stage"], "between")
        self.p.action("next", self.now)
        self.submit(b"display clok")
        self.rx(b"\r\n   ^\r\nError: Unrecognized command\r\n<new-sw>")
        self.assertEqual(self.p.snapshot()["stage"], "error")
        with self.assertRaises(ValueError):
            self.p.action("correct", self.now, value="display clock\rquit")
        self.p.action("correct", self.now, value="display clock")
        self.submit(b"display clock")
        self.rx(b"\r\n12:34:56\r\n<new-sw>")
        self.assertEqual(self.p.hostname, "new-sw")
        self.assertEqual(self.p.snapshot()["done"], 6)

    def test_huawei_two_questions_matched_by_kind(self):
        self.start("save", b"<sw>")
        self.submit(b"save")
        self.rx(b"\r\nWarning: configuration will be saved. Continue? [Y/N]:")
        self.assertEqual(self.p.snapshot()["answer_kind"], "yesno")
        with self.assertRaises(ValueError):
            self.p.action("enter", self.now)
        self.p.action("yes", self.now)
        self.submit(b"y")
        self.rx(b"\r\nInfo: Please input the file name [vrpcfg.zip]:")
        self.assertEqual(self.p.snapshot()["answer_kind"], "filename")
        with self.assertRaises(ValueError):
            self.p.action("filename", self.now, value="../foo.cfg")
        self.p.action("filename", self.now, value="bench.zip")
        self.submit(b"bench.zip")
        self.rx(b"\r\nInfo: Saved.\r\n<sw>")
        self.assertEqual(self.p.snapshot()["stage"], "done")

    def test_bulk_cisco_answers_distinct_copy_questions_and_advances(self):
        self.start("copy running-config startup-config\nshow clock", mode="bulk")
        self.submit(b"copy running-config startup-config")
        for question in (b"Destination filename [startup-config]?", b"Overwrite startup-config? [confirm]"):
            self.rx(b"\r\n" + question)
            self.assertEqual(self.p.take_output(), b"\r")
        self.rx(b"\r\nContinue? [yes/no]:")
        self.submit(b"yes")
        self.rx(b"\r\n[OK]\r\nswitch#")
        self.now += .2
        self.p.tick(self.now)
        self.submit(b"show clock")
        self.rx(b"\r\n12:25:10\r\nswitch#")
        self.assertEqual(self.p.snapshot()["done"], 2)
        self.assertEqual(self.p.snapshot()["auto_answers"], 3)

    def test_bulk_cisco_single_key_question_does_not_submit_enter_at_a_new_prompt(self):
        for echo in (b"", b"y"):
            with self.subTest(echo=echo):
                self.setUp()
                self.start("test", mode="bulk")
                self.submit(b"test")
                self.rx(b"\r\nContinue? [y/n]:")
                self.assertEqual(self.p.take_output(), b"y")
                self.rx(echo + b"\r\nswitch#")
                self.assertEqual(self.p.take_output(), b"")
                self.assertEqual(self.p.snapshot()["stage"], "done")

    def test_bulk_huawei_file_runs_through_save_dialogs_on_both_families(self):
        for version in (VRP_VERSION, YUN_VERSION):
            with self.subTest(version=version.splitlines()[0]):
                self.setUp()
                self.start("display clock\n" * 199 + "save", b"<rack>", mode="bulk", version=version)
                for _ in range(199):
                    self.submit(b"display clock")
                    self.rx(b"\r\n12:03:00\r\n<rack>")
                    self.now += .2
                    self.p.tick(self.now)
                self.submit(b"save")
                self.rx(b"\r\nWarning: The current configuration will be written to the device.\r\nContinue? [Y/N][N]:")
                self.submit(b"y")
                self.rx(b"\r\nInfo: Please input the file name [vrpcfg.zip]:")
                self.assertEqual(self.p.take_output(), b"\r")
                self.rx(b"\r\nWarning: File exists. Overwrite? [Y/N]:")
                self.submit(b"y")
                self.rx(b"\r\nInfo: Saved.\r\n<rack>")
                self.assertEqual(self.p.snapshot()["stage"], "done")
                self.assertEqual(self.p.snapshot()["done"], 200)
                self.assertEqual(self.p.snapshot()["auto_answers"], 3)

    def test_bulk_distinguishes_two_huawei_warnings_with_the_same_short_question(self):
        self.start("save", b"<rack>", mode="bulk", version=VRP_VERSION)
        self.submit(b"save")
        for message in (b"Warning: Save the current configuration?", b"Warning: Overwrite the existing file?"):
            self.rx(b"\r\n" + message + b"\r\nContinue? [Y/N]:")
            self.submit(b"y")
        self.rx(b"\r\nWarning: Overwrite the existing file?\r\nContinue? [Y/N]:")
        self.assertEqual(self.p.snapshot()["stage"], "question")
        self.assertEqual(self.p.take_output(), b"")

    def test_bulk_waits_for_a_slow_flash_write_and_continues_on_the_real_prompt(self):
        self.start("save\n" + "display clock", b"<rack>", mode="bulk", version=YUN_VERSION)
        self.submit(b"save")
        self.rx(b"\r\nContinue? [Y/N]:")
        self.submit(b"y")
        self.now += 15
        self.p.tick(self.now)
        self.assertEqual(self.p.snapshot()["stage"], "awaiting")
        self.assertEqual(self.p.take_output(), b"")
        self.rx(b"\r\nInfo: Saved.\r\n<rack>")
        self.assertEqual(self.p.snapshot()["last_done_line"], 1)

    def test_bulk_repeated_question_pause_and_unexpected_input_do_not_send_an_answer(self):
        for result in (b"Continue? [yes/no]:", b"other#\r\nProceed? [confirm]", b"Password:",
                       b"Would you like to enter the initial configuration dialog? [yes/no]:",
                       b"% Invalid input\r\nProceed? [confirm]"):
            with self.subTest(result=result):
                self.setUp()
                self.start("test\nshow clock", mode="bulk")
                self.submit(b"test")
                self.rx(b"\r\nContinue? [yes/no]:")
                self.submit(b"yes")
                self.rx(b"\r\n" + result)
                self.assertIn(self.p.snapshot()["stage"], ("question", "uncertain"))
                self.assertEqual(self.p.snapshot()["done"], 0)
                self.assertEqual(self.p.take_output(), b"")
        self.setUp()
        self.start("test", mode="bulk")
        self.submit(b"test")
        self.p.action("pause", self.now)
        self.rx(b"\r\nProceed? [confirm]")
        self.assertEqual(self.p.snapshot()["stage"], "question")
        self.assertEqual(self.p.take_output(), b"")

    def test_bulk_pages_long_output_but_does_not_answer_text_printed_by_show(self):
        self.start("show inventory", mode="bulk")
        self.submit(b"show inventory")
        self.rx(b"\r\nInventory\r\n--More--")
        self.assertEqual(self.p.take_output(), b" ")
        self.rx(b"\r\nDescription: Proceed? [confirm]")
        self.assertEqual(self.p.snapshot()["stage"], "question")
        self.assertEqual(self.p.take_output(), b"")

    def test_default_destination_one_exact_line_once(self):
        self.start("!\ncopy running-config startup-config", mode="smart", allow=True)
        self.assertEqual(self.p.snapshot()["default_line"], 2)
        self.submit(b"copy running-config startup-config")
        self.rx(b"\r\nDestination filename [startup-config]?")
        self.assertEqual(self.p.take_output(), b"\r")
        self.rx(b"\r\nDestination filename [startup-config]?")
        self.assertEqual(self.p.snapshot()["answer_kind"], "enter")
        self.assertEqual(self.p.take_output(), b"")

    def test_pager_requires_allowed_command_and_caps_pages(self):
        self.start("show version", mode="smart")
        self.submit(b"show version")
        for i in range(20):
            self.rx(b"\r\nline\r\n--More--")
            self.assertEqual(self.p.take_output(), b" ")
        self.rx(b"\r\n--More--")
        self.assertEqual(self.p.take_output(), b"")
        self.assertEqual(self.p.snapshot()["pages"], 20)
        self.assertEqual(self.p.snapshot()["answer_kind"], "pager")
        self.p.action("space", self.now)
        self.assertEqual(self.p.take_output(), b" ")
        self.rx(b"\r\nMore in normal output\r\nswitch#")
        self.assertEqual(self.p.snapshot()["stage"], "done")

    def test_manual_linux_and_unanchored_bracket(self):
        for prompt in [b"node login:", b"user@node:~$", b"[rack-east-01-GE1/0/1]"]:
            self.rx(b"\r\n" + prompt)
            self.p.manual(b"\x1b[A")
            self.rx(b"\r\n" + prompt)
            with self.assertRaises(ValueError):
                self.p.preview("test", "c", "8n1")
            self.assertEqual(self.p.take_output(), b"")

    def test_passive_version_requires_user_command_and_real_response(self):
        self.rx(b"<rack-west-02>")
        reply = (b"\r\nHuawei YunShan OS\r\nVersion V600R024C10\r\n"
                 b"CloudEngine S5735-S-V2 uptime is 1 day\r\n"
                 b"S5735-S24T4XE-V2(Master) 1 : uptime is 1 day\r\n<rack-west-02>")
        self.rx(reply)
        self.assertIsNone(self.p.platform)
        self.p.manual(b"dis")
        self.rx(b"dis")
        self.p.manual(b"play version\r")
        self.rx(b"play version" + reply)
        self.assertIn("S5735-S24T4XE-V2", self.p.platform)
        self.assertIn("YunShan OS V600R024C10", self.p.platform)
        self.rx(b"\r\n<new-peer>")
        self.assertIsNone(self.p.platform)
        self.assertEqual(self.p.take_output(), b"")

    def test_timeout_stop_clear_no_output(self):
        self.start("show clock")
        self.assertEqual(self.p.take_output(), b"show clock")
        self.p.tick(self.now + 9)
        self.assertEqual(self.p.snapshot()["stage"], "uncertain")
        self.p.action("stop", self.now + 10)
        self.assertEqual(self.p.snapshot()["text"], "")
        self.p.action("clear", self.now + 11)
        self.assertIsNone(self.p.snapshot())
        self.assertEqual(self.p.take_output(), b"")

    def test_exact_filename_permission_excludes_whitespace_and_other_commands(self):
        self.start("copy running-config startup-config", mode="smart", allow=True)
        self.submit(b"copy running-config startup-config")
        self.rx(b"\r\n Destination filename [startup-config]?")
        self.assertEqual(self.p.snapshot()["stage"], "question")
        self.assertEqual(self.p.take_output(), b"")

    def test_prior_unanswered_prompt_in_same_read_stops_progress(self):
        for output in [b"Which interface?\r\nswitch#", b"Password:\r\nswitch#",
                       b"[*other]\r\nswitch#", b"other#\r\nswitch#"]:
            with self.subTest(output=output):
                self.setUp()
                self.start("show version\nshow clock", mode="smart")
                self.submit(b"show version")
                self.rx(b"\r\n" + output)
                self.assertEqual(self.p.snapshot()["stage"], "uncertain")

    def test_manual_input_echo_cannot_supply_a_prompt(self):
        self.rx(b"switch#")
        self.assertTrue(self.p.ready)
        self.p.manual(b"new#")
        self.rx(b"new#")
        self.assertFalse(self.p.ready)
        self.assertEqual(self.p.hostname, "switch")
        self.p.manual(b"\r")
        self.rx(b"\r\nswitch#")
        self.assertEqual(self.p.version_command, "show version")

    def test_prompts_in_linux_and_receiver_input_never_emit_bytes(self):
        self.rx(b"node login:")
        self.assertEqual(self.p.hostname, "node")
        self.assertFalse(self.p.ready)
        self.assertIsNone(self.p.version_command)
        self.p.manual(b"operator\r")
        self.rx(b"operator\r\nPassword: ")
        self.p.manual(b'new-pass\r')
        self.rx(b"\r\noperator@node:~$ ")
        self.assertFalse(self.p.ready)
        self.assertIsNone(self.p.platform)
        self.assertEqual(self.p.take_output(), b"")

    def test_linux_login_cannot_anchor_a_huawei_subview(self):
        self.rx(b"core login:")
        self.rx(b"\r\n[core-vlan3]")
        self.assertFalse(self.p.ready)

    def test_review_invalidated_after_peer_change_even_if_original_name_returns(self):
        self.rx(b"<peer>")
        self.p.preview("display clock", "c", "8n1")
        self.rx(b"\r\n<other>")
        self.rx(b"\r\n<peer>")
        with self.assertRaises(ValueError):
            self.p.action("start", self.now)
        self.assertEqual(self.p.take_output(), b"")

    def test_split_line_in_chunks_with_one_enter_and_no_autonomous_replay(self):
        line = ("description " + "A" * 1000 + "é" * 1024).encode()
        self.start(line.decode())
        tx = bytearray()
        while len(tx) < len(line):
            chunk = self.p.take_output()
            self.assertGreater(len(chunk), 0)
            self.assertLessEqual(len(chunk), 1024)
            tx.extend(chunk)
            self.rx(chunk)
        self.assertEqual(tx, line)
        self.assertEqual(self.p.take_output(), b"\r")
        self.p.transport_lost("unplugged")
        self.assertEqual(self.p.snapshot()["done"], 0)
        self.assertEqual(self.p.snapshot()["stage"], "stopped")
        self.assertFalse(self.p.ready)
        self.assertEqual(self.p.take_output(), b"")

    def test_step_pager_does_not_submit_a_line_and_accepts_marker_erasure(self):
        self.start("show interfaces status")
        self.submit(b"show interfaces status")
        self.rx(b"\r\nGi1/1 connected\r\n--More--")
        self.assertEqual(self.p.take_output(), b"")
        self.assertEqual(self.p.snapshot()["stage"], "question")
        self.p.action("space", self.now)
        self.assertEqual(self.p.take_output(), b" ")
        self.rx(b"\x08"*8 + b" "*8 + b"\x08"*8 + b"Gi1/2 up\r\nswitch#")
        self.assertEqual(self.p.snapshot()["stage"], "done")

    def test_smart_pause_no_new_line_until_explicit_resume(self):
        self.start("show clock\nshow clock", mode="smart")
        self.submit(b"show clock")
        self.p.action("pause", self.now)
        self.rx(b"\r\nswitch#")
        self.p.tick(self.now + 1)
        self.assertEqual(self.p.snapshot()["stage"], "between")
        self.assertEqual(self.p.take_output(), b"")
        self.p.action("next", self.now + 1)
        self.assertEqual(self.p.take_output(), b"show clock")

    def test_unapproved_pager_or_warning_never_automatically_writes(self):
        for command, reply in [
                ("display interface brief | redirect x", b"---- More ----"),
                ("display version", b"Warning: review output\r\n---- More ----")]:
            with self.subTest(command=command):
                self.setUp()
                self.start(command, b"<sw>", mode="smart", version=VRP_VERSION)
                self.submit(command.encode())
                self.rx(b"\r\n" + reply)
                self.assertEqual(self.p.take_output(), b"")

    def test_smart_advances_only_after_a_stable_prompt(self):
        self.start("show clock\nshow clock", mode="smart")
        self.submit(b"show clock")
        self.p.feed(b"\r\nswitch#", self.now)
        self.p.tick(self.now + .01)
        self.assertEqual(self.p.snapshot()["stage"], "awaiting")
        self.p.tick(self.now + .1)
        self.assertEqual(self.p.snapshot()["stage"], "between")
        self.p.tick(self.now + .21)
        self.assertEqual(self.p.take_output(), b"show clock")

    def test_read_version_confident_vrp_and_ambiguous_banner(self):
        self.rx(b"<s5731>")
        for banner, label in [
                (b"Huawei Versatile Routing Platform Software", "S5731-H24T4XC · VRP V200R022C00"),
                (b"Huawei Versatile Routing Platform Software\r\nHuawei YunShan OS", None)]:
            with self.subTest(banner=banner):
                self.p.manual(b"display version\r")
                self.rx(b"display version\r\n" + banner + b"\r\nVersion V200R022C00\r\n"
                        b"HUAWEI S5731-H24T4XC Routing Switch uptime is 72 days\r\n<s5731>")
                self.assertEqual(self.p.platform, label)

    def test_huawei_smart_requires_requested_version_even_if_file_starts_with_it(self):
        self.rx(b"<sw>")
        self.rx(b"\r\n" + YUN_VERSION + b"\r\n<sw>")
        self.p.preview("display version\ndisplay clock", "f", "8n1")
        with self.assertRaisesRegex(ValueError, "display version"):
            self.p.action("start", self.now, mode="smart")
        self.assertTrue(self.p.ready)
        self.assertFalse(self.p.smart_ready)
        self.assertEqual(self.p.snapshot()["stage"], "review")
        self.assertEqual(self.p.take_output(), b"")
        self.p.action("start", self.now, mode="step")
        self.submit(b"display version")
        self.rx(b"\r\n" + YUN_VERSION + b"\r\n<sw>")
        self.assertTrue(self.p.smart_ready)
        self.assertEqual(self.p.snapshot()["stage"], "between")
        self.assertEqual(self.p.take_output(), b"")

    def test_explicit_identification_unlocks_smart_for_both_platforms(self):
        for output, model in [(VRP_VERSION, "S5731-H24T4XC"), (YUN_VERSION, "S5735-S24T4XE-V2"),
                              (YUN_INDUSTRIAL_VERSION, "S5735I-S8U4XN-V2")]:
            with self.subTest(model=model):
                self.setUp()
                self.rx(b"<sw>")
                self.assertFalse(self.p.smart_ready)
                self.read_version(output, b"<sw>")
                self.assertIn(model, self.p.platform)
                self.assertTrue(self.p.smart_ready)
                self.p.preview("display clock", "f", "8n1")
                self.p.action("start", self.now, mode="smart")
                self.submit(b"display clock")
                self.rx(b"\r\n<sw>")
                self.assertEqual(self.p.snapshot()["stage"], "done")

    def test_industrial_family_alone_or_a_conflicting_board_cannot_enable_automatic_answers(self):
        for output in (YUN_INDUSTRIAL_VERSION.rsplit(b"\r\n", 1)[0],
                       YUN_INDUSTRIAL_VERSION.replace(b"S8U4XN", b"S8T4XN"),
                       YUN_INDUSTRIAL_VERSION.replace(b"CloudEngine S5735I", b"CloudEngine S5735"),
                       YUN_INDUSTRIAL_VERSION + b"\r\nS5735I-S8T4XN-V2(Master) 2 : uptime is 1 day"):
            with self.subTest(output=output):
                self.setUp()
                self.rx(b"<sw>")
                self.read_version(output, b"<sw>")
                self.assertFalse(self.p.smart_ready)

    def test_manual_version_accepts_cli_casing_and_one_crlf(self):
        self.rx(b"<sw>")
        self.p.manual(b"DISPLAY VERSION\r\n")
        self.rx(b"DISPLAY VERSION\r\n" + VRP_VERSION + b"\r\n<sw>")
        self.assertTrue(self.p.smart_ready)
        self.p.manual(b"display version\rdisplay clock\r")
        self.rx(b"display version\r\n" + VRP_VERSION + b"\r\n<sw>")
        self.assertFalse(self.p.smart_ready)

    def test_huawei_version_proof_does_not_follow_peer_or_connection(self):
        self.rx(b"<sw>")
        self.read_version(VRP_VERSION, b"<sw>")
        self.assertTrue(self.p.smart_ready)
        self.rx(b"\r\n<other>")
        self.rx(b"\r\n<sw>")
        self.assertFalse(self.p.smart_ready)
        self.read_version(YUN_VERSION, b"<other>")
        self.assertFalse(self.p.smart_ready)
        self.read_version(YUN_VERSION, b"<other>")
        self.assertTrue(self.p.smart_ready)
        self.p.transport_lost("unplugged")
        self.assertFalse(self.p.smart_ready)

    def test_huawei_proof_is_revoked_on_login_or_coalesced_foreign_prompt(self):
        for transition in [b"<other>", b"Username:"]:
            with self.subTest(transition=transition):
                self.setUp()
                self.rx(b"<sw>")
                self.read_version(VRP_VERSION, b"<sw>")
                self.p.preview("display clock", "f", "8n1")
                self.rx(b"\r\n" + transition + b"\r\n<sw>")
                self.assertFalse(self.p.smart_ready)
                self.assertEqual(self.p.snapshot()["stage"], "stopped")
                self.read_version(VRP_VERSION, b"<sw>")
                self.assertTrue(self.p.smart_ready)

    def test_ambiguous_or_unsupported_manual_version_output_cannot_unlock_smart(self):
        for output in [YUN_VERSION.replace(b"V600", b"V200"), VRP_VERSION.replace(b"S5731-", b"S5720-"),
                       VRP_VERSION + b"\r\nHuawei YunShan OS", VRP_VERSION + b"\r\n<another-peer>",
                       VRP_VERSION + b"\r\nHUAWEI S5731-Z99 Routing Switch uptime is 1 day",
                       YUN_VERSION + b"\r\nError: command failed", b"Huawei YunShan OS"]:
            with self.subTest(output=output):
                self.setUp()
                self.rx(b"<sw>")
                self.read_version(output, b"<sw>")
                self.assertFalse(self.p.smart_ready)

    def test_smart_stops_after_rename_until_the_new_hostname_is_identified(self):
        self.start("system-view\nsysname changed\ndisplay clock", b"<sw>", mode="smart", version=VRP_VERSION)
        self.submit(b"system-view")
        self.rx(b"\r\n[sw]")
        self.now += .2
        self.p.tick(self.now)
        self.submit(b"sysname changed")
        self.rx(b"\r\n[changed]")
        self.now += .2
        self.p.tick(self.now)
        self.assertFalse(self.p.smart_ready)
        self.assertEqual(self.p.snapshot()["done"], 2)
        self.assertEqual(self.p.snapshot()["stage"], "uncertain")
        self.assertIsNone(self.p.platform)
        self.assertEqual(self.p.take_output(), b"")

    def test_in_file_version_recheck_stops_smart_if_platform_changes_or_is_unknown(self):
        for reply in [VRP_VERSION, b"unsupported"]:
            with self.subTest(reply=reply):
                self.setUp()
                self.start("display version\ndisplay clock", b"<sw>", mode="smart", version=YUN_VERSION)
                self.submit(b"display version")
                self.rx(b"\r\n" + reply + b"\r\n<sw>")
                self.now += .2
                self.p.tick(self.now)
                self.assertEqual(self.p.snapshot()["stage"], "uncertain")
                self.assertEqual(self.p.take_output(), b"")

    def test_model_change_at_same_hostname_invalidates_a_reviewed_file(self):
        self.rx(b"<sw>")
        self.read_version(VRP_VERSION, b"<sw>")
        self.p.preview("system-view", "f", "8n1")
        self.read_version(YUN_VERSION, b"<sw>")
        self.assertTrue(self.p.smart_ready)
        self.assertEqual(self.p.snapshot()["stage"], "stopped")
        with self.assertRaises(ValueError):
            self.p.action("start", self.now, mode="smart")
        self.assertEqual(self.p.take_output(), b"")

    def test_truncated_paginated_version_cannot_identify_huawei(self):
        self.start("display version", b"<sw>")
        self.submit(b"display version")
        self.rx(b"\r\n" + VRP_VERSION + b"\r\n" + b"x" * 61000 + b"\r\n---- More ----")
        self.p.action("space", self.now)
        self.assertEqual(self.p.take_output(), b" ")
        self.rx(b"\r\n" + b"x" * 9000 + b"\r\n<sw>")
        self.assertEqual(self.p.snapshot()["stage"], "done")
        self.assertFalse(self.p.smart_ready)

    def test_cisco_smart_ready_from_prompt_and_manual_linux_false(self):
        self.rx(b"switch#")
        self.assertTrue(self.p.smart_ready)
        self.rx(b"\r\nnode login:")
        self.assertFalse(self.p.smart_ready)

    def test_last_confirmed_source_line_survives_loss_during_next_command(self):
        self.start("!\n\nshow clock\n! skip\nshow version")
        self.assertIsNone(self.p.snapshot().get("last_done_line"))
        self.submit(b"show clock")
        self.rx(b"\r\nswitch#")
        self.p.action("next", self.now)
        self.submit(b"show version")
        self.p.transport_lost("unplugged")
        snap = self.p.snapshot()
        self.assertEqual((snap["done"], snap["last_done_line"], snap["line"]), (1, 3, 5))

    def test_counter_tracks_fresh_prompts_including_repeated_hostname_only(self):
        self.assertEqual(self.p.prompt_observations, 0)
        self.rx(b"node login: ")
        self.rx(b" ")
        self.p.tick(self.now + 5)
        self.assertEqual(self.p.prompt_observations, 1)
        self.rx(b"\r\nWelcome")
        self.assertEqual(self.p.prompt_observations, 1)
        self.rx(b"\r\nnode login: ")
        self.assertEqual(self.p.prompt_observations, 2)
        self.rx(b"\r\nswitch#")
        self.rx(b"\x1b[0m ")
        self.assertEqual(self.p.prompt_observations, 3)
        self.p.preview("show clok", "f", "8n1")
        self.p.action("start", self.now, mode="step")
        self.submit(b"show clok")
        self.assertEqual(self.p.prompt_observations, 3)
        self.rx(b"\r\n% Invalid input\r\nswitch#")
        self.assertEqual(self.p.prompt_observations, 4)
        self.p.action("correct", self.now, value="show clock")
        self.submit(b"show clock")
        self.rx(b"\r\nswitch#")
        self.rx(b" ")
        self.assertEqual(self.p.prompt_observations, 5)

    def test_physical_line_limit_in_encoded_bytes_before_start(self):
        self.rx(b"switch#")
        self.p.preview("x" * 8192, "c", "8n1")
        self.assertEqual(len(self.p.page(0)["rows"][0]["line"]), 8192)
        with self.assertRaisesRegex(ValueError, r"Line 3.*8192"):
            self.p.preview("show clock\n! blank\n" + "é" * 4097, "c", "8n1")
        self.assertEqual(self.p.take_output(), b"")

    def test_many_small_commands_and_last_preview_page(self):
        self.rx(b"switch#")
        self.p.preview("!\r\n" + "x\r\n" * 25000, "c", "8n1")
        self.assertEqual(self.p.snapshot()["total"], 25000)
        page = self.p.page(2083)
        self.assertEqual(page["pages"], 2084)
        self.assertEqual([r["num"] for r in page["rows"]], list(range(24998, 25002)))

    def test_linux_custom_shell_or_bootloader_requires_manual_input(self):
        self.rx(b"node login:")
        self.rx(b"\r\nnode#")
        self.assertFalse(self.p.ready)
        for loader in [b"grub>", b"loader>", b"rommon>"]:
            self.rx(b"\r\n" + loader)
            self.assertFalse(self.p.ready)

    def test_indented_prompt_output_does_not_release_a_command(self):
        self.start("show version\nshow clock", mode="smart")
        self.submit(b"show version")
        self.rx(b"\r\n    switch#")
        self.p.tick(self.now + 1)
        self.assertEqual(self.p.snapshot()["done"], 0)
        self.assertEqual(self.p.take_output(), b"")

    def test_foreign_prompt_alongside_error_cannot_arm_command_correction(self):
        self.start("show clok")
        self.submit(b"show clok")
        self.rx(b"\r\n% Invalid input\r\nother#\r\nswitch#")
        self.assertEqual(self.p.snapshot()["stage"], "uncertain")
        with self.assertRaises(ValueError):
            self.p.action("correct", self.now, value="show clock")

    def test_partial_pager_erase_and_fragmented_sgr(self):
        self.start("show version", mode="smart")
        self.submit(b"show version")
        self.rx(b"\r\n--More--")
        self.assertEqual(self.p.take_output(), b" ")
        self.rx(b"\b" * 3)
        self.assertEqual(self.p.snapshot()["stage"], "awaiting")
        self.rx(b"\b" * 5 + b" " * 8 + b"\b" * 8 + b"more\r\n\x1b[32mswitch#\x1b[0m")
        self.assertEqual(self.p.snapshot()["stage"], "done")

    def test_catalyst_padded_pager_can_be_erased_across_usb_reads(self):
        self.start("show version", mode="smart")
        self.submit(b"show version")
        self.rx(b"\r\nCisco IOS Software\r\n --More-- ")
        self.assertEqual(self.p.take_output(), b" ")
        self.rx(b"\b" * 9 + b" " * 3)
        self.assertEqual(self.p.snapshot()["stage"], "awaiting")
        self.assertEqual(self.p.take_output(), b"")
        self.rx(b" " * 5 + b"\b" * 9 + b"Model number: WS-C2960CX-8PC-L\r\nswitch#")
        self.assertEqual(self.p.snapshot()["stage"], "done")
        self.assertEqual(self.p.snapshot()["pages"], 1)

    def test_unexpected_catalyst_pager_erase_stops_before_next_command(self):
        for marker, middle in [(b"--More--", 8), (b" --More-- ", 7)]:
            with self.subTest(marker=marker, middle=middle):
                self.setUp()
                self.start("show version\nshow clock", mode="smart")
                self.submit(b"show version")
                self.rx(b"\r\n" + marker)
                self.assertEqual(self.p.take_output(), b" ")
                self.rx(b"\b" * 9 + b" " * middle + b"\b" * 9 + b"\r\nswitch#")
                self.assertEqual(self.p.snapshot()["stage"], "uncertain")
                self.assertEqual(self.p.snapshot()["done"], 0)
                self.assertEqual(self.p.take_output(), b"")

    def test_cisco_version_reference_is_output_with_and_without_paging(self):
        notice = b"A summary of U.S. laws governing Cisco cryptographic products may be found at:"
        for paging in (False, True):
            with self.subTest(paging=paging):
                self.setUp()
                self.start("show version", mode="smart")
                self.submit(b"show version")
                if paging:
                    self.rx(b"\r\n --More-- ")
                    self.assertEqual(self.p.take_output(), b" ")
                self.rx(b"\r\n" + notice)
                self.rx(b"\r\nhttp://www.cis")
                self.assertEqual(self.p.snapshot()["stage"], "awaiting")
                self.assertEqual(self.p.take_output(), b"")
                self.rx(b"co.com/wwl/export/crypto/tool/stqrg.html\r\nswitch#")
                self.assertEqual(self.p.snapshot()["stage"], "done")

    def test_reference_link_does_not_hide_an_interactive_prompt(self):
        self.start("show version\nshow clock", mode="smart")
        self.submit(b"show version")
        self.rx(b"\r\nPassword:\r\nhttp://www.cisco.com/wwl/export/crypto/tool/stqrg.html\r\nswitch#")
        self.assertEqual(self.p.snapshot()["stage"], "uncertain")
        self.assertEqual(self.p.take_output(), b"")

    def test_carriage_return_overwrite_does_not_become_a_new_prompt_line(self):
        self.start("show clock\nshow clock", mode="smart")
        self.submit(b"show clock")
        self.rx(b"\r\nsome-visible-output\rswitch#")
        self.assertEqual(self.p.snapshot()["stage"], "uncertain")
        self.assertEqual(self.p.take_output(), b"")

    def test_slow_split_crlf_in_fresh_response(self):
        self.start("show clock")
        self.submit(b"show clock")
        self.rx(b"\r")
        self.assertEqual(self.p.snapshot()["stage"], "awaiting")
        self.rx(b"\nswitch#")
        self.assertEqual(self.p.snapshot()["stage"], "done")

    def test_short_frames_cannot_encode_command_text(self):
        self.rx(b"switch#")
        for frame in ("5n1", "6e2", "auto", None):
            with self.subTest(frame=frame), self.assertRaises(ValueError):
                self.p.preview("show clock", "f", frame)
        self.assertEqual(self.p.take_output(), b"")

    def test_explicit_no_holds_next_line_and_stop_scrubs_payload(self):
        self.start("save\ndisplay clock", b"<sw>", mode="smart", version=VRP_VERSION)
        self.submit(b"save")
        self.rx(b"\r\nContinue? [Y/N]:")
        self.p.action("no", self.now)
        self.submit(b"n")
        self.rx(b"\r\nCancelled.\r\n<sw>")
        self.p.tick(self.now + 1)
        self.assertEqual(self.p.snapshot()["stage"], "between")
        self.assertTrue(self.p.snapshot()["hold"])
        self.assertEqual(self.p.take_output(), b"")
        self.p.action("next", self.now + 1)
        self.p.action("stop", self.now + 1)
        self.assertEqual(self.p.take_output(), b"")

    def test_absolute_timeout_on_continuous_partial_output_and_bound_reply(self):
        for oversized in (True, False):
            with self.subTest(oversized=oversized):
                self.setUp()
                self.start("show version\nshow clock", mode="smart")
                self.submit(b"show version")
                if oversized:
                    self.rx(b"\r\n" + b"x" * 65536)
                else:
                    self.rx(b"\r\n")
                    for _ in range(31):
                        self.now += 4
                        self.p.feed(b"line\r\n", self.now)
                self.assertEqual(self.p.snapshot()["stage"], "uncertain")
                self.assertEqual(self.p.snapshot()["done"], 0)
                self.assertEqual(self.p.take_output(), b"")

    def test_smart_preserves_order_in_large_interactive_huawei_file(self):
        descriptions = [f" description Sensor {n:04} " + "a" * 169 for n in range(1500)]
        commands = ["system-view", "interface GE 1/0/1", *descriptions, "quit", "return"]
        self.start("! bench\n" + "\r\n".join(commands), b"<rack-west-02>", mode="smart", version=YUN_VERSION)
        for index, command in enumerate(commands):
            self.assertEqual(self.p.snapshot()["line"], index + 2)
            self.submit(command.encode())
            if command in ("system-view", "quit"):
                prompt = b"[rack-west-02]"
            elif command == "return":
                prompt = b"<rack-west-02>"
            else:
                prompt = b"[rack-west-02-GE1/0/1]"
            self.rx(b"\r\n" + prompt)
            self.now += .1
            self.p.tick(self.now)
        self.assertEqual(self.p.snapshot()["done"], 1504)
        self.assertEqual(self.p.snapshot()["stage"], "done")
        self.assertEqual(self.p.take_output(), b"")


if __name__ == "__main__":
    unittest.main()
