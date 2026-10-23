#!/usr/bin/python3
"""Optional local HDMI console. Only the small watcher/VT setup runs as root.

The renderer runs as a user of its own and only reads one JSON file; the Artisan reader that keeps that file
current is a separate unit of the web user, without a terminal. No HTTP listener, browser, X server, GPIO writes
or diagnostic requests are involved.
"""
import argparse
import curses
import fcntl
import glob
import json
import math
import os
from pathlib import Path
import re
import signal
import struct
import subprocess
import sys
import tempfile
import textwrap
import time

CONFIG = Path('/etc/default/cmprovision-wallboard.json')
RUNTIME = Path('/run/cmprovision-wallboard')
UNIT = 'cmprovision-wallboard-display.service'
TTY = '/dev/tty8'
SNAPSHOT = Path('/run/cmprovision-wallboard-data/snapshot.json')
MAX_SNAPSHOT = 2097152
DEBOUNCE = 2   # readings in a row before a connector change counts
CONSOLEBLANK = Path('/sys/module/kernel/parameters/consoleblank')
FONTS = Path('/usr/share/consolefonts')
DEFAULTS = dict(mode='off', palette='warm', view='auto', rotation=12)
WEB_THEMES = {'warm': ('warm', 'dark'), 'warm-light': ('warm', 'light'),
              'neutral': ('neutral', 'dark'), 'neutral-light': ('neutral', 'light')}
PALETTE_ALIASES = {'phosphor': 'warm', 'mono': 'neutral'}
WEB_CSS = Path('/var/lib/cmprovision/resources/css/app.css')
CHOICES = dict(mode=('off', 'auto', 'on'), palette=tuple(WEB_THEMES),
               view=('auto', 'desk', 'flow', 'engineer'))
STOP = False
SCROLL_SECONDS = .35   # a text that does not fit runs one column per step
MARQUEE_REST = 4       # steps it rests at each end


def valid_config(data):
    if not isinstance(data, dict) or set(data) != set(DEFAULTS):
        raise ValueError('Expected mode, palette, view and rotation.')
    data = dict(data)
    if isinstance(data['palette'], str):
        data['palette'] = PALETTE_ALIASES.get(data['palette'], data['palette'])
    for key, choices in CHOICES.items():
        if data[key] not in choices:
            raise ValueError('Invalid ' + key)
    if type(data['rotation']) is not int or not 5 <= data['rotation'] <= 60:
        raise ValueError('Invalid rotation')
    return dict(data)


def read_config(path=CONFIG):
    try:
        return valid_config(json.loads(path.read_text(encoding='utf-8')))
    except (OSError, ValueError, TypeError):
        return dict(DEFAULTS)  # malformed settings fail closed


def atomic_json(path, data):
    # Both directories are root-owned. Never open a writable application path as root.
    fd, name = tempfile.mkstemp(prefix='.wallboard-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as out:
            json.dump(data, out)
            out.write('\n')
            out.flush()
            os.fsync(out.fileno())
            os.fchmod(out.fileno(), 0o644)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def hdmi_connected(root=Path('/sys/class/drm')):
    for path in root.glob('card*-HDMI-A-*/status'):
        try:
            if path.read_text().strip() == 'connected':
                return True
        except OSError:
            pass
    return False


def wanted(config, connected):
    return config['mode'] == 'on' or (config['mode'] == 'auto' and connected)


def systemctl(*args):
    try:
        return subprocess.run(['/usr/bin/systemctl', *args, UNIT], timeout=15,
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
    except (OSError, subprocess.TimeoutExpired):
        return False


def watch():
    RUNTIME.mkdir(mode=0o755, exist_ok=True)
    last_state = None
    last_check = 0
    active = False
    connected = hdmi_connected()
    candidate, count = connected, 0
    fresh = True            # the next start is a new connection (or boot), not a retry: only that may take the console
    failures, retry_at = 0, 0.0
    try:
        while not STOP:
            config = read_config()
            # A cable or a TV in standby flaps for a moment: a change counts after DEBOUNCE equal readings in a row.
            reading = hdmi_connected()
            if reading == connected:
                candidate, count = connected, 0
            else:
                count = count + 1 if reading == candidate else 1
                candidate = reading
                if count >= DEBOUNCE:
                    connected, count = reading, 0
            desired = wanted(config, connected)
            now = time.monotonic()
            if desired != active or now - last_check >= 10:
                active = systemctl('is-active', '--quiet')
                if active:
                    fresh, failures = False, 0
                if desired and not active:
                    if now >= retry_at:
                        if fresh:
                            (RUNTIME / 'switch-requested').write_text('1', encoding='ascii')
                            fresh = False
                        active = systemctl('start')
                        failures = 0 if active else failures + 1
                        retry_at = now + (10 if failures < 3 else 60)   # a unit that keeps failing is not hammered
                elif not desired and active:
                    systemctl('stop')
                    active = systemctl('is-active', '--quiet')
                if not desired:
                    fresh, failures = True, 0
                last_check = now
            state = dict(state='displaying' if active else ('error' if desired else ('off' if config['mode'] == 'off' else 'waiting for HDMI')),
                         connected=connected, mode=config['mode'])
            if active and desired:
                layout = configure_console(framebuffer_size())
                state['resolution'] = layout['resolution']
            if state != last_state:
                atomic_json(RUNTIME / 'status.json', state)
                print('HDMI wallboard: ' + state['state'], flush=True)
                last_state = state
            time.sleep(2)
    finally:
        systemctl('stop')
        atomic_json(RUNTIME / 'status.json', dict(state='stopped', connected=hdmi_connected()))


def active_vt():
    try:
        return Path('/sys/class/tty/tty0/active').read_text().strip()
    except OSError:
        return ''


def framebuffer_size(device=Path('/dev/fb0')):
    """Visible pixels, not virtual_size, which can include offscreen buffers."""
    try:
        with device.open('rb', buffering=0) as fb:
            info = bytearray(160)  # struct fb_var_screeninfo
            fcntl.ioctl(fb.fileno(), 0x4600, info)  # FBIOGET_VSCREENINFO, read only
        width, height = struct.unpack_from('=2I', info)
        return (width, height) if 320 <= width <= 16384 and 240 <= height <= 16384 else None
    except OSError:
        return None


def font_candidates(pixels):
    # Keep >=38 text rows and >=100 columns for diagnostics plus the footer.
    # Prefer native glyphs over doubled ones of the same height.
    base = [(6, 12), (8, 14), (8, 16), (10, 18), (10, 20), (11, 22), (12, 24), (14, 28), (16, 32)]
    choices = []
    for width, height in base:
        name = 'Lat15-Terminus%d%s.psf.gz' % (height, ('x%d' % width) if height not in (14, 16) else '')
        for scale in (1, 2):
            fw, fh = width * scale, height * scale
            if (pixels and pixels[0] // fw >= 100 and pixels[1] // fh >= 38) or (not pixels and fh <= 28):
                choices.append(dict(name=name, double=scale == 2, width=fw, height=fh))
    return sorted(choices, key=lambda f: (-f['height'], f['double'], -f['width']))


def configure_console(pixels, force=False):
    """Root watcher adjusts only tty8's font; never switches the active VT here."""
    resolution = '%dx%d' % pixels if pixels else 'unknown'
    path = RUNTIME / 'layout.json'
    try:
        previous = json.loads(path.read_text())
        if not force and isinstance(previous, dict) and previous.get('resolution') == resolution:
            return previous
    except (OSError, ValueError):
        pass
    result = dict(resolution=resolution, font='system default')
    for font in font_candidates(pixels):
        file = FONTS / font['name']
        if not file.is_file():
            continue
        command = ['/usr/bin/setfont', '-C', TTY] + (['-d'] if font['double'] else []) + [str(file)]
        try:
            applied = subprocess.run(command, capture_output=True, timeout=5).returncode == 0
        except (OSError, subprocess.TimeoutExpired):
            applied = False
        if applied:
            result.update(font=font['name'], double=font['double'], cell=[font['width'], font['height']])
            break
    # Remember a fallback too: older kernels rejecting tall fonts must not be
    # retried every two seconds. A new connection/resolution retries selection.
    atomic_json(path, result)
    print('HDMI layout: ' + json.dumps(result), flush=True)
    return result


def claim():
    RUNTIME.mkdir(mode=0o755, exist_ok=True)
    previous = active_vt()
    if previous.startswith('tty') and previous[3:].isdigit() and previous != 'tty8':
        (RUNTIME / 'previous-vt').write_text(previous, encoding='ascii')
    # Font is local to tty8; other virtual terminals retain the administrator's font.
    configure_console(framebuffer_size(), force=True)
    # The console is taken only when the watcher asked for it: a new connection or a boot. A restart after a crash or an
    # upgrade must not pull the administrator off tty1.
    flag = RUNTIME / 'switch-requested'
    switch = flag.exists()
    flag.unlink(missing_ok=True)
    if switch:
        subprocess.run(['/usr/bin/chvt', '8'], check=True, timeout=5)
    # Keep the unattended display from blanking. The interval the administrator had is remembered once: the kernel
    # parameter is the live value this very escape sequence zeroes, so release() could not read it back afterwards.
    saved = RUNTIME / 'consoleblank'
    if not saved.exists():
        try:
            saved.write_text(CONSOLEBLANK.read_text().strip(), encoding='ascii')
        except OSError:
            pass
    with open(TTY, 'w') as tty:
        tty.write('\x1b[9;0]')


def release():
    try:
        saved = RUNTIME / 'consoleblank'
        try:
            interval = int(saved.read_text())
        except (OSError, ValueError):
            interval = int(CONSOLEBLANK.read_text())
        with open(TTY, 'w') as tty:
            tty.write('\x1b[0m\x1b]R')  # restore tty8's system palette even after a killed renderer
            tty.write('\x1b[9;%d]' % min(60, math.ceil(interval / 60)))
        saved.unlink(missing_ok=True)
        previous = (RUNTIME / 'previous-vt').read_text().strip()
        if active_vt() == 'tty8' and previous.startswith('tty') and previous[3:].isdigit() and 1 <= int(previous[3:]) <= 63:
            subprocess.run(['/usr/bin/chvt', previous[3:]], check=False, timeout=5)
    except (OSError, ValueError, subprocess.TimeoutExpired):
        pass


def safe(value):
    """All external text is single-line printable ASCII, including escape/C1/combining characters."""
    return ''.join(c if 32 <= ord(c) < 127 else ' ' for c in str(value if value is not None else ''))


def duration(value):
    if value is None:
        return '--'
    value = max(0, int(value))
    return '%d:%02d' % (value // 60, value % 60)


def size(value):
    return '--' if value is None else '%.2f GiB' % (float(value) / 1073741824)


def age(value, now):
    return '--' if value is None else str(max(0, int(now - value))) + 's ago'


class Canvas:
    def __init__(self, cols, rows, step=0):
        self.cols, self.rows = cols, rows
        self.step = step      # which step of the scrolling texts this frame is
        self.scrolls = False  # something did not fit: the screen is redrawn every step
        self.cells = [[(' ', 'fg') for _ in range(cols)] for _ in range(rows)]

    def text(self, x, y, text, ink='fg', width=None):
        if not 0 <= y < self.rows:
            return
        value = safe(text)
        width = max(0, min(self.cols - x, width if width is not None else self.cols - x))
        if len(value) > width:
            value = value[:max(0, width - 1)] + ('~' if width else '')
        for i, char in enumerate(value):
            if 0 <= x + i < self.cols:
                self.cells[y][x + i] = (char, ink)

    def marquee(self, x, y, text, width, ink='fg'):
        """Text that fits is drawn as it is. A longer one runs through its column: it rests at the start, moves one
        column a step, rests at the end and starts over; the last column says '>' while there is more to come."""
        value = safe(text)
        width = max(0, min(self.cols - x, width))
        if len(value) <= width:
            return self.text(x, y, value, ink, width)
        self.scrolls = True
        shown = max(1, width - 1)
        last = len(value) - shown
        offset = min(max(self.step % (last + 2 * MARQUEE_REST) - MARQUEE_REST, 0), last)
        self.text(x, y, value[offset:offset + shown] + ('>' if offset < last else ' '), ink, width)

    def line(self, y, x=0, width=None):
        self.text(x, y, '-' * (width or self.cols), 'dim')

    def box(self, x, y, width, height, title):
        self.text(x, y, '+' + '-' * (width - 2) + '+', 'dim')
        self.text(x, y + height - 1, '+' + '-' * (width - 2) + '+', 'dim')
        for row in range(y + 1, y + height - 1):
            self.text(x, row, '|', 'dim')
            self.text(x + width - 1, row, '|', 'dim')
        self.text(x + 2, y, ' ' + safe(title)[:width - 6] + ' ', 'accent')

    def wrap(self, x, y, text, width, limit=2, ink='fg'):
        lines = textwrap.wrap(safe(text), width=max(1, width), break_long_words=True) or ['--']
        for i, line in enumerate(lines[:limit]):
            self.text(x, y + i, line, ink, width)

    def plain(self):
        return '\n'.join(''.join(c for c, _ in row).rstrip() for row in self.cells)


SMALL = {'C': [' ##', '#  ', ' ##'], 'M': ['# #', '###', '# #'], 'P': ['## ', '###', '#  '],
         'R': ['## ', '###', '# #'], 'O': ['###', '# #', '###'], 'V': ['# #', '# #', ' # '],
         'I': ['###', ' # ', '###'], 'S': [' ##', ' # ', '## '], 'N': ['## ', '# #', '# #'],
         'F': ['###', '## ', '#  '], 'A': [' # ', '###', '# #'], 'L': ['#  ', '#  ', '###'],
         'W': ['# #', '###', '###'], 'T': ['###', ' # ', ' # '], 'D': ['## ', '# #', '## '],
         'E': ['###', '## ', '###'], 'Y': ['# #', ' # ', ' # '], 'B': ['## ', '###', '## ']}
CHIP = ['    .--------------------.', ' +--|    WLAN Pi M4+     |--+',
        ' |  +--------------------+ |', ' |                         |',
        '[USB]  .------------.   [ETH]', ' |     |    CM4     |      |',
        ' |     | .--------. |      |', ' |     | |  eMMC  | |      |',
        " |     | '--------' |      |", " |     '------------'      |", ' +--+--+--+--+--+--+--+--+--+',
        '    |  |  |  |  |  |  |  |']
INK = {'ok': 'good', 'run': 'run', 'fail': 'fail', 'stale': 'warn', 'unknown': 'dim'}


def phase(module):
    if module.get('state') == 'stale':
        return 'NO REPORT'
    return safe(module.get('phase', 'unknown')).upper()


def header(c, data, now):
    counts = data.get('counts', {})
    if c.cols >= 100:
        # Six columns by three rows are roughly square in a console font.
        # Keep the web mark's simple cm tile; the name is ordinary readable text.
        for row, line in enumerate(['.----.', '| cm |', "'----'"]):
            c.text(0, row, line, 'accent')
        # The name sits on the middle row of the three-row mark, two columns from it.
        c.text(8, 1, 'cmprovision', 'fg')
        c.text(21, 1, 'PROVISIONING STAND', 'dim')
        x = max(48, c.cols - 36)
        c.text(x, 0, safe(data.get('host')) + ' / HDMI', 'dim')
        c.text(x, 1, 'ON STAND %02d   DONE %02d' % (len(data.get('modules', [])), counts.get('done', 0)), 'good')
        c.text(x, 2, 'RUN %02d  FAIL %02d  STALE %02d' % (counts.get('running', 0), counts.get('failed', 0), counts.get('stale', 0)), 'warn')
        c.text(0, 4, 'PROJECT: ' + safe(data.get('project') or 'none active'), 'fg', c.cols - 32)
        c.text(c.cols - 30, 4, 'UPDATED ' + age(data.get('generated_at'), now), 'dim')
        c.line(5)
        return 7
    c.text(0, 0, '[cm] cmprovision // ' + safe(data.get('host', 'HDMI')), 'accent')
    c.text(0, 1, 'PROJECT: ' + safe(data.get('project') or 'none active'), 'dim')
    c.text(0, 2, 'ON STAND %d / DONE %d / RUN %d / FAIL %d / STALE %d' %
           (len(data.get('modules', [])), counts.get('done', 0), counts.get('running', 0), counts.get('failed', 0), counts.get('stale', 0)), 'warn')
    c.line(3)
    return 5


def port_mark(port, modules):
    found = [m for m in modules if m['serial'] in port.get('serials', [])]
    if found:
        m = sorted(found, key=lambda m: ['fail', 'stale', 'run', 'unknown', 'ok'].index(m['state']))[0]
        mark = 'WAIT' if m['state'] == 'stale' else ('FAIL' if m['state'] == 'fail' else
                ('DONE' if m['state'] == 'ok' else (str(m['percent']) + '%' if m.get('percent') is not None else phase(m)[:4])))
        return mark, INK.get(m['state'], 'dim')
    return {'free': ('FREE', 'dim'), 'uplink': ('UP', 'dim'), 'unrecognized': ('UNR', 'warn'),
            'quiet': ('LNK', 'warn'), 'boot': ('BOOT', 'run')}.get(port.get('state'), ('?', 'dim'))


def banks(switch, width):
    """Keep columns of verified banks in their physical order, splitting only between columns."""
    result = []
    max_columns = max(1, width // 10)
    for block in switch.get('blocks', []):
        rows = max(1, min(2, int(block['rows'])))
        ports = block['ports']
        for start in range(0, len(ports), max_columns * rows):
            part = ports[start:start + max_columns * rows]
            result.append(dict(label=block['label'], rows=rows, ports=part,
                               width=math.ceil(len(part) / rows) * 10, height=2 + rows * 3))
    return result


def port_pages(switch, width, height):
    pages, page, x, y, line_height = [], [], 0, 0, 0
    for bank in banks(switch, width):
        if x + bank['width'] > width:
            x, y, line_height = 0, y + line_height + 1, 0
        if page and y + bank['height'] > height:
            pages.append(page)
            page, x, y, line_height = [], 0, 0, 0
        page.append((x, y, bank))
        x += bank['width'] + 1
        line_height = max(line_height, bank['height'])
    if page:
        pages.append(page)
    # Single-row uplink banks sit at the bottom of the physical front panel.
    # Align each wrapped line independently, retaining the column order.
    pages = [[(x, y + max(b['height'] for _, by, b in page if by == y) - bank['height'], bank)
              for x, y, bank in page] for page in pages]
    return pages or [[]]


def ports(c, x, y, width, height, switches, modules, tick):
    if not switches:
        c.box(x, y, width, height, 'PORTS')
        c.wrap(x + 2, y + 2, 'No managed switch configured. Device cards use recent module reports.', width - 4, 3, 'dim')
        return
    switch = switches[tick % len(switches)]
    pages = port_pages(switch, width - 4, height - 4)
    page_no = (tick // len(switches)) % len(pages)
    title = '%s / %s' % (switch['name'], switch.get('model') or 'automatic layout')
    c.box(x, y, width, height, title)
    if not switch['answering']:
        c.text(x + 2, y + 1, 'SNMP NOT RESPONDING / last known ports', 'warn', width - 4)
    for bx, by, bank in pages[page_no]:
        c.text(x + 2 + bx, y + 2 + by, bank['label'], 'dim', bank['width'])
        for i, port in enumerate(bank['ports']):
            px, py = x + 2 + bx + (i // bank['rows']) * 10, y + 3 + by + (i % bank['rows']) * 3
            mark, ink = port_mark(port, modules)
            number = str(port.get('number') if port.get('number') is not None else port['name'])
            c.text(px, py, '.-------.', 'dim')
            c.text(px, py + 1, '|%s|' % (number + ' ' + mark)[:7].ljust(7), ink)
            c.text(px, py + 2, "'-------'", 'dim')
    if len(pages) > 1 or len(switches) > 1:
        c.text(x + 2, y + height - 2, 'SWITCH %d/%d / PORT PAGE %d/%d' %
               (tick % len(switches) + 1, len(switches), page_no + 1, len(pages)), 'dim', width - 4)


def detail(c, x, y, width, module, now):
    if module is None:
        c.wrap(x, y, 'Waiting for a connected module. Plug Ethernet into the provisioning switch.', width, 4, 'dim')
        return
    ink = INK.get(module.get('state'), 'dim')
    c.marquee(x, y, module['name'], width, 'accent')
    c.text(x, y + 1, module['serial'], 'dim', width)
    c.marquee(x, y + 2, module['port'], width, 'dim')
    c.text(x, y + 4, phase(module), ink, width)
    pct = module.get('percent')
    if pct is not None:
        bar_width = min(width - 2, 36)
        fill = round(bar_width * max(0, min(100, pct)) / 100)
        c.text(x, y + 5, '[' + '#' * fill + '.' * (bar_width - fill) + '] ' + str(pct) + '%', ink, width)
    c.text(x, y + 7, ('%.1f MiB/s / ETA %s' % (module['speed'] / 1048576, duration(module.get('eta'))))
           if module.get('speed') else 'CYCLE ' + duration(module.get('cycle')), 'fg', width)
    diag = module.get('diagnostics')
    c.text(x, y + 9, ('DIAG: ' + diag['source'] + ' / ' + age(diag['received_at'], now)) if diag else 'DIAG: no snapshot', 'dim', width)
    if module.get('detail'):
        c.wrap(x, y + 10, module['detail'], width, 2, ink)


def desk(c, data, module, now, tick):
    y = header(c, data, now)
    switches = data.get('switches', [])
    # Fit compact physical panels beside a readable card; larger banks paginate.
    natural = max((sum(math.ceil(len(b['ports']) / max(1, b['rows'])) * 10 for b in s['blocks'])
                   + max(0, len(s['blocks']) - 1) + 4 for s in switches), default=0)
    width = max(30, min(c.cols - 42, max(round(c.cols * 0.60), natural)))
    height = min(17, max(11, c.rows - y - 10))
    if c.cols < 100:
        ports(c, 0, y, c.cols, height, data.get('switches', []), data.get('modules', []), tick)
        table_y = y + height + 1
    else:
        ports(c, 0, y, width, height, data.get('switches', []), data.get('modules', []), tick)
        c.box(width + 2, y, c.cols - width - 2, height, 'DEVICE CARD')
        detail(c, width + 4, y + 1, c.cols - width - 6, module, now)
        table_y = y + height + 1
    table(c, table_y, data.get('modules', []), tick)


def table(c, y, modules, tick):
    """Port / device, phase and result across the whole width. The first column is as wide as the longest name needs
    (at most about half the screen), the result takes the rest; what still does not fit runs through its column."""
    phase_width = 12
    names = [safe(m['port']) + ' / ' + safe(m['name']) for m in modules]
    first = max(33, min(max(map(len, names), default=0), max(33, min(c.cols - phase_width - 4 - 30, int(c.cols * .45)))))
    x_phase, x_result = first + 2, first + 2 + phase_width + 2
    c.text(0, y, 'PORT / DEVICE', 'dim')
    c.text(x_phase, y, 'PHASE', 'dim')
    c.text(x_result, y, 'PROGRESS / RESULT', 'dim')
    c.line(y + 1)
    capacity = max(1, c.rows - 5 - y - 2)
    start = (tick % max(1, math.ceil(len(modules) / capacity))) * capacity
    for i, m in enumerate(modules[start:start + capacity]):
        ink, row = INK.get(m['state'], 'dim'), y + 2 + i
        result = ((str(m['percent']) + '% / ETA ' + duration(m.get('eta'))) if m.get('percent') is not None and m['phase'] != 'done'
                  else (m.get('detail') or 'Cycle ' + duration(m.get('cycle'))))
        c.marquee(0, row, names[start + i], first, ink)
        c.text(x_phase, row, phase(m)[:phase_width], ink)
        c.marquee(x_result, row, result, c.cols - x_result, ink)
    if not modules:
        c.text(0, y + 2, 'No modules currently on the stand.', 'dim')


def flow(c, data, module, now, tick):
    y = header(c, data, now)
    c.text(0, y, '[ WLAN Pi M4+ ] ---> [ SWITCH / PoE ] ---> [ CM4 SENSOR FLEET ]', 'accent')
    modules = data.get('modules', [])
    columns = 3 if c.cols >= 150 else (2 if c.cols >= 90 else 1)
    width = (c.cols - (columns - 1) * 2) // columns
    card_height = 8
    rows = max(1, (c.rows - y - 7) // (card_height + 1))
    capacity = rows * columns
    start = (tick % max(1, math.ceil(len(modules) / capacity))) * capacity
    for i, m in enumerate(modules[start:start + capacity]):
        x, cy = (i % columns) * (width + 2), y + 2 + (i // columns) * (card_height + 1)
        c.box(x, cy, width, card_height, m['port'] + ' / ' + m['name'])
        ink = INK.get(m['state'], 'dim')
        for j, line in enumerate(['.-----.', '| CM4 |', "'-----'"]):
            c.text(x + 2, cy + 2 + j, line, ink)
        c.text(x + 12, cy + 2, phase(m), ink, width - 14)
        c.text(x + 12, cy + 3, (str(m['percent']) + '%') if m.get('percent') is not None else m.get('detail'), ink, width - 14)
        c.text(x + 12, cy + 4, 'CYCLE ' + duration(m.get('cycle')), 'dim', width - 14)
        c.text(x + 2, cy + 6, m['serial'], 'dim', width - 4)
    if not modules:
        c.text(0, y + 3, 'Waiting for connected modules.', 'dim')


def engineer(c, data, module, now, tick):
    y = header(c, data, now)
    if not module:
        detail(c, 0, y, c.cols, None, now)
        return
    ink = INK.get(module['state'], 'dim')
    c.text(0, y, module['port'] + ' / ' + module['name'], 'accent')
    for i, line in enumerate(CHIP):
        c.text(0, y + 2 + i, line, 'board')
    x = 35 if c.cols >= 80 else 0
    dy = y + 2 if x else y + 15
    word = {'fail': 'FAIL', 'stale': 'WAIT', 'ok': 'DONE'}.get(module['state'],
            {'write': 'WRITE', 'verify': 'VERIFY', 'firstboot': 'OS'}.get(module['phase'], 'BOOT'))
    for row in range(3):
        c.text(x, dy + row, ' '.join(SMALL[ch][row] for ch in word), ink)
    c.text(x, dy + 4, module['serial'], 'dim')
    diag = module.get('diagnostics')
    c.wrap(x, dy + 6, (diag['title'] if diag else module.get('detail')) or 'No diagnostic snapshot yet.', c.cols - x, 2, ink)
    if diag:
        c.text(x, dy + 9, 'DIAG ' + diag['source'] + ' / ' + age(diag['received_at'], now), 'dim')
        facts = diag['facts']
        temp = facts.get('temperature_mc')
        temp = '%.1f C' % (float(temp) / 1000) if temp not in (None, '') else 'unknown'
        c.text(x, dy + 11, 'SNAPSHOT TEMP ' + temp + ' / POWER ' + safe(facts.get('throttled', 'unknown')), 'dim')
    below = max(y + 15, dy + 13)
    c.line(below)
    phases = ['preinstall', 'write', 'verify', 'postinstall', 'firstboot']
    c.text(0, below + 1, ' -> '.join(p.upper() for p in phases), 'dim')
    c.text(0, below + 2, 'REPORTED STEPS: ' + ', '.join(p.upper() for p in module.get('steps', []) if p in phases), ink)
    issues = (diag or {}).get('issues', [])
    evidence = issues[0].get('evidence') if issues else None
    advice = issues[0].get('advice') if issues else module.get('advice')
    c.wrap(0, below + 4, 'EVIDENCE: ' + (evidence or module.get('detail') or 'No recognized warning in the saved snapshot.'), c.cols, 2, 'fg')
    if below + 7 < c.rows - 5:
        c.wrap(0, below + 7, 'NEXT: ' + (advice or 'Wait for the configured checks to complete. See the module card in the web UI.'), c.cols, min(3, c.rows - 5 - below - 7), 'warn')


def frame(data, cols, rows, selected=0, view='auto', now=None, tick=0, paused=False, step=0):
    now = now or time.time()
    c = Canvas(max(1, cols), max(1, rows), step)
    if cols < 60 or rows < 24:
        c.wrap(0, 0, 'cmprovision HDMI: terminal too small. Need at least 60 columns and 24 rows. Use Settings > HDMI display or a smaller console font.', cols, rows, 'warn')
        return c
    modules = data.get('modules', [])
    module = modules[selected % len(modules)] if modules else None
    if view == 'auto':
        view = 'engineer' if module and module['state'] in ('fail', 'stale') else 'desk'
    {'desk': desk, 'flow': flow, 'engineer': engineer}.get(view, desk)(c, data, module, now, tick)
    problems = [m for m in modules if m['state'] in ('fail', 'stale')]
    stale = now - data.get('generated_at', 0) > 10
    c.line(rows - 5)
    if stale:
        c.text(0, rows - 4, '! DATA STALE / last successful snapshot ' + age(data.get('generated_at'), now), 'fail')
    elif problems:
        m = problems[tick % len(problems)]
        c.marquee(0, rows - 4, '! ' + m['port'] + ': ' + (m.get('detail') or phase(m)), cols, 'fail')
    elif any(not s['answering'] for s in data.get('switches', [])):
        c.text(0, rows - 4, '! A switch is not answering. Ports are last known data.', 'warn')
    else:
        unknown = sum(p.get('state') in ('unrecognized', 'quiet') for s in data.get('switches', []) for b in s['blocks'] for p in b['ports'])
        c.text(0, rows - 4, ('! %d unrecognized/quiet ports. Open Ports in the web UI.' % unknown) if unknown else 'READ ONLY / ' + safe(data.get('clock')), 'warn' if unknown else 'dim')
    c.text(0, rows - 2, ('PAUSED' if paused else 'AUTO ROTATION') + ' / CARD %d/%d / %s' %
           ((selected % len(modules)) + 1 if modules else 0, len(modules), view.upper()), 'dim')
    keys = 'Alt+F1 console / Alt+F8 board / <- -> / Space pause / V view'
    c.text(max(0, cols - len(keys)), rows - 1, keys, 'dim')
    return c


class Reader:
    """The display's data: the JSON file that wallboard:snapshot --output keeps current in the data unit's runtime
    directory. Nothing is started from here, and nothing in the file reaches the terminal unfiltered (see safe())."""

    def __init__(self, path=None):
        self.path = path or SNAPSHOT
        self.seen = None

    def poll(self):
        try:
            info = os.stat(self.path)
            key = (info.st_ino, info.st_mtime_ns, info.st_size)
            if key == self.seen:
                return None
            self.seen = key   # a file that cannot be used is not read again until it is replaced
            if info.st_size > MAX_SNAPSHOT:
                return None
            with open(self.path, 'rb') as handle:
                value = json.loads(handle.read(MAX_SNAPSHOT + 1))
        except (OSError, ValueError, TypeError):
            return None
        if isinstance(value, dict) and value.get('version') == 1 and 'generated_at' in value and isinstance(value.get('modules'), list):
            return value
        return None

    def close(self):
        pass


def web_palette(palette, path=WEB_CSS):
    """The web CSS is the source of truth, read only when a palette is selected.

    Console cells cannot have alpha: composite text/muted over the selected page.
    Seven foreground colors plus a background fit even TERM=linux's eight slots.
    """
    style, mode = WEB_THEMES[palette]
    selector = ':root[data-style="%s"][data-mode="%s"]' % (style, mode)
    block = re.search(re.escape(selector) + r'\s*\{([^}]*)\}', path.read_text(encoding='utf-8'))
    if not block:
        raise ValueError('Missing web theme: ' + palette)
    tokens = dict(re.findall(r'--([\w-]+):\s*([^;]+);', block[1]))

    def rgb(token, background=(0, 0, 0)):
        value = tokens[token].strip()
        if re.fullmatch(r'#[0-9a-fA-F]{6}', value):
            return tuple(int(value[i:i + 2], 16) for i in (1, 3, 5))
        match = re.fullmatch(r'rgba\(\s*(\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\s*\)', value)
        if not match:
            raise ValueError('Unsupported web color: ' + token)
        alpha = float(match[4])
        return tuple(int(int(match[i + 1]) * alpha + background[i] * (1 - alpha) + .5) for i in range(3))

    bg = rgb('page')
    result = {name: rgb(token, bg) for name, token in dict(fg='text', dim='muted', accent='accent',
              good='pos-text', run='info-text', fail='neg-text', warn='warn-text').items()}
    return dict(result, bg=bg, board=result['accent'])


def colors(palette):
    if not curses.has_colors():
        return dict.fromkeys(['fg', 'dim', 'accent', 'good', 'run', 'fail', 'warn', 'board'], 0)
    curses.start_color()
    slots = dict(fg=7, dim=6, accent=3, good=2, run=4, fail=1, warn=5, board=3)
    custom = curses.can_change_color()
    if custom:
        rgb = web_palette(palette)
        for name, slot in dict(slots, bg=0).items():
            if name == 'board':
                continue
            # linux's initc terminfo multiplies by 255/1000 with integer division.
            # Round up here so every original 8-bit CSS channel survives exactly.
            curses.init_color(slot, *((channel * 1000 + 254) // 255 for channel in rgb[name]))
    else:
        slots = dict(fg=7, dim=7, accent=3, good=2, run=6, fail=1, warn=3, board=3)
    result = {}
    for index, (name, color) in enumerate(slots.items(), 1):
        curses.init_pair(index, color, 0)
        # A_BOLD selects a different, bright palette entry on Linux consoles.
        result[name] = curses.color_pair(index) | (curses.A_BOLD if not custom and name != 'dim' else 0)
    return result


def viewport(cols, rows):
    # Small safe margins, then use the available screen. Caps apply only to
    # unusually large/fallback consoles, not ordinary Full HD/ultrawide/4K.
    width = min(cols - 4 if cols >= 80 else cols, 240)
    height = min(rows - 2 if rows >= 28 else rows, 60)
    return width, height, max(0, (cols - width) // 2), max(0, (rows - height) // 2)


def display(screen):
    curses.curs_set(0)
    screen.timeout(1000)
    screen.keypad(True)
    reader = Reader()
    data = dict(version=1, generated_at=0, modules=[], switches=[], counts={})
    selected, tick, last_rotation, paused, view_override = 0, 0, time.monotonic(), False, None
    palette_name, inks = None, None
    try:
        while not STOP:
            config = read_config()
            update = reader.poll()
            if update is not None:
                serial = data['modules'][selected % len(data['modules'])]['serial'] if data['modules'] else None
                data = update
                selected = next((i for i, m in enumerate(data['modules']) if m['serial'] == serial), 0)
            if not paused and time.monotonic() - last_rotation >= config['rotation']:
                selected += 1
                tick += 1
                last_rotation = time.monotonic()
            rows, cols = screen.getmaxyx()
            width, height, ox, oy = viewport(cols, rows)
            canvas = frame(data, width, height, selected, view_override or config['view'], tick=tick, paused=paused,
                           step=int(time.monotonic() / SCROLL_SECONDS))
            screen.timeout(int(SCROLL_SECONDS * 1000) if canvas.scrolls else 1000)   # faster only while a text runs
            if config['palette'] != palette_name:
                inks = colors(config['palette'])
                palette_name = config['palette']
                screen.bkgd(' ', inks['fg'])
                screen.clearok(True)
            screen.erase()
            for y, row in enumerate(canvas.cells):
                x = 0
                while x < width:
                    ink = row[x][1]
                    end = x + 1
                    while end < width and row[end][1] == ink:
                        end += 1
                    try:
                        screen.addstr(oy + y, ox + x, ''.join(char for char, _ in row[x:end]), inks[ink])
                    except curses.error:
                        pass  # lower-right cell and a concurrent resize are normal curses errors
                    x = end
            screen.refresh()
            key = screen.getch()
            if key in (curses.KEY_RIGHT, ord('n')):
                selected += 1
                last_rotation = time.monotonic()
            elif key in (curses.KEY_LEFT, ord('b')):
                selected = (selected - 1) % max(1, len(data['modules']))
                last_rotation = time.monotonic()
            elif key == ord(' '):
                paused = not paused
                last_rotation = time.monotonic()
            elif key in (ord('v'), ord('V')):
                views = ('auto', 'desk', 'flow', 'engineer')
                view_override = views[(views.index(view_override or config['view']) + 1) % len(views)]
    finally:
        reader.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('watch', 'display', 'claim', 'release', 'control', 'preview'))
    parser.add_argument('--snapshot')
    parser.add_argument('--cols', type=int, default=112)
    parser.add_argument('--rows', type=int, default=37)
    parser.add_argument('--view', choices=CHOICES['view'], default='auto')
    parser.add_argument('--step', type=int, default=0, help='preview: the step of the scrolling texts')
    args = parser.parse_args()
    if args.action == 'control':
        try:
            if os.geteuid() != 0:
                raise ValueError('Root required')
            atomic_json(CONFIG, valid_config(json.loads(sys.stdin.buffer.read(4097))))
            return 0
        except (OSError, ValueError, TypeError):
            print('Invalid or unavailable HDMI settings.', file=sys.stderr)
            return 1
    if args.action == 'preview':
        data = json.loads(Path(args.snapshot).read_text(encoding='utf-8'))
        print(frame(data, args.cols, args.rows, view=args.view, step=args.step).plain())
        return 0
    signal.signal(signal.SIGTERM, lambda *_: globals().__setitem__('STOP', True))
    signal.signal(signal.SIGINT, lambda *_: globals().__setitem__('STOP', True))
    if args.action == 'display':
        try:
            curses.wrapper(display)
        finally:
            # Reset only this terminal, not the global console palette (PIO_CMAP).
            reset = curses.tigetstr('oc')
            if reset:
                sys.stdout.buffer.write(reset)
                sys.stdout.buffer.flush()
    else:
        {'watch': watch, 'claim': claim, 'release': release}[args.action]()
    return 0


if __name__ == '__main__':
    sys.exit(main())
