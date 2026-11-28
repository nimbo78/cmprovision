"""In-memory USB console protocol. All parser input is received from the tty."""
from array import array
from dataclasses import dataclass
import re
import unicodedata


MAX_FILE = 1024 * 1024
MAX_REPLY = 65536
CHUNK = 1024
MAX_LINE = 8192
PAGE_SIZE = 12
SETTLE = .060
IDLE = 8
DEADLINE = 120
MAX_CONFIRMATIONS = 8
IOS_NAME = r"[A-Za-z0-9][A-Za-z0-9.-]{0,63}"
HW_NAME = r"[A-Za-z0-9](?:[A-Za-z0-9_. -]{0,62}[A-Za-z0-9_.-])?"
COPY = "copy running-config startup-config"
SHORT_CHOICE = re.compile(r"(?:\[\s*y\s*/\s*n\s*\]|\(\s*y\s*/\s*n\s*\))(?:\s*\[\s*[yn]\s*\])?\s*[:?]?$", re.I)
FULL_CHOICE = re.compile(r"(?:\[\s*yes\s*/\s*no\s*\]|\(\s*yes\s*/\s*no\s*\))(?:\s*\[\s*(?:yes|no)\s*\])?\s*[:?]?$", re.I)
NEW_SESSION = re.compile(r"\binitial configuration dialog\b|(?:^|\s)login:\s*$|^\s*(?:username|password):\s*$", re.I)
WARNING_LINE = re.compile(r"^(?:%\s*)?Warning:", re.I)
BLOCK = re.compile(r"(?:banner\b|macro\s+name\b|crypto\s+pki\s+(?:certificate\s+chain|enroll|authenticate|import)\b|certificate\b|key-string\b|tclsh\b|menu\s+\S+\s+(?:prompt|title)\b)", re.I)
HW_BLOCK = re.compile(r"(?:header\s+(?:login|shell)\s+information|(?:rsa|ecc|dsa)\s+peer-public-key|public-key-code\s+begin|pki\s+.*\bimport)\b", re.I)
IOS_ERROR = re.compile(r"%\s*(?:Invalid input|Incomplete command|Ambiguous command|Unrecognized command|Error|Authorization failed|Access denied|Failed)\b", re.I)
MORE = {"ios": r"--More--", "huawei": r"----\s+More\s+----"}
READ_ONLY = {"ios": r"show\s+(?:interfaces\s+status|version)",
             "huawei": r"display\s+(?:interface\s+brief|version|(?:current|saved)-configuration)"}
IOS_VERSION_NOTICE = "A summary of U.S. laws governing Cisco cryptographic products may be found at:"
IOS_VERSION_URLS = ("http://www.cisco.com/wwl/export/crypto/tool/stqrg.html",
                    "https://www.cisco.com/wwl/export/crypto/tool/stqrg.html")


@dataclass(frozen=True)
class Prompt:
    family: str
    host: str
    view: str


def parse_prompt(line, anchor=None):
    ios = re.fullmatch(rf"({IOS_NAME})(\([A-Za-z0-9-]+\))?([>#])", line)
    if ios:
        if ios[1].lower() in {"grub", "loader", "rommon", "u-boot"}:
            return None
        return Prompt("ios", ios[1], (ios[2] or "") + ios[3])
    user = re.fullmatch(rf"<({HW_NAME})>", line)
    if user:
        return Prompt("huawei", user[1], "user")
    bracket = re.fullmatch(r"\[([A-Za-z0-9][A-Za-z0-9_. /-]{0,145})\]", line)
    if bracket:
        body = bracket[1]
        host = anchor or (body if body.upper() == "HUAWEI" else None)
        if host and body == host:
            return Prompt("huawei", host, "system")
        if host and body.startswith(host + "-") and body[len(host) + 1:]:
            return Prompt("huawei", host, body[len(host) + 1:])
    return None


def hw_view(cmd):
    iface = re.fullmatch(r"interface\s+([a-z-]+|[1-9]\d*ge)\s*(\d+(?:/\d+){0,4}(?:\.\d+)?)", cmd, re.I)
    if iface:
        return iface[1] + iface[2]
    vlan = re.fullmatch(r"vlan\s+(\d+)", cmd, re.I)
    if vlan:
        return "vlan" + vlan[1]
    vty = re.fullmatch(r"user-interface\s+vty\s+(\d+)(?:\s+(\d+))?", cmd, re.I)
    if vty:
        return "ui-vty" + vty[1] + ("-" + vty[2] if vty[2] else "")
    return "aaa" if cmd.lower() == "aaa" else None


def normalized_view(view):
    return re.sub(r"^(x?)gigabitethernet(?=\d)", r"\1ge", view.lower())


def expected_prompt(before, cmd, tail):
    hw = before.family == "huawei"
    rename = re.fullmatch(rf"sysname\s+({HW_NAME})" if hw else rf"hostname\s+({IOS_NAME})", cmd, re.I)
    host = rename[1] if rename else before.host
    result = parse_prompt(tail, host)
    if not result or (result.family, result.host) != (before.family, host):
        return None
    command = cmd.lower()
    if hw:
        target = hw_view(cmd) or before.view
        if command == "system-view":
            target = "system"
        elif command == "return":
            target = "user"
        elif command == "quit":
            if before.view == "user":
                return None
            target = "user" if before.view == "system" else "system"
        return result if normalized_view(result.view) == normalized_view(target) else None
    if command in ("configure terminal", "end"):
        view = "(config)#" if command == "configure terminal" else "#"
    elif re.match(r"interface\s+", command):
        view = "(config-if-range)#" if re.match(r"interface\s+range\s+", command) else "(config-if)#"
    elif re.match(r"vlan\s+", command):
        view = "(config-vlan)#"
    elif command == "exit":
        view = "#" if before.view == "(config)#" else "(config)#" if before.view.startswith("(config-") else None
    else:
        view = before.view
    return result if result.view == view else None


def version_label(text, family):
    if family == "ios":
        return "Cisco IOS" if re.search(r"^Cisco IOS(?: XE)? Software\b", text, re.I | re.M) else None
    yun = bool(re.search(r"^Huawei YunShan OS\b", text, re.I | re.M))
    vrp = bool(re.search(r"^(?:Huawei Versatile Routing Platform Software\b|VRP \(R\) software,\s*Version\b)", text, re.I | re.M))
    if yun == vrp or re.search(r"^[ \t]*(?:%|(?:Error|Password|Username|Login)\s*:|<[^\n]+>[ \t]*$|\[[^\n]+\][ \t]*$)", text, re.I | re.M):
        return None
    model_pattern = r"(S5731-[A-Z0-9-]+|S5735I?-S[A-Z0-9-]*-V2)"
    chassis = re.findall(r"^(?:HUAWEI[ \t]+)?(?:CloudEngine[ \t]+)?" + model_pattern +
                         r"(?:[ \t]+(?:Routing Switch|Switch|Device))?[ \t]+uptime\s+is\b", text, re.I | re.M)
    master = re.findall(r"^[ \t]*" + model_pattern + r"[ \t]*\(Master\)[ \t]*\d+[ \t]*:[ \t]*uptime\s+is\b", text, re.I | re.M)
    products = {name.upper() for name in chassis + master}
    for family in ("S5735-S", "S5735I-S"):
        if len(products) == 2 and family + "-V2" in products and all(p.startswith(family) for p in products):
            products.remove(family + "-V2")
    releases = {v.upper() for v in re.findall(r"\b(V(?:200|600)R\d{3}C\d{2}(?:SPC\d+)?)\b", text, re.I)}
    if len(products) != 1 or len(releases) != 1:
        return None
    model, version = products.pop(), releases.pop()
    if vrp and model.startswith("S5731-") and version.startswith("V200"):
        return f"{model} · VRP {version}"
    if yun and (model.startswith("S5735-S") or model == "S5735I-S8U4XN-V2") and version.startswith("V600"):
        return f"{model} · YunShan OS {version}"
    return None


def has_control(line):
    return any(unicodedata.category(ch) in {"Cc", "Cf", "Cs", "Zl", "Zp"} for ch in line)


class FileRows:
    """Retain one text buffer and two integers per executable line."""

    def __init__(self, text):
        self.text = text
        self.index = array("i")
        self.default_line = 0

    def append(self, start, num, separator):
        # A negative physical line number records a preceding Huawei separator.
        self.index.extend((start, -num if separator else num))

    def __len__(self):
        return len(self.index) // 2

    def __getitem__(self, position):
        start, num = self.index[2 * position], self.index[2 * position + 1]
        end = self.text.index("\n", start)
        return abs(num), self.text[start:end], num < 0


def preflight(text, frame, family):
    if not isinstance(frame, str) or not re.fullmatch("[78][neo][12]", frame, re.I):
        raise ValueError("Text transfer requires a confirmed seven or eight data bit frame.")
    if not isinstance(text, str) or len(text) > MAX_FILE:
        raise ValueError("Choose a UTF-8 file of at most 1 MiB.")
    try:
        size = len(text.encode("utf-8"))
    except UnicodeError:
        raise ValueError("Choose a valid UTF-8 file.") from None
    if size > MAX_FILE:
        raise ValueError("Choose a UTF-8 file of at most 1 MiB.")
    physical = text.removeprefix("\ufeff").replace("\r\n", "\n").replace("\r", "\n") + "\n"
    rows = FileRows(physical)
    separator = nested = False
    for num, match in enumerate(re.finditer(r"([^\n]*)\n", physical), 1):
        line = match[1]
        if has_control(line):
            raise ValueError(f"Line {num} contains a control character.")
        cmd = line.strip()
        if not cmd:
            continue
        if family == "huawei":
            if re.match(r"!(?:Software\s+Version|Last\s+configuration|Configuration)\b", cmd, re.I):
                raise ValueError(f"Line {num} begins a saved configuration. Supply interactive commands.")
            if cmd == "#":
                separator = True
                continue
            if cmd.startswith("#") or HW_BLOCK.match(cmd):
                raise ValueError(f"Line {num} begins an unsupported configuration block.")
            change = cmd.lower() in ("quit", "return", "system-view")
            if separator and (nested or not rows) and not change and not re.match(r"display\s+\S+", cmd, re.I):
                raise ValueError(f"Line {num} needs an explicit view change.")
            if hw_view(cmd):
                nested = True
            elif change:
                nested = False
        if cmd.startswith("!"):
            continue
        if len(line.encode()) > MAX_LINE:
            raise ValueError(f"Line {num} exceeds 8192 UTF-8 bytes.")
        if frame.startswith("7") and not line.isascii():
            raise ValueError(f"Line {num} contains text incompatible with seven data bits.")
        banner = re.fullmatch(r"banner\s+(?:motd|login|exec|incoming)\s+([^\w\s])(.*?)\1", cmd, re.I)
        if BLOCK.match(cmd) and (not banner or banner[1] in banner[2]):
            raise ValueError(f"Line {num} begins an interactive text block.")
        rows.append(match.start(), num, separator)
        if family == "ios" and not rows.default_line and cmd.lower() == COPY:
            rows.default_line = num
        separator = False
    if not rows:
        raise ValueError("No command lines found.")
    return rows


def question(tail, family):
    if SHORT_CHOICE.search(tail) or FULL_CHOICE.search(tail):
        return "yesno"
    if family == "huawei":
        if re.fullmatch(r"(?:Info:\s*)?(?:Please\s+(?:input|enter)|Enter|Input)\b[^?]*\bfile\s*name\b[^?]*\[[^\]]+\]\s*[:?]", tail, re.I):
            return "filename"
    else:
        if re.search(r"\[confirm\]\s*[:?]?$", tail, re.I) or re.fullmatch(r"(?:Destination|Source|Delete) filename \[[^\]]+\]\?", tail, re.I):
            return "enter"
    return "pager" if re.fullmatch(MORE[family], tail, re.I) else ""


def screen_lines(data):
    # Accept SGR colour only. Cursor movement, malformed UTF-8 and redraws
    # cannot be used as evidence of a completed command.
    text = re.sub(rb"\x1b\[[0-9;]*m", b"", data).decode("utf-8", errors="replace")
    return re.sub(r"\r+\n", "\n", text).split("\n")


def looks_interactive(line):
    return bool(re.search(r"[?:]$|^(?:<.+>|\[.+\])$|^[^\s]+[#$>]$", line))


class ConsoleProtocol:
    def __init__(self):
        self.hostname = self.platform = self._prompt = None
        self.prompt_observations = 0
        self._counted_line = False
        self._context = self._version_context = None
        self._version_typed = None
        self._version_seen = False
        self._manual_rx = bytearray()
        self._manual_input = False
        self._hw_anchor = None
        self._linux_host = None
        self._settle_at = None
        self._stage = None
        self._rows = ()
        self._rx = bytearray()
        self._tx = self._payload = b""
        self._lost = False

    @property
    def ready(self):
        return self._prompt is not None and not self._lost

    @property
    def smart_ready(self):
        return self.ready and (self._prompt.family == "ios" or self.platform is not None)

    @property
    def version_command(self):
        if self.ready:
            return "display version" if self._prompt.family == "huawei" else "show version"
        return None

    def _owns(self):
        return self._stage not in (None, "review", "done", "stopped")

    def snapshot(self):
        if self._stage is None:
            return None
        return dict(stage=self._stage, file=self._file, total=self._total, done=self._done, last_done_line=self._last_done_line,
                    line=self._line, text=self._text, notice=self._notice, answer_kind=self._kind,
                    pages=self._pages, auto_answers=self._auto_answers, hold=self._hold, mode=self._mode, default_line=self._default)

    def preview(self, text, filename, frame):
        if self._lost or self._owns():
            raise ValueError("Stop the current transfer first.")
        if not self.ready:
            raise ValueError("Review the file at an authenticated Cisco or Huawei prompt.")
        if not isinstance(filename, str) or len(filename) > 255 or has_control(filename):
            raise ValueError("Invalid file name.")
        self._stage = None
        self._rows = ()
        self._text = ""
        self._context = self._prompt
        self._review_platform = self.platform
        self._frame = frame
        self._rows = preflight(text, frame, self._context.family)
        self._file, self._total = filename, len(self._rows)
        self._default = self._rows.default_line
        self._done = self._line = self._pages = self._auto_answers = 0
        self._last_done_line = None
        self._mode, self._hold = "smart", False
        self._stage, self._notice, self._kind = "review", "", ""

    def page(self, page):
        pages = (len(self._rows) + PAGE_SIZE - 1) // PAGE_SIZE
        if self._stage != "review" or type(page) is not int or not 0 <= page < pages:
            raise ValueError("Invalid review page.")
        start = page * PAGE_SIZE
        rows = []
        for position in range(start, min(start + PAGE_SIZE, len(self._rows))):
            num, line, _ = self._rows[position]
            rows.append({"num": num, "line": line})
        return {"pages": pages, "rows": rows}

    def take_output(self):
        data, self._tx = self._tx, b""
        return data

    def _halt(self, stage, reason):
        self._stage, self._notice, self._kind = stage, reason, ""
        self._tx = self._payload = b""
        self._rx.clear()
        self._settle_at = None
        if stage == "uncertain":
            self._prompt = None
            self.platform = None
        if stage in ("stopped", "done"):
            self._rows = ()
            self._text = self._version = ""
            self._answered = set()

    def transport_lost(self, reason):
        self._lost = True
        self._prompt = self.platform = self._version_context = None
        self._hw_anchor = None
        self._manual_rx.clear()
        if self._stage is not None:
            self._halt("stopped", "Connection lost. Remaining commands were discarded.")

    def manual(self, data):
        if self._lost or self._owns():
            raise ValueError("Manual input is unavailable.")
        # Compare only the read-version command. Never accumulate arbitrary
        # typed text (which could be a password).
        if self.ready:
            self._version_context = self._prompt
            self._version_typed = self.version_command.encode()
            self._version_seen = False
            self._manual_rx.clear()
        self._manual_input = True
        if self._version_typed is not None:
            for position, byte in enumerate(data.lower()):
                if not self._version_typed and byte in (10, 13):
                    self.platform = None
                    self._version_seen = data[position:] in (b"\r", b"\n", b"\r\n")
                    self._version_typed = None
                    break
                if not self._version_typed or byte != self._version_typed[0]:
                    self._version_context = self._version_typed = None
                    self._version_seen = False
                    break
                self._version_typed = self._version_typed[1:]
        elif self._version_seen and data != b" ":
            self._version_seen = False
        self._prompt = None

    def _observe_manual(self):
        lines = screen_lines(self._manual_rx)
        tail = lines[-1].rstrip(" ")
        if self._manual_input and len(lines) == 1:
            return
        identity_changed = False
        if self._hw_anchor or self._stage == "review":
            for line in lines:
                candidate = parse_prompt(line.rstrip(" "), self._hw_anchor)
                if ((candidate and (candidate.host != self.hostname or
                                    (candidate.family == "huawei") != bool(self._hw_anchor)))
                    or re.fullmatch(r"(?:[\w.-]+\s+login|login|username|password):\s*|\[[~*].+\]", line, re.I)):
                    identity_changed = True
                    self.platform = None
                    self._version_seen = False
                    break
        p = parse_prompt(tail, self._hw_anchor)
        if p and p.family == "ios" and p.host == self._linux_host:
            p = None
        if p:
            if p.host != self.hostname or (p.family == "huawei") != bool(self._hw_anchor):
                self.platform = None
            self.hostname = p.host
            self._linux_host = None
            self._hw_anchor = p.host if p.family == "huawei" else None
            self._manual_input = False
            if self._version_seen and p == self._version_context:
                cmd = "display version" if p.family == "huawei" else "show version"
                if lines[0].strip().lower() == cmd:
                    self.platform = version_label("\n".join(lines[1:-1]), p.family)
            self._version_context = self._version_typed = None
            self._version_seen = False
            self._note_prompt(tail)
        else:
            linux = re.fullmatch(rf"({IOS_NAME}) login:", tail) or re.fullmatch(rf"[\w.-]+@({IOS_NAME}):[^\r\n]*[$#]", tail)
            if linux:
                self.hostname, self.platform, self._hw_anchor = linux[1], None, None
                self._linux_host = linux[1]
                self._note_prompt(tail)
        if self._stage == "review":
            changed_platform = p and self._review_platform and self._review_platform != self.platform
            if identity_changed or changed_platform or (self._context != p and (p or looks_interactive(tail))):
                self._halt("stopped", "The device or prompt changed. Review the file again.")
        self._prompt = p

    def _note_prompt(self, tail):
        if not self._counted_line:
            self.prompt_observations += 1
            self._counted_line = True
        self._manual_rx[:] = tail.encode()

    def feed(self, data, now):
        if not data or self._lost:
            return
        if b"\n" in data:
            self._counted_line = False
        if not self._owns():
            self._prompt = None
            self._manual_rx.extend(data)
            if len(self._manual_rx) > MAX_REPLY:
                self._version_seen = False
                # Preserve only complete received lines after truncation.
                tail = self._manual_rx[-MAX_REPLY:]
                self._manual_rx[:] = b"\x00" + tail
            self._settle_at = now + SETTLE
            return
        if self._stage == "uncertain":
            return
        if self._stage != "awaiting" or self._tx:
            self._halt("uncertain", "Unexpected input arrived. Inspect the terminal.")
            return
        if now >= self._deadline or now >= self._idle:
            self._halt("uncertain", "Response timed out. Inspect the device before continuing.")
            return
        self._idle = now + IDLE
        if self._phase == "echo":
            end = self._echo_pos + len(data)
            if end > self._chunk_end or self._payload[self._echo_pos:end] != data:
                self._halt("uncertain", "The received echo differs from the submitted text.")
                return
            self._echo_pos = end
            if end == self._chunk_end:
                self._queue_chunk()
            return
        if len(self._rx) + len(data) > MAX_REPLY:
            self._halt("uncertain", "The device response exceeded the review buffer.")
            return
        if self._slow_reply:
            self._idle = self._deadline
        self._rx.extend(data)
        self._settle_at = now + SETTLE

    def tick(self, now):
        if self._lost:
            return
        if self._stage == "awaiting" and (now >= self._deadline or now >= self._idle):
            self._halt("uncertain", "Response timed out. Inspect the device before continuing.")
        elif self._settle_at is not None and now >= self._settle_at:
            self._settle_at = None
            if self._stage == "awaiting":
                self._review_rx(now)
            elif not self._owns():
                self._observe_manual()
        elif self._stage == "between" and self._mode != "step" and not self._hold and now >= self._advance:
            self._send_line(now)

    def _queue_chunk(self):
        if self._echo_pos == len(self._payload):
            self._payload = b""
            self._phase, self._tx = "reply", b"\r"
            if self._slow_reply:
                self._idle = self._deadline
        else:
            self._chunk_end = min(len(self._payload), self._echo_pos + CHUNK)
            self._tx = self._payload[self._echo_pos:self._chunk_end]

    def _exchange(self, payload, now, pager=False, single_key=False):
        self._prompt = None
        self._manual_rx.clear()
        self._rx.clear()
        self._settle_at = None
        self._stage, self._phase = "awaiting", "pager" if pager else "echo"
        self._kind, self._notice = "", ""
        self._idle = now + IDLE
        if pager:
            self._tx = b" "
            self._pages += 1
            self._line_pages += 1
        elif single_key:
            self._phase = "answer-key"
            self._payload = self._tx = payload.encode()
        else:
            self._payload, self._echo_pos = payload.encode(), 0
            self._queue_chunk()

    def _send_line(self, now, corrected=None):
        self._line, self._text, separator = self._rows[self._done]
        if corrected is not None:
            self._text = corrected
        if self._context != self._prompt or not self.ready:
            self._halt("uncertain", "The expected command prompt is unavailable.")
            return
        if separator and self._context.family == "huawei" and self._context.view not in ("user", "system") and self._text.strip().lower() not in ("quit", "return"):
            self._halt("uncertain", "An explicit quit or return is required at this section.")
            return
        self._line_pages, self._version = 0, ""
        self._answered = set()
        self._deadline = now + DEADLINE
        self._slow_reply = self._mode == "bulk" and bool(re.match(r"(?:save|copy|write|commit)\b", self._text.strip(), re.I))
        self._exchange(self._text, now)

    def _review_rx(self, now):
        data = self._rx
        if self._phase == "answer-key":
            # Some CLIs accept Y immediately; others echo it and wait for Return.
            if data == self._payload:
                self._exchange("", now)
                return
            if data.startswith(self._payload):
                data = data[len(self._payload):]
        if self._phase == "pager":
            for erase in self._pager_erasures:
                if data.startswith(erase):
                    data = data[len(erase):]
                    break
                if erase.startswith(data):
                    return  # The USB read ended inside a known erase sequence.
        lines = screen_lines(data)
        if data.endswith(b"\r"):
            # A USB read can end between CR and LF.
            return
        if self._phase != "pager" and lines[0]:
            self._halt("uncertain", "Expected a fresh line after Enter.")
            return
        # Replacing malformed bytes or interpreting arbitrary cursor escapes
        # would risk matching text which is not at the actual terminal cursor.
        if any("\ufffd" in line or has_control(line.replace("\t", "")) for line in lines):
            self._halt("uncertain", "The terminal redrew its output. Inspect the device.")
            return
        tail = lines[-1].rstrip(" ")
        cmd = self._text.strip()
        family = self._context.family
        bulk = self._mode == "bulk"
        ios_version = family == "ios" and cmd.lower().split() == ["show", "version"]
        if bulk and any(NEW_SESSION.search(s) for s in lines):
            self._halt("uncertain", "The device requested login or initial setup. Inspect the terminal.")
            return
        for index, earlier in enumerate(lines[:-1]):
            earlier = earlier.strip()
            if bulk and WARNING_LINE.match(earlier) and not question(earlier, family):
                continue  # The actual choice can be on the following line.
            if ios_version and earlier == IOS_VERSION_NOTICE:
                link = lines[index + 1].strip()
                if link in IOS_VERSION_URLS:
                    continue
                if index + 2 == len(lines) and any(url.startswith(link) for url in IOS_VERSION_URLS):
                    return  # The USB read ended in the middle of the printed link.
            if earlier != "[OK]" and (question(earlier, family) or looks_interactive(earlier)):
                self._halt("uncertain", "Multiple device prompts arrived in one response.")
                return
        diagnostic = next((line.strip() for line in lines if IOS_ERROR.match(line.strip())
                          or (family == "huawei" and re.match(r"Error\s*:", line.strip(), re.I))), None)
        if diagnostic:
            if parse_prompt(tail, self._context.host) == self._context:
                self._prompt = self._context
                self._note_prompt(tail)
                self._halt("error", diagnostic[:512] + "\n" + tail)
            elif (question(tail.strip(), family) or looks_interactive(tail)) and not tail.startswith(("Error:", "%")):
                self._halt("uncertain", "The device reported an error outside the expected prompt.")
            return
        if any(line.lstrip().startswith("%") and not (bulk and WARNING_LINE.match(line.lstrip())) for line in lines):
            self._halt("uncertain", "Unexpected device diagnostic. Inspect the terminal.")
            return
        kind = question(tail.lstrip(" "), family)
        warning = next((s.strip() for s in lines if family == "huawei" and s.lstrip().lower().startswith("warning:")), "")
        if kind:
            if kind == "pager":
                width = len(lines[-1])
                self._pager_erasures = (b"\b" * width + b" " * width + b"\b" * width,)
                if family == "ios" and lines[-1] == " --More-- ":
                    # Catalyst 2960-CX backs over nine columns and blanks the eight marker characters.
                    self._pager_erasures += (b"\b" * 9 + b" " * 8 + b"\b" * 9,)
            if cmd.lower() == "display version" and self._version is not None:
                self._version += "\n".join(lines[:-1]) + "\n"
                if kind != "pager" or len(self._version) > MAX_REPLY:
                    self._version = None
            if bulk and not self._hold and self._bulk_answer(kind, tail, lines, cmd, now):
                return
            if kind == "pager" and not warning and self._mode == "smart" and not self._hold and self._line_pages < 20 and re.fullmatch(READ_ONLY[family], cmd, re.I):
                self._exchange("", now, pager=True)
            elif (kind == "enter" and self._mode == "smart" and not self._hold and self._allow_default
                  and self._line == self._default and cmd.lower() == COPY and lines[-1] == "Destination filename [startup-config]?"):
                self._allow_default = False
                self._exchange("", now)
            else:
                self._stage, self._kind = "question", kind
                self._notice = (warning[:512] + "\n" if warning and warning != tail else "") + tail[:512]
            return
        if warning and not bulk:
            self._halt("uncertain", warning[:512])
            return
        after = expected_prompt(self._context, cmd, tail)
        if after:
            self._note_prompt(tail)
            old_platform = self.platform
            if after.host != self.hostname:
                self.platform = None
            self._prompt = self._context = after
            self.hostname = after.host
            self._hw_anchor = after.host if family == "huawei" else None
            if cmd.lower() == self.version_command:
                result = self._version + "\n".join(lines[:-1]) if self._version is not None else ""
                self.platform = version_label(result, family) if len(result) <= MAX_REPLY else None
            self._done += 1
            self._last_done_line = self._line
            self._rx.clear()
            if self._done == self._total:
                self._halt("done", "The device returned to its prompt after the final command.")
            elif family == "huawei" and self._mode != "step" and self.platform != old_platform:
                self._halt("uncertain", "Huawei identification changed. Read display version before continuing automatically.")
            else:
                self._stage, self._advance = "between", now + .090
        elif bulk and WARNING_LINE.match(tail.lstrip()):
            return  # Wait for the rest of the warning or its question.
        elif looks_interactive(tail) and not (ios_version and tail == IOS_VERSION_NOTICE):
            self._halt("uncertain", "Unexpected prompt. Inspect the terminal before continuing.")

    def _bulk_answer(self, kind, tail, lines, cmd, now):
        if kind == "pager":
            self._exchange("", now, pager=True)
            return True
        # Commands which print configuration or descriptions can print question-like text as data.
        verb = cmd.lower().removeprefix("do ").lstrip().split()[0]
        if "show".startswith(verb) or "display".startswith(verb) or verb in ("more", "dir"):
            return False
        previous = lines[-2].strip() if len(lines) > 1 else ""
        key = (previous.casefold() if WARNING_LINE.match(previous) else "", tail.strip().casefold())
        if key in self._answered or len(self._answered) >= MAX_CONFIRMATIONS:
            return False
        self._answered.add(key)
        self._auto_answers += 1
        if kind == "yesno":
            self._answer_choice(tail, "yes", now)
        else:
            self._exchange("", now)  # Accept the displayed filename or [confirm].
        return True

    def _answer_choice(self, tail, answer, now):
        short = bool(SHORT_CHOICE.search(tail))
        self._exchange(answer[0] if short else answer, now, single_key=short)

    def action(self, action, now, *, value="", mode="smart", allow_default=False):
        if self._stage is None or self._lost:
            raise ValueError("No available file transfer.")
        if action == "stop":
            self._halt("stopped", "Remaining lines were discarded. Inspect the device for applied commands or unfinished input.")
        elif action == "clear":
            self._halt("stopped", "")
            self._stage = None
        elif action == "start" and self._stage == "review":
            if not self.ready or self._prompt != self._context or mode not in ("bulk", "smart", "step") or type(allow_default) is not bool:
                raise ValueError("Review the file at an authenticated Cisco or Huawei prompt.")
            if mode != "step" and not self.smart_ready:
                raise ValueError("Read display version at this Huawei prompt before starting automatic transfer.")
            self._mode, self._allow_default = mode, allow_default
            self._send_line(now)
        elif action == "pause" and self._stage in ("awaiting", "between") and self._mode != "step":
            self._hold = True
        elif action == "next" and self._stage == "between":
            self._hold = False
            self._send_line(now)
        elif action == "correct" and self._stage == "error":
            if not isinstance(value, str) or "\r" in value or "\n" in value or value.startswith("\ufeff"):
                raise ValueError("Enter one corrected command.")
            preflight(value, self._frame, self._context.family)
            if self._line == self._default:
                self._allow_default = False
            self._send_line(now, corrected=value)
        elif self._stage == "question":
            if self._kind == "yesno" and action in ("yes", "no"):
                if action == "no":
                    self._hold = True
                self._deadline = now + DEADLINE
                self._answer_choice(screen_lines(self._rx)[-1].rstrip(" "), action, now)
                return
            elif self._kind == "enter" and action == "enter" or self._kind == "pager" and action == "space":
                value = ""
            elif self._kind == "filename" and action == "filename":
                if not isinstance(value, str) or len(value) > 255 or value and not re.fullmatch(r"[A-Za-z0-9_.-]+\.(?:cfg|zip)", value, re.I):
                    raise ValueError("Supply a .cfg or .zip basename, or accept the default.")
            else:
                raise ValueError("The answer does not match the pending device question.")
            self._deadline = now + DEADLINE
            self._exchange(value, now, pager=action == "space")
        else:
            raise ValueError("The action does not match the current transfer state.")
