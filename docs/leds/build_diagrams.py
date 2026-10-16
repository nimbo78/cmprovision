#!/usr/bin/env python3
"""Builds the figures of docs/leds.md (SVG, standard library only):

    python docs/leds/build_diagrams.py [output directory, docs/leds by default]

The timings are read from tools/ledpattern/ledpattern.c, resources/views/scriptexecute/leds.blade.php and
app/Models/Cm.php, so the figures follow the code. When the code changes in a way this script does not know, it stops
instead of drawing a stale figure. LedDocsTest builds the figures again and compares them with the committed files.
"""

import math
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent


def source(path):
    return (ROOT / path).read_text(encoding='utf-8')


C_SOURCE = source('tools/ledpattern/ledpattern.c')
BLADE = source('resources/views/scriptexecute/leds.blade.php')
MODEL = source('app/Models/Cm.php')


def stop(message):
    sys.exit('build_diagrams.py: ' + message + ' - the code changed: update this script and docs/leds.md')


def c_define(name):
    m = re.search(r'#define\s+' + name + r'\s+([0-9.]+)', C_SOURCE)
    if not m:
        stop('no #define ' + name + ' in ledpattern.c')
    return float(m.group(1))


def blade_numbers(pattern):
    m = re.search(pattern, BLADE)
    if not m:
        stop('leds.blade.php no longer matches ' + pattern)
    return [int(g) for g in m.groups()]


# What ledpattern.c draws: the formulas below mirror its duties() and bump(); these lines must still be there
FRAME_US = c_define('FRAME_US')
DONE_CYCLE = c_define('DONE_CYCLE')
DONE_BUMP = c_define('DONE_BUMP')
FAIL_ACT_DUTY = c_define('FAIL_ACT_DUTY')
FAIL_CYCLE = c_define('FAIL_CYCLE')
IDENTIFY_HALF = c_define('IDENTIFY_HALF')
for needle in ('return s * s * s * s;', '*act = bump(t / DONE_CYCLE);', '*pwr = bump(t / DONE_CYCLE + 0.5);',
               '*act = *pwr = bump(t / DONE_CYCLE);', '*pwr = (p < 0.2 || (p >= 0.5 && p < 0.7)) ? 1 : 0;',
               '*act = p < IDENTIFY_HALF ? 1 : 0;', '*pwr = 1 - *act;'):
    if needle not in C_SOURCE:
        stop('ledpattern.c no longer has: ' + needle)
FAIL_FLASHES = ((0.0, 0.2), (0.5, 0.7))

# What leds.blade.php sets: the kernel timers of "progress" and of the states without the helper
PROGRESS_MS = blade_numbers(r'led_set "\$LED_PWR" none 1\n\s+led_set "\$LED_ACT" timer (\d+) (\d+)')
FALLBACK_DONE = blade_numbers(r'done\) led_set "\$LED_ACT" timer (\d+) (\d+); led_set "\$LED_PWR" timer (\d+) (\d+)')
FALLBACK_IDENTIFY = blade_numbers(r'identify\) led_set "\$LED_ACT" timer (\d+) (\d+); led_set "\$LED_PWR" timer (\d+) (\d+)')
if 'fail) led_set "$LED_ACT" none 0; led_set "$LED_PWR" heartbeat' not in BLADE:
    stop('leds.blade.php no longer shows fail by heartbeat without the helper')
POLL_SECONDS = blade_numbers(r'LED_POLL_SECONDS=\$\{LED_POLL_SECONDS:-(\d+)\}')[0]
IDENTIFY_POLL_SECONDS = blade_numbers(r'LED_IDENTIFY_POLL_SECONDS=\$\{LED_IDENTIFY_POLL_SECONDS:-(\d+)\}')[0]
m = re.search(r'const IDENTIFY_SECONDS = (\d+);', MODEL)
if not m:
    stop('no IDENTIFY_SECONDS in Cm.php')
IDENTIFY_SECONDS = int(m.group(1))

# Look
FONT = "'Segoe UI', 'Helvetica Neue', Arial, 'DejaVu Sans', sans-serif"
MONO = "'Cascadia Mono', Consolas, 'DejaVu Sans Mono', monospace"
PAPER, CARD, INK, MUTED = '#FFF7E8', '#FFFDF8', '#2A1A0C', '#7A6650'
GRID, LANE, RULE, SOFT = '#EADFCB', '#F4E8D2', '#D8C7A8', '#FBEBD0'
RED, ORANGE, CRIMSON = '#D9342B', '#E2681C', '#8E1B2E'


def n(x):
    s = ('%.1f' % x).rstrip('0').rstrip('.')
    return '0' if s in ('-0', '') else s


def esc(s):
    return s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


def ru(x):
    """A number the Russian way: 0,15"""
    return ('%g' % x).replace('.', ',')


def text(x, y, s, size=13, weight='normal', fill=INK, anchor='start', family=FONT):
    return ('<text x="%s" y="%s" font-family="%s" font-size="%s" font-weight="%s" fill="%s" text-anchor="%s">%s</text>'
            % (n(x), n(y), family, size, weight, fill, anchor, esc(s)))


def rect(x, y, w, h, fill, stroke=None, rx=0, sw=1, extra=''):
    return ('<rect x="%s" y="%s" width="%s" height="%s" rx="%s" fill="%s"%s%s/>'
            % (n(x), n(y), n(w), n(h), n(rx), fill, (' stroke="%s" stroke-width="%s"' % (stroke, sw)) if stroke else '', extra))


def line(x1, y1, x2, y2, stroke=GRID, sw=1, dash=None):
    return ('<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="%s" stroke-width="%s"%s/>'
            % (n(x1), n(y1), n(x2), n(y2), stroke, sw, (' stroke-dasharray="%s"' % dash) if dash else ''))


def svg(width, height, title, desc, body):
    return ('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img">\n'
            '<title>%s</title>\n<desc>%s</desc>\n'
            '<defs>\n'
            '<marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse">'
            '<path d="M0,0 L10,5 L0,10 z" fill="%s"/></marker>\n'
            '<marker id="arrow-red" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse">'
            '<path d="M0,0 L10,5 L0,10 z" fill="%s"/></marker>\n'
            '</defs>\n'
            '<rect width="%d" height="%d" fill="%s"/>\n%s\n</svg>\n'
            % (width, height, width, height, esc(title), esc(desc), MUTED, RED, width, height, PAPER, '\n'.join(body)))


def heading(title, subtitle):
    return [text(24, 38, title, 22, 'bold'), rect(24, 48, 48, 4, ORANGE, rx=2), text(24, 74, subtitle, 13, fill=MUTED)]


# ---------------------------------------------------------------- what the LEDs do, as functions of time (0..1)

def bump(phase):
    phase -= math.floor(phase)
    if phase >= DONE_BUMP:
        return 0.0
    s = math.sin(math.pi * phase / DONE_BUMP)
    return s * s * s * s


def helper(mode):
    """ledpattern.c duties(): (act, pwr) at t seconds"""
    def duties(t):
        if mode == 'done':
            return bump(t / DONE_CYCLE), bump(t / DONE_CYCLE + 0.5)
        if mode == 'ready':
            v = bump(t / DONE_CYCLE)
            return v, v
        if mode == 'fail':
            p = t % FAIL_CYCLE
            return FAIL_ACT_DUTY, 1.0 if any(a <= p < b for a, b in FAIL_FLASHES) else 0.0
        p = t % (2 * IDENTIFY_HALF)
        act = 1.0 if p < IDENTIFY_HALF else 0.0
        return act, 1.0 - act
    return duties


def timer(on_ms, off_ms):
    """The kernel timer trigger: on first"""
    period = (on_ms + off_ms) / 1000.0
    return lambda t: 1.0 if (t % period) < on_ms / 1000.0 else 0.0


HEARTBEAT_PERIOD = 3.65  # ledtrig-heartbeat: 0.3 + 6.7 / (2 + load) seconds, about 3.65 s on an idle module


def heartbeat(t):
    p = t % HEARTBEAT_PERIOD
    return 1.0 if p < 0.07 or HEARTBEAT_PERIOD / 4 <= p < HEARTBEAT_PERIOD / 4 + 0.07 else 0.0


def steady(v):
    return lambda t: v


def split(mode):
    d = helper(mode)
    return (lambda t: d(t)[0]), (lambda t: d(t)[1])


# ---------------------------------------------------------------- drawing

def rdp(points, eps):
    if len(points) < 3:
        return points
    keep = [False] * len(points)
    keep[0] = keep[-1] = True
    stack = [(0, len(points) - 1)]
    while stack:
        a, b = stack.pop()
        (x1, y1), (x2, y2) = points[a], points[b]
        dx, dy = x2 - x1, y2 - y1
        norm = math.hypot(dx, dy)
        best, idx = -1.0, -1
        for i in range(a + 1, b):
            x0, y0 = points[i]
            d = abs(dy * (x0 - x1) - dx * (y0 - y1)) / norm if norm else math.hypot(x0 - x1, y0 - y1)
            if d > best:
                best, idx = d, i
        if best > eps:
            keep[idx] = True
            stack.append((a, idx))
            stack.append((idx, b))
    return [p for p, k in zip(points, keep) if k]


def lane(x, y, w, h, fn, seconds, color=RED, opacity=0.30, sw=1.6, dash=None, fill=True):
    """One LED's brightness over `seconds`, drawn in the box (x, y, w, h); 1 ms resolution, then thinned. The brightness is
    rounded first: the last bit of sin() differs between C libraries, and the figures must come out the same everywhere."""
    pxs = w / seconds
    pts = [(i * pxs / 1000.0, (1.0 - max(0.0, min(1.0, round(fn(i / 1000.0), 6)))) * h) for i in range(int(round(seconds * 1000)) + 1)]
    pts = rdp(pts, 0.1)
    out = []
    if fill:
        d = 'M%s,%s ' % (n(x), n(y + h)) + ' '.join('L%s,%s' % (n(x + px), n(y + py)) for px, py in pts) + ' L%s,%s Z' % (n(x + w), n(y + h))
        out.append('<path d="%s" fill="%s" fill-opacity="%s"/>' % (d, color, opacity))
    out.append('<polyline points="%s" fill="none" stroke="%s" stroke-width="%s" stroke-linejoin="round" stroke-linecap="round"%s/>'
               % (' '.join('%s,%s' % (n(x + px), n(y + py)) for px, py in pts), color, sw, (' stroke-dasharray="%s"' % dash) if dash else ''))
    return out


def pair(x, y, w, h, act, pwr, seconds, label_size=10, gap=6, labels=True):
    """The ACT lane over the PWR lane"""
    out = []
    for i, (name, fn) in enumerate((('ACT', act), ('PWR', pwr))):
        ly = y + i * (h + gap)
        out.append(rect(x, ly, w, h, LANE, rx=3))
        out += lane(x, ly, w, h, fn, seconds)
        if labels:
            out.append(text(x - 6, ly + h / 2 + 4, name, label_size, 'bold', MUTED, 'end', MONO))
    return out


def chip(x, y, label, fill=SOFT, stroke=ORANGE, color=INK):
    w = 8 * len(label) + 16
    return [rect(x, y, w, 20, fill, stroke, rx=10), text(x + w / 2, y + 14, label, 12, 'bold', color, 'middle', MONO)]


# ---------------------------------------------------------------- figure: the modes

def rows_figure(filename, title, subtitle, rows, desc, footer=None):
    """Rows of modes, each with a name block on the left and the two traces on the right over a common 3 s axis"""
    left, trace_x, seconds, pxs = 24, 300, 3.0, 260.0
    lane_h, gap, row_h = 30, 6, 112
    top = 96
    width = int(trace_x + seconds * pxs + 24)
    height = top + len(rows) * row_h + 40 + (24 if footer else 0)
    body = heading(title, subtitle)
    body.append(text(width - 24, 38, 'высота линии — яркость светодиода, красное — светится', 11, 'normal', MUTED, 'end'))
    for i in range(int(seconds * 2) + 1):                      # grid: every half second, stronger every second
        gx = trace_x + i * pxs / 2
        body.append(line(gx, top - 8, gx, top + len(rows) * row_h, GRID if i % 2 else RULE, 1))
    for r, row in enumerate(rows):
        y = top + r * row_h
        if r:
            body.append(line(left, y, width - 24, y, RULE, 1))
        body.append(text(left, y + 28, row['title'], 17, 'bold'))
        body += chip(left, y + 36, row['key'])
        for k, s in enumerate(row['lines']):
            body.append(text(left, y + 74 + k * 14, s, 12, fill=MUTED))
        body += pair(trace_x, y + 23, seconds * pxs, lane_h, row['act'], row['pwr'], seconds, gap=gap)
        for t, which, s in row.get('notes', []):
            ny = y + 23 + (lane_h + gap) * which + lane_h / 2 + 4
            body.append(text(trace_x + t * pxs, ny, s, 11, 'normal', INK, 'middle'))
    ay = top + len(rows) * row_h + 22
    for i in range(int(seconds) + 1):
        body.append(text(trace_x + i * pxs, ay, ('%d с' % i) if i else '0', 12, 'normal', MUTED, 'middle'))
    if footer:
        body.append(text(left, ay + 24, footer, 11, 'normal', MUTED))
    return svg(width, height, title, desc, body)


def figure_modes():
    on, off = PROGRESS_MS
    flashes = ' и '.join('%s…%s с' % (ru(a), ru(b)) for a, b in FAIL_FLASHES)
    return rows_figure(
        'modes.svg', 'Режимы светодиодов',
        'Три секунды каждого режима. ACT — у разъёма Ethernet, PWR — у слота microSD, оба красные.',
        [
            dict(key='progress', title='Идёт прошивка', lines=['Запись, сверка, пост-скрипты.', 'ACT мигает %d раз в секунду,' % round(1000 / (on + off)), 'PWR горит.'],
                 act=timer(on, off), pwr=steady(1.0)),
            dict(key='done', title='Готово', lines=['Прогон закончен. ACT и PWR по', 'очереди плавно загораются и', 'гаснут, цикл %s секунды.' % ru(DONE_CYCLE)],
                 act=split('done')[0], pwr=split('done')[1]),
            dict(key='ready', title='ОС проверена', lines=['Проверка ОС прошла (2.6.0).', 'ACT и PWR вместе плавно', 'загораются и гаснут.'],
                 act=split('ready')[0], pwr=split('ready')[1]),
            dict(key='fail', title='Ошибка', lines=['ACT тускло горит, PWR даёт две', 'вспышки за секунду, затем', 'тёмная пауза. Цикл %s секунды.' % ru(FAIL_CYCLE)],
                 act=split('fail')[0], pwr=split('fail')[1],
                 notes=[(2.45, 0, 'ACT тускло: %d%% яркости' % round(FAIL_ACT_DUTY * 100))]),
            dict(key='identify', title='Опознать', lines=['Кнопка Identify: ACT и PWR', 'резко чередуются каждые', '%s секунды.' % ru(IDENTIFY_HALF)],
                 act=split('identify')[0], pwr=split('identify')[1]),
        ],
        'Пять режимов светодиодов ACT и PWR: идёт прошивка, готово, ОС проверена, ошибка, опознать. '
        'У каждого показаны яркость ACT и PWR за три секунды.',
        'Всё, кроме progress, рисует помощник ledpattern программным ШИМ; progress — таймер ядра.')


def figure_fallback():
    d_on, d_off, dp_on, dp_off = FALLBACK_DONE
    i_on, i_off, ip_on, ip_off = FALLBACK_IDENTIFY
    return rows_figure(
        'modes-fallback.svg', 'Режимы без помощника ledpattern',
        'Если помощник не скачался или не запустился, те же состояния показываются триггерами ядра.',
        [
            dict(key='progress', title='Идёт прошивка', lines=['Как с помощником: это и так', 'таймер ядра.'], act=timer(*PROGRESS_MS), pwr=steady(1.0)),
            dict(key='done', title='Готово', lines=['Оба светодиода мигают вместе:', '%s с горят, %s с нет.' % (ru(d_on / 1000), ru(d_off / 1000)), 'Плавности нет.'],
                 act=timer(d_on, d_off), pwr=timer(dp_on, dp_off)),
            dict(key='ready', title='ОС проверена', lines=['Оба светодиода просто горят.'], act=steady(1.0), pwr=steady(1.0)),
            dict(key='fail', title='Ошибка', lines=['ACT погашен, PWR — триггер', 'heartbeat: две короткие вспышки', 'и пауза (ритм зависит от нагрузки).'],
                 act=steady(0.0), pwr=heartbeat),
            dict(key='identify', title='Опознать', lines=['Оба мигают вместе по %s с:' % ru(i_on / 1000), 'чередоваться без помощника', 'они не умеют.'],
                 act=timer(i_on, i_off), pwr=timer(ip_on, ip_off)),
        ],
        'Режимы светодиодов без помощника ledpattern: ядерные триггеры timer и heartbeat, без плавности и без чередования.',
        'heartbeat нарисован схематично: его период 0,3 + 6,7 / (2 + нагрузка) секунды, у простаивающего модуля около 3,6 с.')


# ---------------------------------------------------------------- figure: the case

PANEL, PANEL_TEXT = '#3B3836', '#ECE7DF'


def led(cx, cy, label, color=RED, glow=True):
    out = []
    if glow:
        out.append('<circle cx="%s" cy="%s" r="16" fill="%s" fill-opacity="0.20"/>' % (n(cx), n(cy), color))
        out.append('<circle cx="%s" cy="%s" r="10" fill="%s" fill-opacity="0.40"/>' % (n(cx), n(cy), color))
    out.append('<circle cx="%s" cy="%s" r="5" fill="%s" stroke="#1B1A19" stroke-width="1"/>' % (n(cx), n(cy), color))
    out.append(text(cx, cy + 36, label, 15, 'bold', PANEL_TEXT, 'middle', MONO))
    return out


def figure_case():
    body = heading('Где смотреть светодиоды', 'Схема торцов WLAN Pi M4+, не в масштабе.')
    # the Ethernet end
    body.append(text(30, 112, 'Торец с Ethernet', 14, 'bold'))
    body.append(rect(30, 124, 480, 170, PANEL, INK, rx=10, sw=2))
    body.append(rect(54, 150, 120, 100, '#B9B6B1', '#6F6B66', rx=4, sw=2))              # the RJ45 jack
    body.append(rect(70, 168, 88, 54, '#8C8884', '#6F6B66', rx=3))
    body.append(text(114, 274, 'RJ45', 13, 'normal', PANEL_TEXT, 'middle', MONO))
    body += led(212, 200, 'ACT')
    body.append(rect(330, 212, 140, 18, '#1B1A19', '#6F6B66', rx=3))                      # the microSD slot
    body.append(text(400, 256, 'слот microSD', 13, 'normal', PANEL_TEXT, 'middle', MONO))
    body += led(400, 152, 'PWR')
    # the USB-C end
    body.append(text(560, 112, 'Торец с USB-C', 14, 'bold'))
    body.append(rect(560, 124, 210, 170, PANEL, INK, rx=10, sw=2))
    body.append(rect(590, 178, 70, 26, '#B9B6B1', '#6F6B66', rx=13, sw=2))               # the USB-C port
    body.append(text(625, 228, 'USB-C', 13, 'normal', PANEL_TEXT, 'middle', MONO))
    body += led(722, 191, '5V', color='#F4F1EA')
    body.append(text(665, 276, 'питание напрямую: горит всегда', 11, 'normal', PANEL_TEXT, 'middle'))
    # what it means
    body.append(text(30, 326, 'ACT — у разъёма Ethernet, PWR — у слота microSD. Оба красные: режимы различаются ритмом', 13))
    body.append(text(30, 346, 'и тем, какой из двух горит. Светодиод 5V от cmprovision не зависит.', 13))
    return svg(800, 372, 'Где смотреть светодиоды',
               'Схема торцов модуля: у Ethernet светодиоды ACT у разъёма и PWR у слота microSD, у USB-C светодиод 5V, который горит всегда.', body)


# ---------------------------------------------------------------- figure: the life of a run

def box(x, y, w, h, title, lines, stroke=RULE, fill=CARD):
    out = [rect(x, y, w, h, fill, stroke, rx=8, sw=1.5), text(x + 14, y + 26, title, 15, 'bold')]
    for k, s in enumerate(lines):
        out.append(text(x + 14, y + 48 + k * 16, s, 12, fill=MUTED))
    return out


def arrow(d, red=False, dash=None):
    return ('<path d="%s" fill="none" stroke="%s" stroke-width="2"%s marker-end="url(#%s)"/>'
            % (d, RED if red else MUTED, (' stroke-dasharray="%s"' % dash) if dash else '', 'arrow-red' if red else 'arrow'))


def figure_lifecycle():
    body = heading('Что показывает модуль на каждом этапе', 'От включения питания до итога прогона. В рамке — имя режима из таблицы режимов.')
    secs = 3.0

    def mini(x, y, w, act, pwr, labels=False):
        return pair(x, y, w, 12, act, pwr, secs, label_size=9, gap=3, labels=labels)

    # the chain: boot, pre-install scripts, write and verify
    cy, ch, cw = 100, 190, 180
    body += box(24, cy, cw, ch, 'Включение', ['Прошивка Pi берёт ядро', 'и утилитарную ОС по', 'сети: около полуминуты.', 'Светодиоды ведут', 'прошивка и ядро.'])
    body += chip(38, cy + ch - 34, 'не cmprovision', fill=LANE, stroke=RULE, color=MUTED)
    body += box(228, cy, cw, ch, 'Пре-скрипты', ['Прошивка EEPROM и', 'скрипты проекта. ACT', 'тёмный: его вывод', 'занят шиной SPI.'])
    body += mini(268, cy + 112, 124, steady(0.0), steady(1.0), labels=False)
    body.append(text(262, cy + 122, 'ACT', 9, 'bold', MUTED, 'end', MONO))
    body.append(text(262, cy + 137, 'PWR', 9, 'bold', MUTED, 'end', MONO))
    body += chip(242, cy + ch - 34, 'progress')
    body += box(432, cy, cw, ch, 'Запись и сверка', ['Образ в eMMC, потом', 'сверка SHA256 и', 'пост-скрипты.', 'ACT уже мигает.'])
    body += mini(472, cy + 112, 124, timer(*PROGRESS_MS), steady(1.0), labels=False)
    body.append(text(466, cy + 122, 'ACT', 9, 'bold', MUTED, 'end', MONO))
    body.append(text(466, cy + 137, 'PWR', 9, 'bold', MUTED, 'end', MONO))
    body += chip(446, cy + ch - 34, 'progress')
    body.append(arrow('M204,195 L224,195'))
    body.append(arrow('M408,195 L428,195'))

    # the ways it ends
    rx, rw = 780, 296
    body += box(rx, 96, rw, 118, 'Готово', ['Проект без проверки ОС. Модуль ждёт,', 'раз в %d с спрашивает: опознаться?' % POLL_SECONDS])
    body += chip(rx + 14, 168, 'done')
    body += mini(rx + 100, 174, 182, *split('done'))
    body += box(rx, 232, rw, 146, 'С проверкой ОС', ['Перезагрузка в записанную систему:', 'её служба проверяет и отчитывается.'])
    body += mini(rx + 14, 304, 124, *split('ready'))
    body += chip(rx + 14, 340, 'ready')
    body.append(text(rx + 78, 354, 'прошла', 11, fill=MUTED))
    body += mini(rx + 158, 304, 124, *split('fail'))
    body += chip(rx + 158, 340, 'fail')
    body.append(text(rx + 214, 354, 'не прошла', 11, fill=MUTED))
    body += box(rx, 392, rw, 118, 'Ошибка на любом шаге', ['Сбой скрипта, отказ сервера на старте', 'или его ошибка. Модуль ждёт оператора.'], stroke=RED)
    body += chip(rx + 14, 464, 'fail')
    body += mini(rx + 100, 470, 182, *split('fail'))

    # connectors
    body.append('<path d="M612,195 L650,195" fill="none" stroke="%s" stroke-width="2"/>' % MUTED)
    body.append(arrow('M650,195 L650,155 L776,155'))
    body.append(arrow('M650,195 L650,305 L776,305'))
    body.append(text(660, 147, 'без проверки ОС', 11, fill=MUTED))
    body.append(text(660, 297, 'с проверкой ОС', 11, fill=MUTED))
    body.append('<path d="M522,290 L522,451" fill="none" stroke="%s" stroke-width="2" stroke-dasharray="6 5"/>' % RED)
    body.append(arrow('M318,290 L318,451 L776,451', red=True, dash='6 5'))
    body.append(text(400, 443, 'любой сбой', 11, fill=RED))

    # identify
    body += box(24, 540, 1052, 116, 'Опознать', [
        'Кнопка Identify в карточке модуля и на дашборде. Закончивший модуль, который ещё на стенде,',
        'раз в %d с спрашивает сервер. Услышав identify, он мигает %d с или до Stop (опрос раз в %d с)' % (POLL_SECONDS, IDENTIFY_SECONDS, IDENTIFY_POLL_SECONDS),
        'и возвращается к «готово» или «ошибка». Из новой системы после проверки ОС модуль сервер',
        'не спрашивает: Identify там нет.'])
    body += chip(868, 600, 'identify')
    body += mini(868, 562, 190, *split('identify'))
    return svg(1100, 680, 'Что показывает модуль на каждом этапе',
               'Включение, пре-скрипты, запись и сверка, затем готово, проверка ОС или ошибка; опознать поверх итога.', body)


# ---------------------------------------------------------------- figure: how the brightness is drawn

def figure_pwm():
    body = heading('Как рисуется яркость', 'Светодиод умеет только «включён» и «выключен»; плавность даёт программный ШИМ.')
    x0, w, seconds = 40, 920, DONE_CYCLE
    pxs = w / seconds
    body.append(text(24, 112, 'Режим done: один цикл', 14, 'bold'))
    body.append(rect(x0, 130, w, 90, LANE, rx=3))
    act, pwr = split('done')
    body += lane(x0, 130, w, 90, pwr, seconds, color=CRIMSON, sw=2, dash='5 4', fill=False)
    body += lane(x0, 130, w, 90, act, seconds)
    lx = x0 + w - 190                                     # legend, top right of the lane
    body.append(line(lx, 146, lx + 28, 146, RED, 2))
    body.append(text(lx + 36, 150, 'ACT', 11, 'bold', MUTED, 'start', MONO))
    body.append(line(lx + 90, 146, lx + 118, 146, CRIMSON, 2, '5 4'))
    body.append(text(lx + 126, 150, 'PWR', 11, 'bold', CRIMSON, 'start', MONO))
    bump_w = DONE_BUMP * DONE_CYCLE * pxs
    shift = DONE_CYCLE / 2 * pxs
    body.append(line(x0, 232, x0 + bump_w, 232, INK, 1.5))
    body.append(text(x0 + bump_w / 2, 250, 'подъём и спад: %s%% цикла, %s с' % (ru(DONE_BUMP * 100), ru(DONE_BUMP * DONE_CYCLE)), 12, anchor='middle'))
    body.append(line(x0 + shift, 232, x0 + shift + bump_w, 232, CRIMSON, 1.5))
    body.append(text(x0 + shift + bump_w / 2, 250, 'PWR: то же, на полцикла (%s с) позже' % ru(DONE_CYCLE / 2), 12, fill=CRIMSON, anchor='middle'))
    gap_x = x0 + (bump_w + shift) / 2
    body.append(line(gap_x, 236, gap_x, 258, MUTED, 1))
    body.append(text(gap_x, 272, 'тёмная пауза %s с: оба погашены' % ru(DONE_CYCLE * (0.5 - DONE_BUMP)), 11, fill=MUTED, anchor='middle'))
    body.append(text(x0, 296, 'Яркость — sin⁴ от фазы: плавный подъём и спад без резкого края.', 12, fill=MUTED))
    for i in range(int(seconds) + 1):
        body.append(text(x0 + i * pxs, 318, ('%d с' % i) if i else '0', 11, 'normal', MUTED, 'middle'))
    # the frames
    frame_ms = FRAME_US / 1000.0
    body.append(text(24, 356, 'Каждый кадр — %s мс (%d кадров в секунду)' % (ru(frame_ms), round(1e6 / FRAME_US)), 14, 'bold'))
    body.append(text(24, 376, 'Светодиод включают на часть кадра и выключают; глаз усредняет мерцание, и видна яркость.', 12, fill=MUTED))
    for i, duty in enumerate((0.1, 0.5, 0.9)):
        y = 400 + i * 62
        body.append(text(24, y + 22, 'яркость %d%%' % round(duty * 100), 13, 'bold'))
        body.append(text(24, y + 40, 'светится %s мс из %s мс' % (ru(round(duty * frame_ms, 2)), ru(frame_ms)), 11, fill=MUTED))
        fx, fw = 250, 160
        for k in range(4):
            x = fx + k * fw
            body.append(rect(x, y, fw, 40, LANE, rx=2, extra=' stroke="%s" stroke-width="1"' % RULE))
            body.append(rect(x, y, fw * duty, 40, RED, rx=2, extra=' fill-opacity="0.85"'))
        body.append(text(fx + 4 * fw + 12, y + 25, 'кадры по %s мс' % ru(frame_ms), 11, fill=MUTED))
    body.append(text(24, 604, 'Светодиод записывается только при смене состояния: PWR включается через почтовый ящик прошивки, и каждая запись — вызов.', 12, fill=MUTED))
    return svg(1000, 628, 'Как рисуется яркость',
               'Яркость режима done как sin в четвёртой степени на 45% цикла и программный ШИМ с кадром 5 мс.', body)


FIGURES = {
    'case-end.svg': figure_case,
    'modes.svg': figure_modes,
    'modes-fallback.svg': figure_fallback,
    'lifecycle.svg': figure_lifecycle,
    'pwm.svg': figure_pwm,
}


def build():
    return {name: make() for name, make in FIGURES.items()}


if __name__ == '__main__':
    out = Path(sys.argv[1]) if len(sys.argv) > 1 else HERE   # another directory: for LedDocsTest
    for name, content in build().items():
        (out / name).write_text(content, encoding='utf-8', newline='\n')
        print('wrote', out / name, len(content), 'bytes')
