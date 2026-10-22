#!/usr/bin/python3
"""Optional local HDMI console. Only the small watcher/VT setup runs as root.

The renderer and its persistent Artisan reader run as www-data. No HTTP listener,
browser, X server, GPIO writes or diagnostic requests are involved.
"""
import argparse
import curses
import fcntl
import glob
import json
import math
import os
from pathlib import Path
import selectors
import signal
import subprocess
import sys
import tempfile
import textwrap
import time

CONFIG = Path('/etc/default/cmprovision-wallboard.json')
RUNTIME = Path('/run/cmprovision-wallboard')
UNIT = 'cmprovision-wallboard-display.service'
TTY = '/dev/tty8'
DEFAULTS = dict(mode='off', palette='warm', view='auto', rotation=12)
CHOICES = dict(mode=('off', 'auto', 'on'), palette=('warm', 'phosphor', 'mono'),
               view=('auto', 'desk', 'flow', 'engineer'))
STOP = False


def valid_config(data):
    if not isinstance(data, dict) or set(data) != set(DEFAULTS):
        raise ValueError('Expected mode, palette, view and rotation.')
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
    try:
        while not STOP:
            config, connected = read_config(), hdmi_connected()
            desired = wanted(config, connected)
            if desired != active or time.monotonic() - last_check >= 10:
                active = systemctl('is-active', '--quiet')
                if desired and not active:
                    active = systemctl('start')
                elif not desired and active:
                    systemctl('stop')
                    active = systemctl('is-active', '--quiet')
                last_check = time.monotonic()
            state = dict(state='displaying' if active else ('error' if desired else ('off' if config['mode'] == 'off' else 'waiting for HDMI')),
                         connected=connected, mode=config['mode'])
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


def claim():
    RUNTIME.mkdir(mode=0o755, exist_ok=True)
    previous = active_vt()
    if previous.startswith('tty') and previous[3:].isdigit() and previous != 'tty8':
        (RUNTIME / 'previous-vt').write_text(previous, encoding='ascii')
    # Font is local to tty8; other virtual terminals retain the administrator's font.
    for name in ('Lat15-Terminus28x14.psf.gz', 'Lat15-TerminusBold28x14.psf.gz', 'Lat15-TerminusBold32x16.psf.gz'):
        font = Path('/usr/share/consolefonts') / name
        if font.is_file():
            subprocess.run(['/usr/bin/setfont', '-C', TTY, str(font)], check=False, timeout=5)
            break
    subprocess.run(['/usr/bin/chvt', '8'], check=True, timeout=5)
    # Keep the unattended display from blanking; restore the kernel's configured interval on release.
    with open(TTY, 'w') as tty:
        tty.write('\x1b[9;0]')


def release():
    try:
        interval = int(Path('/sys/module/kernel/parameters/consoleblank').read_text())
        with open(TTY, 'w') as tty:
            tty.write('\x1b[9;%d]' % min(60, math.ceil(interval / 60)))
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
    def __init__(self, cols, rows):
        self.cols, self.rows = cols, rows
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
        for row in range(3):
            c.text(0, row, ' '.join(SMALL[ch][row] for ch in 'CMPROVISION'), 'accent')
        x = max(48, c.cols - 36)
        c.text(x, 0, safe(data.get('host')) + ' / HDMI', 'dim')
        c.text(x, 1, 'ON STAND %02d   DONE %02d' % (len(data.get('modules', [])), counts.get('done', 0)), 'good')
        c.text(x, 2, 'RUN %02d  FAIL %02d  STALE %02d' % (counts.get('running', 0), counts.get('failed', 0), counts.get('stale', 0)), 'warn')
        c.text(0, 4, 'PROJECT: ' + safe(data.get('project') or 'none active'), 'fg', c.cols - 32)
        c.text(c.cols - 30, 4, 'UPDATED ' + age(data.get('generated_at'), now), 'dim')
        c.line(5)
        return 7
    c.text(0, 0, 'cmprovision // ' + safe(data.get('host', 'HDMI')), 'accent')
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
    max_columns = max(1, (width - 2) // 9)
    for block in switch.get('blocks', []):
        rows = max(1, min(2, int(block['rows'])))
        ports = block['ports']
        for start in range(0, len(ports), max_columns * rows):
            part = ports[start:start + max_columns * rows]
            result.append(dict(label=block['label'], rows=rows, ports=part,
                               width=math.ceil(len(part) / rows) * 9, height=2 + rows * 3))
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
            px, py = x + 2 + bx + (i // bank['rows']) * 9, y + 3 + by + (i % bank['rows']) * 3
            mark, ink = port_mark(port, modules)
            number = str(port.get('number') if port.get('number') is not None else port['name'])
            c.text(px, py, '.------.', 'dim')
            c.text(px, py + 1, '|%s|' % (number + ' ' + mark)[:6].ljust(6), ink)
            c.text(px, py + 2, "'------'", 'dim')
    if len(pages) > 1 or len(switches) > 1:
        c.text(x + 2, y + height - 2, 'SWITCH %d/%d / PORT PAGE %d/%d' %
               (tick % len(switches) + 1, len(switches), page_no + 1, len(pages)), 'dim', width - 4)


def detail(c, x, y, width, module, now):
    if module is None:
        c.wrap(x, y, 'Waiting for a connected module. Plug Ethernet into the provisioning switch.', width, 4, 'dim')
        return
    ink = INK.get(module.get('state'), 'dim')
    c.text(x, y, module['name'], 'accent', width)
    c.text(x, y + 1, module['serial'], 'dim', width)
    c.text(x, y + 2, module['port'], 'dim', width)
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
    width = max(30, round(c.cols * 0.60))
    height = min(17, max(11, c.rows - y - 10))
    if c.cols < 100:
        ports(c, 0, y, c.cols, height, data.get('switches', []), data.get('modules', []), tick)
        table_y = y + height + 1
    else:
        ports(c, 0, y, width, height, data.get('switches', []), data.get('modules', []), tick)
        c.box(width + 2, y, c.cols - width - 2, height, 'DEVICE CARD')
        detail(c, width + 4, y + 1, c.cols - width - 6, module, now)
        table_y = y + height + 1
    c.text(0, table_y, 'PORT / DEVICE                     PHASE        PROGRESS / RESULT', 'dim')
    capacity = max(1, c.rows - 5 - table_y - 1)
    modules = data.get('modules', [])
    start = (tick % max(1, math.ceil(len(modules) / capacity))) * capacity
    for i, m in enumerate(modules[start:start + capacity]):
        text = '%-33s %-12s %s' % ((safe(m['port']) + ' / ' + safe(m['name']))[:33], phase(m)[:12],
                (str(m['percent']) + '% / ETA ' + duration(m.get('eta'))) if m.get('percent') is not None and m['phase'] != 'done' else (m.get('detail') or 'Cycle ' + duration(m.get('cycle'))))
        c.text(0, table_y + i + 1, text, INK.get(m['state'], 'dim'))
    if not modules:
        c.text(0, table_y + 2, 'No modules currently on the stand.', 'dim')


def flow(c, data, module, now, tick):
    y = header(c, data, now)
    c.text(0, y, '[ WLAN Pi M4+ ] ---> [ SWITCH / PoE ] ---> [ CM4 SENSOR FLEET ]', 'accent')
    modules = data.get('modules', [])
    columns = 2 if c.cols >= 90 else 1
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


def frame(data, cols, rows, selected=0, view='auto', now=None, tick=0, paused=False):
    now = now or time.time()
    c = Canvas(max(1, cols), max(1, rows))
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
        c.text(0, rows - 4, '! ' + m['port'] + ': ' + (m.get('detail') or phase(m)), 'fail')
    elif any(not s['answering'] for s in data.get('switches', [])):
        c.text(0, rows - 4, '! A switch is not answering. Ports are last known data.', 'warn')
    else:
        unknown = sum(p.get('state') in ('unrecognized', 'quiet') for s in data.get('switches', []) for b in s['blocks'] for p in b['ports'])
        c.text(0, rows - 4, ('! %d unrecognized/quiet ports. Open Ports in the web UI.' % unknown) if unknown else 'READ ONLY / ' + safe(data.get('clock')), 'warn' if unknown else 'dim')
    c.text(0, rows - 2, ('PAUSED' if paused else 'AUTO ROTATION') + ' / CARD %d/%d / %s' %
           ((selected % len(modules)) + 1 if modules else 0, len(modules), view.upper()), 'dim')
    c.text(max(0, cols - 57), rows - 1, 'Alt+F1 console / Alt+F8 board / <- -> / Space pause / V view', 'dim')
    return c


class Reader:
    def __init__(self):
        self.process = None
        self.buffer = b''
        self.selector = selectors.DefaultSelector()
        self.last_start = -10

    def poll(self):
        if self.process is None and time.monotonic() - self.last_start >= 10:
            self.last_start = time.monotonic()
            self.process = subprocess.Popen(['/usr/bin/php', '/var/lib/cmprovision/artisan', 'wallboard:snapshot', '--watch', '--no-ansi'],
                                            stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
            os.set_blocking(self.process.stdout.fileno(), False)
            self.selector.register(self.process.stdout, selectors.EVENT_READ)
        newest = None
        for key, _ in self.selector.select(0):
            chunk = os.read(key.fd, 65536)
            if not chunk:
                self.close()
                return newest
            self.buffer += chunk
            if len(self.buffer) > 2097152:
                self.close()
                return newest
            while b'\n' in self.buffer:
                line, self.buffer = self.buffer.split(b'\n', 1)
                try:
                    value = json.loads(line)
                    if value.get('version') == 1 and 'generated_at' in value and isinstance(value.get('modules'), list):
                        newest = value
                except (ValueError, TypeError, AttributeError):
                    pass
        return newest

    def close(self):
        if self.process:
            self.selector.unregister(self.process.stdout)
            self.process.stdout.close()
            self.process.terminate()
            try:
                self.process.wait(timeout=3)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait()
            self.process = None
            self.buffer = b''


def colors(palette):
    if not curses.has_colors():
        return dict.fromkeys(['fg', 'dim', 'accent', 'good', 'run', 'fail', 'warn', 'board'], 0)
    curses.start_color()
    colors = dict(fg=7, dim=7, accent=3, good=2, run=6, fail=1, warn=3, board=5)
    if palette == 'mono':
        colors = dict.fromkeys(colors, 7)
    elif palette == 'phosphor':
        colors['accent'] = colors['board'] = 2
    result = {}
    for index, (name, color) in enumerate(colors.items(), 1):
        curses.init_pair(index, color, 0)
        result[name] = curses.color_pair(index) | (0 if name == 'dim' else curses.A_BOLD)
    return result


def display(screen):
    curses.curs_set(0)
    screen.timeout(1000)
    screen.keypad(True)
    reader = Reader()
    data = dict(version=1, generated_at=0, modules=[], switches=[], counts={})
    selected, tick, last_rotation, paused, view_override = 0, 0, time.monotonic(), False, None
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
            width, height = min(cols, 180), min(rows, 54)
            canvas = frame(data, width, height, selected, view_override or config['view'], tick=tick, paused=paused)
            inks = colors(config['palette'])
            ox, oy = max(0, (cols - width) // 2), max(0, (rows - height) // 2)
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
        print(frame(data, args.cols, args.rows, view=args.view).plain())
        return 0
    signal.signal(signal.SIGTERM, lambda *_: globals().__setitem__('STOP', True))
    signal.signal(signal.SIGINT, lambda *_: globals().__setitem__('STOP', True))
    if args.action == 'display':
        curses.wrapper(display)
    else:
        {'watch': watch, 'claim': claim, 'release': release}[args.action]()
    return 0


if __name__ == '__main__':
    sys.exit(main())
