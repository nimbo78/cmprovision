"""Pillow renderer for the HDMI stand display. All drawing is in memory."""
import math
import time
from functools import lru_cache
from pathlib import Path

from PIL import Image, ImageColor, ImageDraw, ImageFont


PUBLIC = Path(__file__).resolve().parent.parent / "public"
if not (PUBLIC / "fonts").is_dir():
    PUBLIC = Path("/var/lib/cmprovision/public")

FONTS = {"regular": "nunito-v16-latin-regular.woff", "bold": "nunito-v16-latin-700.woff",
         "mono": "jetbrains-mono-700.woff2"}
DARK = dict(bg="#161412", surface="#211e1b", raised="#2c2824", line="#403a34", fg="#faf4ec",
            muted="#b6a99b", accent="#ffa565", tint="#3e2b1e", good="#8ad4ad", green="#23392e",
            run="#a0c8f6", blue="#263444", warn="#ebbf72", amber="#3b3121", bad="#faa69a", red="#422a27")
LIGHT = dict(bg="#f8f5f0", surface="#fffdfa", raised="#f4ede4", line="#ded6cc", fg="#29241f",
             muted="#6e655b", accent="#a94812", tint="#fbe7d5", good="#216747", green="#e1f1e7",
             run="#285f9b", blue="#e4eef9", warn="#8a5b12", amber="#faeac6", bad="#a33931", red="#f8e2df")
NEUTRAL_DARK = dict(bg="#121214", surface="#1c1c20", raised="#25252b", line="#45454d", fg="#f7f7fa",
                    muted="#b1b1bc", accent="#a5b4fc", tint="#292941")
NEUTRAL_LIGHT = dict(bg="#f5f5f7", surface="#ffffff", raised="#eeeef1", line="#d9d9df", fg="#27272b",
                     muted="#62626c", accent="#484ba0", tint="#ededfc")
TINT = dict(muted="raised", fg="raised", good="green", run="blue", warn="amber", bad="red",
            accent="tint", local="local_tint")
STATE = dict(ok="good", run="run", fail="bad", stale="warn", recognized="accent")
VIEWS = dict(desk="Stand console", flow="All device cards", engineer="Device diagnostics")


def clean(value):
    """Keep printable station/device names and keep control characters out of text metrics."""
    return "".join(ch if ch.isprintable() else " " for ch in str(value if value is not None else "")[:512])


@lru_cache(maxsize=160)
def face(px, weight):
    try:
        return ImageFont.truetype(str(PUBLIC / "fonts" / FONTS[weight]), px, layout_engine=ImageFont.Layout.BASIC)
    except OSError:
        return ImageFont.load_default(size=px)


@lru_cache(maxsize=1024)
def dashboard_width(text, px, weight):
    return face(px,weight).getlength(text)


@lru_cache(maxsize=256)
def dashboard_mask(text, px, weight, anchor):
    """Cache coverage, independent of colors. At most 8 MiB of 8-bit glyph masks."""
    font = face(px,weight)
    left, top, right, bottom = font.getbbox(text, anchor=anchor)
    width, height = right-left, bottom-top
    if width*height > 32768 or not width or not height:
        return None
    mask = Image.new("L",(width,height))
    ImageDraw.Draw(mask).text((-left,-top),text,font=font,fill=255,anchor=anchor)
    return mask, left, top


@lru_cache(maxsize=32)
def brand(px, variant):
    # Reuse the actual packaged monogram's antialiased ink. The gradient changes
    # with the display mode, while the neighbouring station has a neutral tile.
    start, end, ink = {
        "dark": ("#ffe3b8", "#e9a661", "#2a1a0c"),
        "light": ("#ffd37a", "#e2681c", "#2a1a0c"),
        "peer": ("#afaaa4", "#89847f", "#282523"),
    }[variant]
    with Image.open(PUBLIC / "apple-touch-icon.png") as source:
        mask = source.convert("RGB").getchannel("R").point(lambda red: max(0, min(255, (220-red)*255//178)))
    side = mask.width
    tile = Image.new("RGB", (side, side))
    draw = ImageDraw.Draw(tile)
    a, b = ImageColor.getrgb(start), ImageColor.getrgb(end)
    for i in range(side * 2):
        ratio = min(1, i / (2 * side - 2))
        draw.line((i, 0, 0, i), fill=tuple(round(x+(y-x)*ratio) for x, y in zip(a, b)))
    tile.paste(ink, (0, 0, side, side), mask)
    edge = Image.new("L", tile.size)
    ImageDraw.Draw(edge).rounded_rectangle((0, 0, side-1, side-1), radius=side*13//44, fill=255)
    tile.putalpha(edge)
    return tile.resize((px, px), Image.Resampling.LANCZOS)


@lru_cache(maxsize=100)
def icon(name, px):
    """Monoline symbols from the display design on a 24-unit grid."""
    mask = Image.new("L", (72, 72))
    draw = ImageDraw.Draw(mask)

    def path(*points):
        draw.line([(round(x*3), round(y*3)) for x, y in points], fill=255, width=5, joint="curve")

    def box(box, radius=1):
        draw.rounded_rectangle(tuple(v*3 for v in box), radius=3*radius, outline=255, width=5)

    if name == "chip":
        box((5, 5, 19, 19), 3)
        box((9, 9, 15, 15))
        for n in (9, 15):
            for a, b in ((1, 5), (19, 23)):
                path((n, a), (n, b))
                path((a, n), (b, n))
    elif name == "switch":
        box((1, 5, 23, 19), 3)
        for n in (5, 11, 17):
            path((n, 10), (n+3, 10))
            path((n, 15), (n+1, 15))
    elif name == "rj":
        path((3, 5), (21, 5), (21, 16), (16, 16), (14, 19), (10, 19), (8, 16), (3, 16), (3, 5))
        for n in (7, 10, 14, 17):
            path((n, 5), (n, 10))
    elif name == "sfp":
        box((2, 6, 22, 18))
        box((6, 10, 10, 14))
        box((14, 10, 18, 14))
    elif name == "monitor":
        box((2, 3, 22, 17), 2)
        path((12, 17), (12, 21))
        path((7, 21), (17, 21))
    elif name == "wifi":
        for offset in (0, 5, 10):
            draw.arc((3*(offset-3), 3*(3+offset), 3*(27-offset), 3*(31-offset)),
                     222, 318, fill=255, width=5)
        draw.ellipse((32, 55, 40, 63), fill=255)
    elif name == "warn":
        path((12, 2), (23, 21), (1, 21), (12, 2))
        path((12, 8), (12, 14))
        path((12, 17), (12, 18))
    elif name == "done":
        path((3, 12), (9, 18), (21, 5))
    elif name == "run":
        path((1, 13), (7, 13), (10, 5), (14, 19), (17, 12), (23, 12))
    elif name == "shield":
        path((12, 2), (20, 5), (20, 12), (18, 16), (12, 22), (6, 16), (4, 12), (4, 5), (12, 2))
        path((8, 11), (11, 14), (16, 9))
    elif name == "lock":
        box((4, 10, 20, 21), 2)
        draw.arc((23, 5, 49, 38), 180, 360, fill=255, width=5)
        path((8, 7), (8, 11))
        path((16, 7), (16, 11))
    elif name == "time":
        draw.ellipse((5, 5, 67, 67), outline=255, width=5)
        path((12, 6), (12, 12), (17, 15))
    return mask.resize((px, px), Image.Resampling.LANCZOS)


class Painter:
    """A 900-unit-high workspace; ultrawide displays gain columns at the same reading distance."""
    def __init__(self, size, palette, now, cache_text=True):
        self.scale = min(size[0]/1536, size[1]/900)
        self.w, self.h = (value/self.scale for value in size)
        self.light = palette.endswith("-light")
        base = LIGHT if self.light else DARK
        self.c = dict(base, local=base["accent"], local_tint=base["tint"])
        if palette.startswith("neutral"):
            self.c.update(NEUTRAL_LIGHT if self.light else NEUTRAL_DARK)
        self.image = Image.new("RGB", size, self.c["bg"])
        self.draw = ImageDraw.Draw(self.image)
        self.now = now
        self.cache_text = cache_text

    def px(self, value):
        return round(self.scale*value)

    def measure(self, text, size=18, weight="regular"):
        return self.text_width(text,max(1,self.px(size)),weight)/self.scale

    def text_width(self, text, px, weight):
        if self.cache_text and len(text) <= 512:
            return dashboard_width(text,px,weight)
        return face(px,weight).getlength(text)

    def paint_label(self, xy, text, px, weight, ink, anchor):
        if self.cache_text:
            cached = dashboard_mask(text,px,weight,anchor)
            if cached is not None:
                mask, left, top = cached
                self.draw.bitmap((xy[0]+left,xy[1]+top),mask,fill=self.c[ink])
                return
        self.draw.text(xy,text,font=face(px,weight),fill=self.c[ink],anchor=anchor)

    def label(self, x, y, value, size=18, ink="fg", weight="regular", width=None, align="left", scroll=False):
        text = clean(value)
        if not text:
            return
        px = max(1, self.px(size))
        if width is not None and self.text_width(text,px,weight) > self.px(width):
            # Clip at a character boundary. Long operational values move within
            # their own column; compact overview chips retain their numeric IDs.
            available = self.px(width)-self.text_width("…",px,weight)
            if available < 1:
                return
            start = 0
            if scroll:
                low, high = 0, len(text)
                while low < high:
                    mid = (low+high)//2
                    if self.text_width(text[mid:],px,weight) > available:
                        low = mid+1
                    else:
                        high = mid
                step = int(self.now*2) % (low+10)
                start = max(0, min(low, step-4))
            lo, hi = start, len(text)
            while lo < hi:
                mid = (lo+hi+1)//2
                if self.text_width(text[start:mid],px,weight) <= available:
                    lo = mid
                else:
                    hi = mid-1
            text = text[start:lo] + ("…" if lo < len(text) else "")
        self.paint_label((self.px(x),self.px(y)),text,px,weight,ink,{"left":"lm","right":"rm","center":"mm"}[align])

    def rect(self, x, y, w, h, fill="surface", line=None, radius=0, stroke=1):
        if w <= 0 or h <= 0:
            return
        bounds = (self.px(x), self.px(y), self.px(x+w), self.px(y+h))
        self.draw.rounded_rectangle(bounds, radius=self.px(radius), fill=self.c[fill] if fill else None,
                                    outline=self.c[line] if line else None, width=max(1, self.px(stroke)))

    def line(self, x, y, x2, y2, color="line"):
        self.draw.line(tuple(self.px(v) for v in (x,y,x2,y2)), fill=self.c[color], width=max(1,self.px(1)))

    def symbol(self, name, x, y, size, ink="muted"):
        mask = icon(name, max(1, self.px(size)))
        self.draw.bitmap((self.px(x), self.px(y)), mask, fill=self.c[ink])

    def mark(self, x, y, size, peer=False):
        tile = brand(max(1, self.px(size)), "peer" if peer else ("light" if self.light else "dark"))
        self.image.paste(tile, (self.px(x), self.px(y)), tile)

    def chip(self, x, y, value, tone="muted", size=16, symbol=None, outlined=False):
        padding, image_w = 9, size+6 if symbol else 0
        width, height = self.measure(value, size, "bold")+2*padding+image_w, size+10
        self.rect(x, y-height/2, width, height, "surface" if outlined else TINT[tone],
                  "line" if outlined else None, radius=6)
        if symbol:
            self.symbol(symbol, x+padding, y-size/2, size, tone)
        self.label(x+padding+image_w, y, value, size, tone, "bold")
        return width

    def icon_box(self, name, x, y):
        self.rect(x, y, 39, 39, "raised", "line", 9)
        self.symbol(name, x+8, y+8, 23)

    def bar(self, x, y, width, percent, tone):
        self.rect(x, y, width, 6, "line", radius=3)
        if isinstance(percent, (int,float)) and percent > 0:
            self.rect(x, y, width*min(percent,100)/100, 6, tone, radius=3)

    def paragraph(self, x, y, text, width, rows=2, size=18, ink="fg"):
        words = clean(text).split()
        for row in range(rows):
            if not words:
                break
            end = 1
            while end < len(words) and self.measure(" ".join(words[:end+1]), size) <= width:
                end += 1
            if row == rows-1:
                end = len(words)
            self.label(x, y+row*(size+5), " ".join(words[:end]), size, ink, width=width, scroll=row == rows-1)
            words = words[end:]


class PortMap:
    """Front-panel pages retain physical columns and carry the owning switch for every port."""
    def __init__(self, switches, cards, columns):
        self.switches = switches
        self.pages = []
        self.by_serial = {}
        self.by_label = {}
        self.modules = {m["serial"]:m for m in cards if m.get("serial")}
        columns = max(1, columns)
        for index, switch in enumerate(switches):
            groups, used = [], 0
            for block in switch.get("blocks") or []:
                rows = 2 if block.get("rows") in (2,"2") else 1
                ports = block.get("ports") or []
                for offset in range(0, len(ports), columns*rows):
                    chunk = ports[offset:offset+columns*rows]
                    needed = math.ceil(len(chunk)/rows)
                    if groups and used+needed > columns:
                        self.pages.append((index, groups))
                        groups, used = [], 0
                    groups.append(dict(label=block.get("label") or "Ports", rows=rows, ports=chunk))
                    used += needed
                    for port in chunk:
                        name = port.get("name") or ""
                        location = (index, name, len(self.pages))
                        label = (clean(switch.get("name"))+" "+name).strip()
                        # When two switches report the same name, only the serial
                        # can disambiguate identical interface names.
                        self.by_label[label] = None if label in self.by_label else location
                        for serial in port.get("serials") or []:
                            self.by_serial[serial] = location
            self.pages.append((index, groups))   # even when the switch has never answered

    def location(self, card):
        if not card:
            return None
        return self.by_serial.get(card.get("serial")) or self.by_label.get(card.get("port"))

    def owner(self, card):
        location = self.location(card)
        return location[:2] if location else None

    def card_port(self, card):
        owner = self.owner(card)
        if owner is None:
            return clean(card.get("port") or "Port unknown")
        index, name = owner
        return ("#%d · " % (index+1) if len(self.switches)>1 else "") + clean(self.switches[index].get("name"))+" "+clean(name)

    def draw_location(self, p, card, x, y, width, size=15, ink="muted"):
        text = self.card_port(card)
        owner = self.owner(card)
        if not owner or p.measure(text,size,"mono") <= width:
            p.label(x,y,text,size,ink,"mono",width,scroll=True)
            return
        index, port = owner
        # Keep an unambiguous switch and port visible even when the hostname is
        # much longer than the card. Only the switch name moves.
        anchor = "#%d · %s" % (index+1,port)
        pinned = min(width,p.measure(anchor,size,"mono")+2)
        p.label(x,y,anchor,size,ink,"mono",pinned)
        p.label(x+pinned+9,y,self.switches[index].get("name"),size,ink,"mono",width-pinned-9,scroll=True)

    def page(self, card, card_count, tick, offset):
        if not self.pages:
            return None
        # First accompany every selected device; then tour the entire bench,
        # including uncabled banks and switches without a saved front panel.
        phase = tick % (card_count+len(self.pages))
        location = self.location(card)
        page = (location[2] if location else tick) if phase < card_count else phase-card_count
        return (page+offset) % len(self.pages)


def tone(m):
    return "warn" if m.get("slow") and m.get("state") == "run" else STATE.get(m.get("state"), "muted")


def phase(board, m):
    raw = board.phase(m)
    return {"WRITE":"Writing", "VERIFY":"Verifying", "FIRSTBOOT":"First boot", "NO REPORT":"No report",
            "OUR SENSOR":"Our sensor"}.get(raw, raw.capitalize())


def phase_icon(m):
    return {"run":"shield" if m.get("phase") == "verify" else "run", "ok":"done", "recognized":"shield",
            "fail":"warn", "stale":"warn"}.get(m.get("state"))


def identity(board, m):
    parts = [board.identity(m)]
    if m.get("hostname") and m.get("serial"):
        parts.append("S/N "+clean(m["serial"]))
    return " · ".join(v for v in parts if v)


def result(board, m):
    if m.get("state") in ("fail","stale","recognized"):
        return m.get("detail") or phase(board, m)
    return board.progress_text(m) or ((m.get("detail") or "Cycle "+board.duration(m.get("cycle"))) + board.slow_suffix(m))


def snapshot_note(board, m, now):
    diag = m.get("diagnostics") or {}
    if not diag:
        return "No diagnostic snapshot yet"
    return "Saved snapshot · %s · %s" % (clean(diag.get("source") or "Unknown source"), board.age(diag.get("received_at"), now))


def header(p, board, data):
    margin, right = 42, p.w-42
    p.rect(0, 0, p.w, 65)
    p.line(0, 65, p.w, 65)
    p.mark(margin, 13, 39)
    p.label(97, 33, "cmprovision", 26, weight="bold")
    p.line(247, 21, 247, 45)
    p.label(266, 33, data.get("host"), 18, "muted", "mono", width=p.w*.36, scroll=True)
    clock = clean(data.get("clock"))
    if len(clock) >= 11 and clock[4:5] == "-":
        clock = clock[11:]
    width = p.measure(clock, 16, "mono")
    p.label(right, 33, clock, 16, "fg", "mono", align="right")
    bar = right-width-18
    p.line(bar, 21, bar, 45)
    updated = "Updated "+board.age(data["generated_at"], p.now) if data.get("generated_at") else "Awaiting snapshot"
    stale = p.now-(data.get("generated_at") or 0)>10
    p.label(bar-16, 33, updated, 17, "muted", align="right")
    dot = bar-29-p.measure(updated, 17)
    p.rect(dot-3, 29, 7, 7, "warn" if stale else "good", radius=4)
    p.line(dot-19, 21, dot-19, 45)
    p.symbol("monitor", dot-111, 23, 20)
    p.label(dot-85, 33, "HDMI", 17, "muted")

    counts = data.get("counts") or {}
    counters = (("Local", len(data.get("modules") or []), "fg"), ("Ours",len(data.get("recognized") or []),"accent"),
                ("Done",counts.get("done",0),"good"), ("Running",counts.get("running",0),"run"),
                ("Failed",counts.get("failed",0),"bad"), ("Silent",counts.get("stale",0),"warn"))
    counter_start = right-6*84
    p.label(margin, 105, "Provisioning stand", 38, weight="bold")
    p.label(margin, 146, "Project", 17, "muted")
    project = clean(data.get("project") or "None active")
    project_width = min(p.w*.28, max(90,p.measure(project,17,"bold")))
    p.label(margin+59, 146, project, 17, weight="bold", width=project_width, scroll=True)
    rescue = board.rescue_banner(data)
    if rescue:
        x = margin+59+project_width+15
        p.line(x, 138, x, 154)
        p.symbol("wifi", x+12, 137, 18)
        state,_,more = rescue.removeprefix("AP ").partition(" / ")
        p.label(x+36,146,"Rescue AP · "+state.lower()+(" · "+more if more else ""),16,"muted",
                width=counter_start-x-53,scroll=True)
    for i, (name,value,color) in enumerate(counters):
        x = counter_start+84*i
        if i:
            p.line(x, 91, x, 146)
        p.label(x+14, 107, str(value), 31, color if value else "muted", "bold")
        p.label(x+14, 136, name, 16, "muted", "bold")

    connections(p,board,data)


def connections(p,board,data):
    margin, right = 42, p.w-42
    p.rect(margin, 171, right-margin, 49, "surface", "line", 10)
    segments, connected = board.network_segments(data)
    if not segments:
        segments = ["UPLINK / No network data"]
    # Extra interfaces stay accessible by scrolling the last segment while the
    # management and stand links keep their places.
    segments = segments[:2]+["   |   ".join(segments[2:])] if len(segments)>3 else segments
    entries = []
    for segment in segments:
        role, _, info = segment.partition(" ")
        fields = []
        if " | " not in info and " / " in info and not info.startswith("/ "):
            device, _, rest = info.partition(" / ")
            medium, _, address = rest.rpartition(" / ")
            fields.append((device,15,"fg","mono"))
            if medium.startswith("Wi-Fi"):
                wifi, _, signal = medium.rpartition(" / ")
                # The SSID can itself contain the delimiter.
                fields.append((wifi or medium,17,"fg","regular"))
                if wifi:
                    fields.append((signal,16,signal_quality(role,signal),"bold"))
            elif medium:
                fields.append((medium.replace("UP ", "Up · "),17,"fg","regular"))
            fields.append((address,15,"muted","mono"))
        else:
            fields.append((info.removeprefix("/ "),16,"fg","regular"))
        width = p.measure(role,13,"bold")+59+sum(p.measure(v,s,f)+10 for v,s,_,f in fields)
        entries.append((role,info,fields,width))
    room = right-margin-2
    shrink = min(1, room/sum(entry[-1] for entry in entries))
    x = margin+1
    for i, (role,info,fields,span) in enumerate(entries):
        w = span*shrink
        symbol = "wifi" if "Wi-Fi" in info else "rj"
        p.symbol(symbol, x+16, 185, 20, ("good" if connected else "warn") if i == 0 else "muted")
        p.label(x+45, 196, role, 13, "muted", "bold")
        detail_x = x+53+p.measure(role,13,"bold")
        if shrink == 1:
            for text, size, ink, weight in fields:
                p.label(detail_x,196,text,size,ink,weight)
                detail_x += p.measure(text,size,weight)+10
        else:
            p.label(detail_x,196,info.removeprefix("/ "),16,width=x+w-detail_x-12,scroll=True)
        x += w
        if i < len(entries)-1:
            p.line(x, 185, x, 207)


def signal_quality(role, signal):
    """Color measured management RSSI with the thresholds shared by WifiLink and HyperPixel."""
    if role != "UPLINK" or not signal.endswith(" dBm"):
        return "muted"
    strength = float(signal[:-4])   # link_summary formats this only from a valid numeric measurement
    if strength >= -67:
        return "good"
    return "warn" if strength >= -75 else "bad"


def roster(p, switches, active, y):
    """Show neighbouring switches together, always reserving space for identity and outage state."""
    start_x, gap = 157, 9
    capacity = min(len(switches), max(2, int((p.w-start_x-42+gap)/270)))
    start = active//capacity*capacity
    end = min(len(switches), start+capacity)
    p.label(42, y+15, "Switches", 19, weight="bold")
    summary = "%d total" % len(switches) if capacity == len(switches) else "%d–%d of %d" % (start+1, end, len(switches))
    p.label(42, y+36, summary, 14, "muted")
    width = (p.w-start_x-42-(capacity-1)*gap)/capacity
    for i in range(start,end):
        switch = switches[i]
        x = start_x+(i-start)*(width+gap)
        ink = "accent" if i == active else "muted"
        online = switch.get("answering") is True
        state, tone = ("Online", "good") if online else ("Offline", "bad")
        p.rect(x, y, width, 48, "tint" if i == active else "surface", ink if i == active else "line", 9, 2)
        number = "#%d" % (i+1)
        p.label(x+13, y+24, number, 16, ink, "mono")
        nx = x+23+p.measure(number,16,"mono")
        p.label(nx, y+24, switch.get("name") or "Unnamed switch", 16, weight="mono",
                width=x+width-92-nx, scroll=i == active)
        p.rect(x+width-78, y+21, 6, 6, tone, radius=3)
        p.label(x+width-65, y+24, state, 15, tone, "bold")
    return y+62


def switch_panel(p, board, stand, page_idx, card, x, y, w, h):
    p.rect(x, y, w, h, "surface", "line", 14)
    p.icon_box("switch", x+20, y+16)
    if page_idx is None:
        p.label(x+70, y+36, "Switch ports", 23, weight="bold")
        p.label(x+w/2, y+h/2, "No managed switch configured", 21, "muted", "bold", align="center")
        p.label(x+w/2, y+h/2+32, "Cards use recent module reports.", 17, "muted", align="center")
        return
    index, groups = stand.pages[page_idx]
    switch = stand.switches[index]
    sibling_pages = [n for n,(i,_) in enumerate(stand.pages) if i == index]
    page_name = "Switch %d / %d" % (index+1,len(stand.switches))
    if len(sibling_pages)>1:
        page_name += " · Bank %d / %d" % (sibling_pages.index(page_idx)+1,len(sibling_pages))
    chip_x = x+w-38-p.measure(page_name,14,"bold")
    p.chip(chip_x, y+35, page_name, size=14, outlined=True)
    p.label(x+71, y+27, switch.get("name") or "Switch %d" % (index+1), 21, weight="mono", width=chip_x-x-84, scroll=True)
    offline = switch.get("answering") is not True
    subtitle = clean(switch.get("model")) or "Automatic port layout"
    if offline:
        subtitle = "Offline · "+("last known ports" if groups else "no port data")+" · "+subtitle
    p.label(x+71, y+51, subtitle, 15, "bad" if offline else "muted", "bold", width=w-100, scroll=True)
    legend_y = y+h-40
    if groups:
        columns = [math.ceil(len(b["ports"])/b["rows"]) for b in groups]
        inner = w-42
        gaps = (len(groups)-1)*16+(sum(columns)-len(groups))*6
        tile_w = min(127, (inner-gaps)/sum(columns))
        rows = max(b["rows"] for b in groups)
        tile_h = min(77,(legend_y-y-99-(rows-1)*5)/rows)
        top = y+67+max(0,(legend_y-y-67-(rows*tile_h+(rows-1)*5+25))/2)
        gx = x+21
        owner = stand.owner(card)
        for group, count in zip(groups,columns):
            group_w = count*tile_w+(count-1)*6
            p.label(gx, top+9, group["label"], 16, "muted", "bold", width=group_w)
            for j, port in enumerate(group["ports"]):
                tx = gx+(j//group["rows"])*(tile_w+6)
                ty = top+25+(rows-group["rows"]+j%group["rows"])*(tile_h+5)
                selected = owner == (index,port.get("name"))
                port_tile(p, board, stand, port, group["label"], tx, ty, tile_w, tile_h, selected)
            gx += group_w+16
    else:
        p.label(x+w/2, y+h/2, "No port data available", 20, "muted", align="center")
    p.line(x, legend_y, x+w, legend_y)
    lx = x+21
    for label, mark in (("This station","local"), ("Other station","peer"), ("Empty port","rj")):
        if mark == "rj":
            p.symbol(mark, lx, legend_y+11, 18)
        else:
            p.mark(lx, legend_y+11, 18, peer=mark=="peer")
        p.label(lx+26, legend_y+21, label, 15, "muted")
        lx += p.measure(label,15)+47


def port_tile(p, board, stand, port, group, x, y, w, h, selected):
    members = [stand.modules[s] for s in port.get("serials") or [] if s in stand.modules
               and stand.modules[s].get("state") in ("fail","stale","run","unknown","ok")]
    mark, old_ink = board.port_mark(port, members)
    tag = port.get("state")
    local = tag == "uplink" and port.get("server")
    peer = tag == "uplink" and bool(port.get("station")) and not local
    ink = {"accent":"accent","good":"good","run":"run","fail":"bad","warn":"warn"}.get(old_ink, "muted")
    if local:
        ink, mark = "local", "Local"
    elif peer:
        ink, mark = "muted", "Peer"
    else:
        mark = {"UP":"Uplink","DONE":"Done","WAIT":"Silent","FAIL":"Failed","BOOT":"Boot","LNK":"Link",
                "UNR":"UNR","?":"Module" if tag == "module" else "?"}.get(mark, mark)
    p.rect(x, y, w, h, TINT[ink], ink if mark else "line", 8, 2 if selected else 1)
    if selected:
        p.rect(x+2,y+2,w-4,h-4,None,ink,6)
    number = port.get("number")
    number = str(number) if number is not None else clean(port.get("name") or "?")
    p.label(x+8, y+16, number, 17, "fg", "mono", width=w-36, scroll=True)
    if local or peer:
        p.mark(x+w-28, y+7, 21, peer=peer)
    else:
        p.symbol("sfp" if "SFP" in group.upper() else "rj", x+w-28, y+7, 20, ink)
    if mark:
        p.label(x+8, y+h-12, mark, 15, ink, "bold", width=w-14)


def card_title(p, board, m, x, y, w, size=24):
    status = phase(board, m)
    symbol = phase_icon(m)
    chip_w = p.measure(status,15,"bold")+18+(21 if symbol else 0)
    p.chip(x+w-chip_w, y, status, tone(m), 15, symbol)
    p.label(x, y, m.get("name") or m.get("mac") or m.get("serial") or "Unknown device", size, "fg", "mono",
            width=w-chip_w-14, scroll=True)


def metric(board, m):
    if m.get("state") == "recognized":
        mark = m.get("flashed_by") or {}
        return clean(mark.get("station") or "Our sensor"), "Provisioning station"
    if m.get("state") in ("fail","stale"):
        return phase(board,m), m.get("cause") or m.get("detail") or ""
    progress = board.progress_text(m)
    if progress:
        percent, _, details = progress.partition(" / ")
        return percent, details.replace(" / "," · ")
    if m.get("cycle") is not None:
        return board.duration(m["cycle"]), "Cycle time"
    return phase(board,m), m.get("detail") or ""


def device_card(p, board, m, stand, index, total, x, y, w, h):
    p.rect(x,y,w,h,"surface","line",14)
    p.icon_box("chip",x+20,y+13)
    p.label(x+71,y+33,"Device card",24,weight="bold")
    p.label(x+w-20,y+33,"%d / %d" % (index+1 if m else 0, total),15,"muted",align="right")
    p.line(x,y+65,x+w,y+65)
    if not m:
        p.label(x+w/2,y+h/2+7,"Waiting for a connected module",20,"muted",align="center")
        return
    ix, inner = x+20, w-40
    card_title(p,board,m,ix,y+91,inner)
    stand.draw_location(p,m,ix,y+118,inner,ink="fg")
    p.label(ix,y+140,identity(board,m),14,"muted","mono",inner,scroll=True)
    big,secondary = metric(board,m)
    by = y+171
    big_width = min(inner*.57,p.measure(big,30,"bold"))
    p.label(ix,by,big,30,tone(m),"bold",big_width+2,scroll=True)
    p.label(ix+inner,by+3,secondary,15,tone(m),"bold",inner-big_width-15,align="right",scroll=True)
    if m.get("percent") is not None and m.get("state") == "run":
        p.bar(ix,by+22,inner,m["percent"],tone(m))
    fy = y+h-59
    p.label(ix,(by+28+fy)/2+1,m.get("detail") or result(board,m),17,width=inner,scroll=True)
    p.line(ix,fy,ix+inner,fy)
    hardware = board.hardware_lines(m)
    if hardware:
        p.symbol("chip",ix,fy+9,17)
        p.label(ix+24,fy+18," · ".join(hardware[:3]).replace(" / "," · "),14,"muted",width=inner-24,scroll=True)
        note = hardware[-1]+" · "+snapshot_note(board,m,p.now)
    elif m.get("state") == "recognized":
        p.label(ix,fy+18,"Verified sensor",15,"accent","bold")
        note = m.get("detail")
    else:
        p.label(ix,fy+18,"Hardware not reported",15,"muted")
        note = snapshot_note(board,m,p.now)
    p.label(ix,fy+40,note,14,"muted",width=inner,scroll=True)


def device_table(p, board, stand, cards, unknown, selected, tick, x, y, w, h):
    p.rect(x,y,w,h,"surface","line",14)
    total = len(cards)+len(unknown)
    p.label(x+20,y+21,"Devices on the stand",21,weight="bold")
    p.rect(x+1,y+44,w-2,27,"raised")
    a,b,c = x+20,x+w*.385,x+w*.57
    for label, pos in (("Port / device",a),("Phase",b),("Progress / result",c)):
        p.label(pos,y+58,label,14,"muted","bold")
    capacity = max(1,int((h-74)/33))
    pages = max(1,math.ceil(total/capacity))
    rotation = tick%(len(cards)+pages)
    page = selected//capacity if rotation<len(cards) else rotation-len(cards)
    page = page%pages
    count = "%d %s" % (total, "device" if total==1 else "devices")
    if pages>1:
        count += " · Page %d / %d" % (page+1,pages)
    p.label(x+w-20,y+21,count,16,"muted",align="right")
    if not total:
        p.label(a,y+99,"No devices currently on the stand.",17,"muted")
    row_h = min(39,(h-74)/capacity)
    for row, idx in enumerate(range(page*capacity,min(total,(page+1)*capacity))):
        ry = y+71+row*row_h
        cy = ry+row_h/2
        p.line(x+1,ry,x+w-1,ry)
        if idx < len(cards):
            m = cards[idx]
            location, name = stand.card_port(m), clean(m.get("name"))
            status, details, color = phase(board,m), result(board,m), tone(m)
            symbol = phase_icon(m)
            percent = m.get("percent") if m.get("state") == "run" else None
            if idx == selected:
                p.rect(x+1,ry+1,3,row_h-2,"accent")
        else:
            item = unknown[idx-len(cards)]
            location, name = stand.card_port(item), clean(item.get("name") or "Unknown device")
            quiet = item.get("state") == "quiet"
            status, color = ("Link only","muted") if quiet else ("Unrecognized","warn")
            details = item.get("detail") or item.get("mac") or ""
            symbol, percent = None, None
        location_w = min(p.measure(location,14,"mono")+12,(b-a)*.56)
        stand.draw_location(p,cards[idx] if idx<len(cards) else unknown[idx-len(cards)],a,cy,location_w-6,14)
        p.label(a+location_w,cy,name,17,"fg","mono",b-a-location_w-15,scroll=True)
        p.chip(b,cy,status,color,14,symbol)
        value_x = c
        if percent is not None:
            p.bar(c,cy-2,74,percent,color)
            value_x += 84
        p.label(value_x,cy,clean(details).replace(" / "," · "),17,width=x+w-value_x-21,scroll=True)


def desk(p, board, data, stand, cards, selected, tick, offset):
    x, y, w, bottom = 42, 237, p.w-84, p.h-80
    left = (w-16)*.616
    m = cards[selected] if cards else None
    page = stand.page(m,len(cards),tick,offset)
    if len(stand.switches)>1:
        y = roster(p,stand.switches,stand.pages[page][0],y)
    height = min(445,max(298,(bottom-y-15)*.59))
    switch_panel(p,board,stand,page,m,x,y,left,height)
    device_card(p,board,m,stand,selected,len(cards),x+left+16,y,w-left-16,height)
    table_y = y+height+15
    device_table(p,board,stand,cards,data.get("unknown") or [],selected,tick,x,table_y,w,bottom-table_y)


def flow(p, board, data, stand, cards, selected):
    x,y,w = 42,237,p.w-84
    columns = 3 if p.w/p.h >= 2.05 else 2
    capacity = columns*2
    page = selected//capacity
    p.label(x,y+17,"All device cards",25,weight="bold")
    center = p.w/2-182
    for sym,label in (("monitor","Provisioner"),("switch","Switch / PoE"),("chip","Modules")):
        p.symbol(sym,center,y+6,21)
        p.label(center+30,y+17,label,17,weight="bold")
        center += 48+p.measure(label,17,"bold")
        if sym != "chip":
            p.line(center-5,y+17,center+9,y+17,"muted")
            p.line(center+5,y+13,center+9,y+17,"muted")
            p.line(center+5,y+21,center+9,y+17,"muted")
            center += 23
    page_text = "Page %d / %d" % (page+1,max(1,math.ceil(len(cards)/capacity)))
    p.chip(x+w-p.measure(page_text,15,"bold")-18,y+17,page_text,size=15,outlined=True)
    gy = y+47
    ch = (p.h-80-gy-15)/2
    cw = (w-(columns-1)*15)/columns
    if not cards:
        p.rect(x,gy,w,p.h-80-gy,"surface","line",14)
        p.label(p.w/2,(gy+p.h-80)/2,"Waiting for connected modules",23,"muted",align="center")
    for i, m in enumerate(cards[page*capacity:(page+1)*capacity]):
        cx,cy = x+(i%columns)*(cw+15), gy+(i//columns)*(ch+15)
        p.rect(cx,cy,cw,ch,"surface","accent" if i+page*capacity==selected else "line",14)
        cx += 21
        inner = cw-42
        card_title(p,board,m,cx,cy+32,inner)
        stand.draw_location(p,m,cx,cy+62,inner)
        center_y = cy+(ch+2)/2
        p.rect(cx,center_y-27,55,55,"raised",radius=12)
        p.symbol("shield" if m.get("state") == "recognized" else "chip",cx+12,center_y-15,31,tone(m))
        big, detail = metric(board,m)
        p.label(cx+71,center_y-12,big,31,tone(m),"bold",inner-71,scroll=True)
        p.label(cx+71,center_y+18,detail,17,width=inner-71,scroll=True)
        if m.get("percent") is not None and m.get("state") == "run":
            p.bar(cx,cy+ch-62,inner,m["percent"],tone(m))
        p.line(cx,cy+ch-45,cx+inner,cy+ch-45)
        p.label(cx,cy+ch-24,board.identity(m),15,"muted","mono",inner*.48,scroll=True)
        note = m.get("detail") if m.get("state") == "recognized" else "Cycle "+board.duration(m.get("cycle"))
        p.label(cx+inner,cy+ch-24,note,16,width=inner*.50,align="right",scroll=True)


def engineer(p, board, stand, m):
    x,y,w,bottom = 42,237,p.w-84,p.h-80
    if not m:
        p.rect(x,y,w,bottom-y,"surface","line",14)
        p.label(p.w/2,(y+bottom)/2,"Waiting for a connected module",23,"muted",align="center")
        return
    name = clean(m.get("name") or m.get("mac") or "Unknown device")
    title_width = min(p.measure(name,27,"mono"), w*.40)
    p.label(x,y+20,name,27,"fg","mono",title_width+1,scroll=True)
    stand.draw_location(p,m,x+title_width+14,y+21,w-title_width-215,17)
    p.chip(x+w-18-p.measure("Device diagnostics",15,"bold"),y+18,"Device diagnostics",size=15,outlined=True)
    cy = y+53
    if m.get("state") == "recognized":
        p.rect(x,cy,w,bottom-cy,"surface","line",14)
        mid = (cy+bottom)/2
        p.symbol("shield",x+29,mid-86,51,"accent")
        p.label(x+29,mid-8,"Our sensor",39,"accent","bold")
        p.label(x+29,mid+43,m.get("detail") or "Verified sensor",23,width=w-58,scroll=True)
        p.label(x+29,mid+87,board.identity(m),18,"muted","mono",w-58)
        return
    split = (w-16)*.363
    step_y = bottom-162
    panel_h = step_y-15-cy
    p.rect(x,cy,split,panel_h,"surface","line",14)
    p.icon_box("chip",x+25,cy+19)
    p.label(x+76,cy+39,"Hardware",22,weight="bold")
    hardware = m.get("hardware") or {}
    if hardware:
        for i,(key,label) in enumerate((("model","Model"),("revision","Revision"),("ram","RAM"),("emmc","eMMC"),
                                        ("wifi","Wi-Fi"),("sku","SKU"))):
            row_y = cy+85+i*31
            p.label(x+25,row_y,label,19,"muted")
            value = clean(hardware.get(key) or "Unknown").removeprefix("Raspberry Pi ")
            p.label(x+150,row_y,value,19,"fg","bold",split-172,scroll=True)
    else:
        p.symbol("chip",x+split/2-41,cy+panel_h/2-38,82)
        p.label(x+split/2,cy+panel_h/2+73,"Hardware not reported",18,"muted",align="center")
    dx, dw = x+split+16, w-split-16
    p.rect(dx,cy,dw,panel_h,"surface","line",14)
    dx += 24
    inner = dw-48
    p.label(dx,cy+30,"Provisioning phase",17,"muted","bold")
    status = phase(board,m)
    p.label(dx,cy+79,status,48,tone(m),"bold",inner)
    if phase_icon(m):
        p.symbol(phase_icon(m),dx+inner-24,cy+18,23,tone(m))
    p.label(dx,cy+129,identity(board,m),16,"muted","mono",inner,scroll=True)
    diag = m.get("diagnostics") or {}
    p.paragraph(dx,cy+170,diag.get("title") or m.get("detail") or "No diagnostic snapshot yet.",inner,2,21)
    status_y = cy+min(222,panel_h*.64)
    p.line(dx,status_y,dx+inner,status_y)
    facts = diag.get("facts") or {}
    facts_text = []
    try:
        if facts.get("temperature_mc") is not None:
            facts_text.append("%.1f °C" % (float(facts["temperature_mc"])/1000))
    except (TypeError,ValueError,OverflowError):
        pass
    if facts.get("throttled") is not None:
        facts_text.append("Power "+clean(facts["throttled"]))
    p.label(dx,status_y+21,"   ·   ".join([snapshot_note(board,m,p.now)]+facts_text),16,"muted",width=inner,scroll=True)
    if board.progress_text(m):
        p.label(dx,status_y+60,board.progress_text(m).replace(" / "," · "),20,tone(m),"bold",inner,scroll=True)
    p.rect(x,step_y,w,bottom-step_y,"surface","line",14)
    p.label(x+23,step_y+24,"Reported steps",21,weight="bold")
    phases = (("preinstall","Preinstall"),("write","Write"),("verify","Verify"),
              ("postinstall","Postinstall"),("firstboot","First boot"))
    reported = [step for step in m.get("steps") or [] if step in dict(phases)]
    sx = x+24
    for key,label in phases:
        last = reported and key == reported[-1]
        color = tone(m) if last and m.get("state") in ("fail","stale","run") else ("good" if key in reported else "muted")
        p.rect(sx,step_y+51,21,21,None,color,11)
        if key in reported:
            mark = (phase_icon(m) or "done") if last else "done"
            p.symbol(mark,sx+4,step_y+55,13,color)
        p.label(sx+28,step_y+62,label,18,color,"bold")
        sx += p.measure(label,18,"bold")+42
        if key != "firstboot":
            p.line(sx,step_y+62,sx+36,step_y+62)
            sx += 52
    p.line(x+23,step_y+88,x+w-23,step_y+88)
    issues = diag.get("issues") or []
    issue = issues[0] if issues else {}
    evidence = issue.get("evidence") or m.get("detail") or "No recognized warning in the saved snapshot."
    advice = issue.get("advice") or m.get("advice") or "Wait for the configured checks. Details are in the web device card."
    for i,(label,value,color) in enumerate((("EVIDENCE",evidence,"muted"),("NEXT",advice,"warn"))):
        gx = x+23+i*(w-34)/2
        p.label(gx,step_y+107,label,14,color,"bold")
        p.paragraph(gx,step_y+132,value,(w-78)/2,2,16)


def footer(p, board, data, stand, cards, selected, tick, actual, paused):
    y, right = p.h-65, p.w-42
    p.rect(0,y,p.w,65)
    p.line(0,y,p.w,y)
    generated = data.get("generated_at") or 0
    problems = [m for m in cards if m.get("state") in ("fail","stale")]
    offline = sum(s.get("answering") is not True for s in stand.switches)
    if p.now-generated>10:
        message, tone = "Data stale · "+("last snapshot "+board.age(generated,p.now) if generated else "waiting for the first snapshot"), "bad"
    elif problems:
        m = problems[tick%len(problems)]
        message, tone = stand.card_port(m)+" · "+clean(m.get("detail") or phase(board,m)), "bad" if m["state"] == "fail" else "warn"
    elif offline:
        lost = next(s for s in stand.switches if s.get("answering") is not True)
        saved = any(block.get("ports") for block in lost.get("blocks") or [])
        message = clean(lost.get("name") or "Switch")+" offline · "+("last known ports" if saved else "no saved ports")
        tone = "bad"
    elif data.get("unknown"):
        message, tone = "%d unrecognized or quiet ports · See Ports in the web UI" % len(data["unknown"]), "warn"
    else:
        message, tone = "Read only", "muted"
    p.symbol("lock" if tone=="muted" else "warn",42,y+11,16,tone)
    p.label(66,y+19,message,15,tone,"bold",p.w-580,scroll=True)
    color_mode = "Auto" if data.get("hdmi_auto_mode") else ("Light" if p.light else "Dark")
    mode_text = "Color mode · "+color_mode
    p.label(right,y+19,mode_text,15,"muted",align="right")
    mode_width = p.measure(mode_text,15)
    p.symbol("time",right-mode_width-22,y+11,15)
    if offline:
        count = "%d switch%s offline" % (offline, "es" if offline!=1 else "")
        p.chip(right-mode_width-67-p.measure(count,14,"bold"),y+19,count,"bad",14)
    state = "Paused" if paused else "Auto rotation"
    current = "%s   ·   Card %d / %d   ·   %s" % (state, selected+1 if cards else 0, len(cards), VIEWS[actual])
    keys = [("← →","Card"),("[ ]","Switch / bank"),("Space","Pause"),("V","View"),("Alt+F1","Console"),("Alt+F8","Board")]
    key_widths = [p.measure(key,12,"mono")+12+p.measure(caption,15)+19 for key,caption in keys]
    kx = right-sum(key_widths)+12
    p.label(42,y+46,current,15,"muted",width=kx-60)
    for (key,caption),span in zip(keys,key_widths):
        kw = p.measure(key,12,"mono")+9
        p.rect(kx,y+36,kw,19,"surface","line",4)
        p.label(kx+kw/2,y+46,key,12,"fg","mono",align="center")
        p.label(kx+kw+5,y+46,caption,15,"muted")
        kx += span


def private_frame(p, access, setup, remaining):
    """Paint an isolated card: ordinary stand records never enter this function."""
    x, right = 42, p.w-42
    p.rect(0,0,p.w,65)
    p.line(0,65,p.w,65)
    p.mark(x,13,39)
    p.label(x+55,33,"cmprovision",26,weight="bold")
    p.label(right,33,"Station access",17,"muted",align="right")
    middle = p.h/2
    if access is not None:
        matrix = access["matrix"]  # The publisher supplied the four-module white border.
        area = min(360,p.w*.25)
        cell = max(1,p.px(area)//len(matrix))
        side = cell*len(matrix)
        left, top = p.px(p.w*.16), p.px(middle)-side//2
        p.draw.rectangle((left,top,left+side-1,top+side-1), fill="white")
        for row, cells in enumerate(matrix):
            for col, black in enumerate(cells):
                if black:
                    a,b = left+col*cell,top+row*cell
                    p.draw.rectangle((a,b,a+cell-1,b+cell-1),fill="black")
        tx = (left+side)/p.scale+62
        info_w = min(p.w-tx-70,860)
        p.symbol("wifi",tx,middle-181,30,"accent")
        p.label(tx,middle-123,"Rescue Wi-Fi",46,weight="bold")
        p.label(tx,middle-78,"Scan to connect",23,"muted")
        p.label(tx,middle-28,"SSID",15,"muted","bold")
        p.label(tx,middle+4,access["ssid"],26,"fg","mono",info_w,scroll=True)
        p.label(tx,middle+53,"PASSWORD",15,"muted","bold")
        password = access["password"]
        # Preserve all characters, including spaces, and wrap at a known
        # monospace character width so a manual login works without scrolling.
        stride = max(1,int(info_w/p.measure("W",24,"mono")))
        for i,first in enumerate(range(0,len(password),stride)):
            p.label(tx,middle+86+i*31,password[first:first+stride],24,"fg","mono")
        p.chip(tx,middle+186,"Closes in %d s" % (remaining or 0),"accent",16)
        note = "FPMS KEY2 closes this card"
    else:
        p.mark(p.w/2-37,middle-219,74)
        p.label(p.w/2,middle-98,"Set up this station",47,weight="bold",align="center")
        p.label(p.w/2,middle-46,setup["url"],27,"fg","mono",p.w-100,align="center")
        p.label(p.w/2,middle+12,"Setup code",18,"muted","bold",align="center")
        cw, gap = 160,12
        for i in range(4):
            gx = (p.w-4*cw-3*gap)/2+i*(cw+gap)
            p.rect(gx,middle+41,cw,85,"tint","line",13)
            p.label(gx+cw/2,middle+84,setup["code"][i*4:i*4+4],47,"accent","mono",align="center")
        p.label(p.w/2,middle+176,"Create your first web administrator",20,"muted",align="center")
        note = "First administrator setup"
    p.line(0,p.h-50,p.w,p.h-50)
    p.symbol("lock",x,p.h-34,18)
    p.label(x+26,p.h-25,note,16,"muted")
    return p.image


def render_frame(board, data, size, *, selected=0, tick=0, view="auto", paused=False, palette="warm",
                 now=None, switch_offset=0, access=None, setup=None, remaining=None):
    """Render one RGB frame at the output backend's bounded working resolution."""
    data = data or {}
    now = time.time() if now is None else now
    private = access is not None or setup is not None
    p = Painter(size,board.resolved_palette(palette,data),now,cache_text=not private)
    if private:
        return private_frame(p,access,setup,remaining)
    cards = list(data.get("modules") or [])+list(data.get("recognized") or [])
    selected = selected%len(cards) if cards else 0
    m = cards[selected] if cards else None
    if view == "auto":
        view = "engineer" if m and m.get("state") in ("fail","stale") else "desk"
    if view not in VIEWS:
        view = "desk"
    left = (p.w-100)*.616
    stand = PortMap(data.get("switches") or [],cards,max(1,int((left-30)/86)))
    header(p,board,data)
    if view == "flow":
        flow(p,board,data,stand,cards,selected)
    elif view == "engineer":
        engineer(p,board,stand,m)
    else:
        desk(p,board,data,stand,cards,selected,tick,switch_offset)
    footer(p,board,data,stand,cards,selected,tick,view,paused)
    return p.image
