"""Render the bench LED observation card; timings come from the existing LED diagrams."""
import math
from pathlib import Path
import runpy

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[2]
TIMING = runpy.run_path(str(ROOT / 'docs/leds/build_diagrams.py'))
OUT = ROOT / 'docs/superpowers/plans/evidence/2026-10-20-led-check-order.png'
W, H = 1800, 1770
BG, PAPER, INK, MUTED, RED = '#FFF7E8', '#FFFDF9', '#2A1A0C', '#796653', '#D9342B'
im = Image.new('RGB', (W, H), BG)
d = ImageDraw.Draw(im)


def font(size, bold=False):
    candidates = [Path('C:/Windows/Fonts') / ('arialbd.ttf' if bold else 'arial.ttf'),
                  Path('/usr/share/fonts/truetype/dejavu') / ('DejaVuSans-Bold.ttf' if bold else 'DejaVuSans.ttf')]
    return ImageFont.truetype(str(next(p for p in candidates if p.exists())), size)


def text(x, y, value, size=28, color=INK, bold=False):
    d.text((x, y), value, font=font(size, bold), fill=color)


def bump(phase):
    phase %= 1
    return math.sin(math.pi * phase / TIMING['DONE_BUMP']) ** 4 if phase < TIMING['DONE_BUMP'] else 0


def duty(mode, t, led):
    if mode == 'ready': return bump(t / TIMING['DONE_CYCLE'])
    if mode == 'done': return bump(t / TIMING['DONE_CYCLE'] + (0.5 if led else 0))
    if mode == 'fail':
        if not led: return TIMING['FAIL_ACT_DUTY']
        return float(any(a <= t % TIMING['FAIL_CYCLE'] < b for a, b in TIMING['FAIL_FLASHES']))
    if mode == 'identify': return float((t % (2 * TIMING['IDENTIFY_HALF']) < TIMING['IDENTIFY_HALF']) != bool(led))
    if mode == 'progress':
        if led: return 1.0
        on, off = TIMING['PROGRESS_MS']
        return float(t * 1000 % (on + off) < on)
    raise ValueError(mode)


text(55, 30, 'cmprovision / WLAN Pi M4+', 27, MUTED, True)
text(55, 75, 'ПОРЯДОК ПРОВЕРКИ СВЕТОДИОДОВ', 51, bold=True)
text(55, 147, 'Сенсор 10000000e611110c • порт Gi0/2 • команды запускает Codex', 27, MUTED)
d.rounded_rectangle((55, 200, 1745, 300), radius=18, fill='#F5E5CE')
text(80, 217, 'ACT — возле Ethernet       PWR — возле microSD       Оба могут быть красными', 29, bold=True)
text(80, 262, 'Смотрим ACT/PWR. Огни RJ45 и постоянный индикатор 5V не оцениваем.', 25, MUTED)

rows = [
    ('READY / ОС ПРОВЕРЕНА', '15–20 с наблюдения', ['Плавно загораются и гаснут ВМЕСТЕ.', 'Цикл около 3 с.'], 'ready', 3, 'Яркость обоих меняется одновременно'),
    ('IDENTIFY', '15 с быстрого чередования', ['Резко мигают ПО ОЧЕРЕДИ.', 'Смена каждые 0,15 с.'], 'identify', 3, 'Один включён — другой выключен'),
    ('STOP → READY', '15–20 с после Stop', ['Снова плавно пульсируют ВМЕСТЕ.', 'Возврат обычно за 2–5 с.'], 'ready', 3, 'Ожидаемый рисунок после Stop'),
    ('АВТОВОЗВРАТ → READY', 'Identify один раз, ждать 35 с', ['30 с чередования → снова ВМЕСТЕ.', 'Stop не нажимаем.'], 'ready', 3, 'Ожидаемый рисунок после истечения 30 с'),
    ('ДЕМОНСТРАЦИЯ DONE', '20 с, сенсор простаивает', ['Плавно пульсируют ПО ОЧЕРЕДИ.', 'Цикл около 3 с. Это не ready.'], 'done', 3, 'Пики яркости сдвинуты на полцикла'),
    ('ДЕМОНСТРАЦИЯ FAIL', '20 с, без ошибки прошивки', ['ACT тусклый. PWR: две вспышки,', 'затем пауза. Цикл около 2 с.'], 'fail', 4, 'Тусклый ACT + двойные вспышки PWR'),
    ('ЗАПИСЬ И СВЕРКА / PROGRESS', 'Настоящий сетевой прогон', ['PWR горит ПОСТОЯННО.', 'ACT быстро мигает: 10 раз/с.'], 'progress', 0.5, 'При Writing image и Verifying рисунок одинаковый'),
]
for i, (title, duration, lines, mode, window, caption) in enumerate(rows):
    y = 330 + i * 186
    d.rounded_rectangle((55, y, 1745, y + 171), radius=18, fill=PAPER, outline='#E4D4BD', width=2)
    d.ellipse((75, y + 19, 133, y + 77), fill=INK)
    text(94, y + 26, str(i + 1), 33, '#FFFFFF', True)
    text(150, y + 17, title, 28, bold=True)
    text(150, y + 56, duration, 24, '#A35C22', True)
    for j, line in enumerate(lines): text(150, y + 95 + 30 * j, line, 25)
    x0, x1 = 900, 1708
    for led in range(2):
        bottom = y + 66 + led * 52
        text(807, bottom - 22, ['ACT', 'PWR'][led], 25, bold=True)
        d.rounded_rectangle((x0, bottom - 34, x1, bottom), radius=5, fill='#EFE8DF')
        for x in range(x0, x1):
            v = duty(mode, (x - x0 + 0.5) / (x1 - x0) * window, led)
            if v:
                d.line((x, bottom - max(2, round(33 * v)), x, bottom), fill=RED, width=1)
        text(x0 if not led else x1 - 63, bottom + (57 if not led else 5), '0 с' if not led else f'{window:g} с', 19, MUTED)
    text(900, y + 148, caption, 19, MUTED)

text(65, 1655, 'Слева — что наблюдать. Справа — изменение яркости во времени →', 27, bold=True)
text(65, 1695, 'Шаг 7: загрузка и EEPROM — переходный этап; после OS check снова рисунок 1.', 25, MUTED)
text(65, 1734, 'Done и fail — только демонстрации LED. Статус прошивки при них остаётся Done.', 22, MUTED)
OUT.parent.mkdir(parents=True, exist_ok=True)
im.save(OUT, optimize=True)
print(OUT)
