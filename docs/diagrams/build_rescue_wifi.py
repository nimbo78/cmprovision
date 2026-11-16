#!/usr/bin/env python3
"""Build GitHub-safe Rescue Wi-Fi figures from the real radio policy.

Run: python docs/diagrams/build_rescue_wifi.py [--check]
No third-party dependencies. No hardware, subprocesses or network access.
"""
import argparse
import html
import importlib.util
import json
from pathlib import Path
import re
import sys

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
BG, INK, MUTED = '#FFF7E8', '#2A1A0C', '#725B42'
PANEL, LINE = '#FFFDF8', '#D8C7A8'
ACCENT, ORANGE, SOFT = '#E2681C', '#8A3C0C', '#FFE4CC'
GREEN, GREEN_BG, GREY, GREY_BG = '#177C32', '#E3F5E8', '#746A5F', '#F0EBE3'
BLUE, BLUE_BG = '#1F4FA8', '#E8EFFC'
FONT = "'Segoe UI', 'Helvetica Neue', Arial, 'DejaVu Sans', sans-serif"
MONO = "'Cascadia Mono', Consolas, 'DejaVu Sans Mono', monospace"


def policy_facts():
    spec = importlib.util.spec_from_file_location('rescue_docs_policy', ROOT/'tools/rescue_wifi_policy.py')
    policy = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(policy)
    cfg = dict(policy.DEFAULTS, enabled=True)
    base = dict(known=True, ready=True, management=False, ap_active=False, clients=0,
                spare_enabled=True, spare_connected=False, other_management=False)
    state, first_spare, started = {}, None, None
    for now in range(3601):
        state = policy.decide(cfg, base, state, now, 'docs')
        if state['action'] == 'connect_spare' and first_spare is None:
            first_spare = now
        if state['action'] == 'start':
            started = now
            break
    assert started == cfg['delay'], 'Automatic startup no longer matches the configured search timeout'
    early = policy.decide(cfg, dict(base, management=True), state, started - 1, 'docs')
    assert early['state'] == 'standby' and early['action'] != 'start'

    obs = dict(base, ap_active=True, management=True, other_management=True)
    state, stable, stopped = {}, None, None
    for now in range(3601):
        state = policy.decide(cfg, obs, state, now, 'docs')
        if state['reason'] == 'waiting_for_clients_to_leave' and stable is None:
            stable = now
        if state['action'] == 'stop':
            stopped = now
            break
    assert stable is not None and stopped is not None and 0 < stable < stopped
    quiet = stopped - stable

    # Clients defer shutdown; the quiet period starts after the last client leaves.
    held, release_at, clients_stop = {}, stopped + 10, None
    for now in range(release_at + quiet + 1):
        held = policy.decide(cfg, dict(obs, clients=2 if now < release_at else 0), held, now, 'docs')
        if now < release_at:
            assert held['action'] != 'stop', 'Connected clients no longer hold the AP'
        if held['action'] == 'stop':
            clients_stop = now
            break
    assert clients_stop == release_at + quiet

    unknown = {}
    for now in range(stopped + 100):
        unknown = policy.decide(cfg, dict(obs, clients=None), unknown, now, 'docs')
        assert unknown['action'] != 'stop', 'Unknown client count no longer preserves access'
    assert unknown['reason'] == 'clients_unknown'

    # Loss during recovery keeps the AP on and clears both recovery counters.
    before_loss = {}
    for now in range(stable + quiet // 2):
        before_loss = policy.decide(cfg, obs, before_loss, now, 'docs')
    lost = policy.decide(cfg, dict(obs, management=False), before_loss, stopped + 1, 'docs')
    assert lost['state'] == 'active' and lost['action'] != 'stop'
    assert 'healthy_since' not in lost and 'idle_since' not in lost

    control = (ROOT/'tools/rescue_wifi.py').read_text(encoding='utf-8')
    hdmi = (ROOT/'tools/hdmi-wallboard.py').read_text(encoding='utf-8')
    hold = re.search(r"state\['hold_until'\] = self\.monotonic\(\) \+ (\d+)", control)
    qr = re.search(r"expires_at=status\['started_at'\]\+(\d+)", hdmi)
    assert hold and qr, 'Manual/QR timer source changed; review the documentation generator'
    facts = dict(search=started, spare=first_spare, stable=stable, quiet=quiet,
                 recovery=stopped, manual=int(hold[1]), qr=int(qr[1]))
    assert facts['search'] % 60 == 0 and facts['manual'] % 60 == 0
    assert policy.decide(cfg, dict(obs), {'boot_id':'docs', 'hold_until':facts['manual']},
                         0, 'docs')['reason'] == 'manual_hold'
    return facts


class SVG:
    def __init__(self, height, title, desc, facts):
        self.height = height
        self.parts = [
            f'<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="{height}" viewBox="0 0 1200 {height}" role="img" aria-labelledby="title desc">',
            f'<title id="title">{html.escape(title)}</title>',
            f'<desc id="desc">{html.escape(desc)}</desc>',
            '<metadata>'+html.escape(json.dumps(facts, sort_keys=True))+'</metadata>',
            '<defs><marker id="arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0 0 L8 4 L0 8Z" fill="'+MUTED+'"/></marker></defs>',
            f'<rect width="1200" height="{height}" rx="20" fill="{BG}"/>']
    def add(self, value): self.parts.append(value)
    def rect(self, x, y, w, h, fill, stroke=None, radius=10):
        extra = f' stroke="{stroke}" stroke-width="1.5"' if stroke else ''
        self.add(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{radius}" fill="{fill}"{extra}/>')
    def line(self, x1, y1, x2, y2, color=LINE, width=2, arrow=False, dash=False):
        extra = ' marker-end="url(#arrow)"' if arrow else ''
        if dash: extra += ' stroke-dasharray="7 7"'
        self.add(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="{color}" stroke-width="{width}"{extra}/>')
    def text(self, x, y, value, size=22, color=INK, weight=400, anchor='start', max_width=None, mono=False):
        limit = f' data-max-width="{max_width}"' if max_width else ''
        family = MONO if mono else FONT
        self.add(f'<text x="{x}" y="{y}" font-family="{family}" font-size="{size}" font-weight="{weight}" fill="{color}" text-anchor="{anchor}"{limit}>{html.escape(str(value))}</text>')
    def lines(self, x, y, values, size=22, gap=28, **kw):
        for i, value in enumerate(values): self.text(x, y+i*gap, value, size=size, **kw)
    def dot(self, x, y, fill):
        self.add(f'<circle cx="{x}" cy="{y}" r="5" fill="{fill}"/>')
    def header(self, title, subtitle):
        self.text(48, 65, title, size=40, weight=700, max_width=1104)
        self.rect(48, 82, 56, 4, ACCENT, radius=2)
        self.text(48, 119, subtitle, size=22, color=MUTED, max_width=1104)
    def finish(self):
        return '\n'.join(self.parts+['</svg>', ''])


def lifecycle(lang, f):
    ru = lang == 'ru'
    title = 'Wi-Fi и резервная точка доступа' if ru else 'Wi-Fi and the rescue access point'
    desc = ('Автоматический цикл: сеть доступна, поиск, работа AP, восстановление, ожидание. Управление и AP показаны раздельно.'
            if ru else 'Automatic cycle: connected, searching, AP active, recovery, standby. Management Wi-Fi and AP are shown separately.')
    s = SVG(710, title, desc, f)
    s.header(title, 'Одна карта ищет сеть управления, другая раздаёт Rescue AP.' if ru else
             'One radio searches management networks; a separate radio hosts Rescue AP.')
    s.text(48, 151, 'Автоматический режим включён · типичный сценарий' if ru else
           'Automatic rescue enabled · a typical outage and recovery', size=20, color=MUTED)
    titles = ([['Сеть', 'доступна'], ['Поиск сетей', 'управления'], ['Rescue AP', 'работает'],
               ['Управление', 'вернулось'], ['Обычная', 'работа Wi-Fi']] if ru else
              [['Management', 'available'], ['Search saved', 'networks'], ['Rescue AP', 'is available'],
               ['Management', 'restored'], ['Back to', 'normal Wi-Fi']])
    values = ['Выкл', f"{f['search']//60} мин", 'Вкл', f"{f['stable']} + {f['quiet']} с", 'Выкл'] if ru else [
              'Off', f"{f['search']//60} min", 'On', f"{f['stable']} + {f['quiet']} s", 'Off']
    captions = ([['AP не раздаётся'], ['Тайм-аут', 'по умолчанию'], ['Пока нет', 'управления'],
                 ['Плюс ожидание', 'ухода клиентов'], ['AP не раздаётся']] if ru else
                [['AP stays off'], ['Default search', 'timeout'], ['Management', 'unavailable'],
                 ['Also wait for', 'clients to leave'], ['AP stays off']])
    states = ['STANDBY', 'WAITING', 'ACTIVE', 'RECOVERY', 'STANDBY']
    for i in range(5):
        x = 48+i*224
        if i < 4: s.line(x+40, 185, x+218, 185, width=1.8, arrow=True)
        s.rect(x, 170, 36, 30, SOFT, radius=8)
        s.text(x+18, 192, f'{i+1:02d}', size=20, color=ORANGE, weight=700, anchor='middle')
        s.rect(x, 218, 208, 304, PANEL, LINE)
        active = i in (2, 3)
        s.rect(x+16, 236, 176, 34, SOFT if active else GREY_BG, radius=8)
        s.text(x+104, 259, 'AP '+states[i], size=20, color=ORANGE if active else GREY,
               weight=700, anchor='middle', max_width=168, mono=True)
        s.lines(x+24, 305, titles[i], size=24, weight=600, max_width=160)
        s.text(x+24, 385, values[i], size=36 if i==3 else 42,
               color=ACCENT if active or i==1 else GREY, weight=700, max_width=164)
        s.lines(x+24, 414, captions[i], size=20, gap=24, color=MUTED, max_width=164)
        wifi = i in (0, 3, 4)
        s.dot(x+24, 474, GREEN if wifi else GREY)
        s.text(x+38, 481, ('Wi-Fi: есть' if wifi else 'Wi-Fi: поиск') if ru else
               ('Wi-Fi: up' if wifi else 'Wi-Fi: search'), size=20, color=GREEN if wifi else GREY, max_width=152)
        s.dot(x+24, 500, ACCENT if active else GREY)
        s.text(x+38, 507, ('AP: включён' if active else 'AP: выключен') if ru else
               ('AP: on' if active else 'AP: off'), size=20, color=ORANGE if active else GREY, max_width=152)
    s.rect(48, 548, 1104, 66, SOFT, radius=12)
    s.text(72, 589, 'Wi-Fi + AP RECOVERY', size=24, color=ORANGE, weight=700, max_width=340, mono=True)
    s.text(446, 589, 'Оба подключения могут работать одновременно.' if ru else
           'Both connections can work at the same time.', size=24, weight=600, max_width=680)
    s.text(48, 655, 'Сеть найдена до тайм-аута → AP не запускается.' if ru else
           'Management returns before the timeout → no AP is started.', size=22, color=MUTED, max_width=1104)
    s.text(48, 687, 'Связь пропала во время Recovery → AP остаётся доступным.' if ru else
           'Management drops during recovery → the AP remains available.', size=22, color=MUTED, max_width=1104)
    return s.finish()


def recovery(lang, f):
    ru = lang == 'ru'
    title = 'Когда выключится Rescue AP' if ru else 'When does Rescue AP switch off?'
    desc = ('Без клиентов: 60 секунд стабильного управления, затем 60 секунд без клиентов. Подключённые клиенты продлевают работу AP. QR закрывается отдельно.'
            if ru else 'With no clients: 60 seconds of stable management, then 60 seconds without clients. Connected clients keep the AP available. QR expiry is separate.')
    s = SVG(900, title, desc, f)
    s.header(title, 'Сначала стабильность управления, затем тишина на AP.' if ru else
             'First, stable management. Then, a client-free interval.')
    s.text(48, 174, '01  Клиентов AP нет' if ru else '01  No AP clients connected', size=28, weight=700, max_width=800)
    s.text(1152, 174, f"≈ {f['recovery']//60} мин + опрос" if ru else
           f"≈ {f['recovery']//60} min + polling", size=24, color=GREEN, weight=600, anchor='end', max_width=360)
    for x, seconds, caption in [(220, f['stable'], 'Стабильная связь' if ru else 'Stable management'),
                                (580, f['quiet'], 'Без клиентов' if ru else 'No clients')]:
        s.rect(x, 200, 360, 96, PANEL, LINE)
        s.text(x+180, 246, f'{seconds} '+('с' if ru else 's'), size=44, color=ACCENT, weight=700, anchor='middle')
        s.text(x+180, 278, caption, size=22, color=MUTED, anchor='middle', max_width=330)
    s.text(48, 335, 'Управление' if ru else 'Management', size=22, weight=600, max_width=160)
    s.text(48, 384, 'Rescue AP', size=22, weight=600, max_width=160)
    s.rect(220, 313, 932, 32, GREEN_BG, radius=6)
    s.text(686, 337, 'Сеть уже доступна' if ru else 'Management is already available', size=21, color=GREEN, anchor='middle', max_width=880)
    s.rect(220, 362, 720, 34, SOFT, radius=6)
    s.text(580, 386, 'AP RECOVERY', size=21, color=ORANGE, weight=700, anchor='middle', mono=True)
    s.rect(940, 362, 212, 34, GREY_BG, radius=6)
    s.text(1046, 386, 'AP STANDBY', size=20, color=GREY, weight=700, anchor='middle', mono=True)
    for x, label in [(220, '0'), (580, str(f['stable'])), (940, str(f['recovery']))]:
        s.line(x, 401, x, 409, MUTED, 1.5)
        s.text(x, 434, label+' '+('с' if ru else 's'), size=20, color=MUTED, anchor='middle')
    s.text(48, 494, '02  Инженер ещё подключён' if ru else '02  An engineer is still connected', size=28, weight=700, max_width=1104)
    phases = [(220,230, f"{f['stable']} "+('с' if ru else 's'), ['Стабильность'] if ru else ['Stable management'], PANEL, INK),
              (450,460,'…', ['Ждём ухода последнего клиента'] if ru else ['Wait for the last client to leave'], SOFT, ORANGE),
              (910,242,f"{f['quiet']} "+('с' if ru else 's'), ['Без клиентов'] if ru else ['No clients'], PANEL, INK)]
    for x,w,value,caption,fill,color in phases:
        s.rect(x, 522, w, 104, fill, LINE)
        s.text(x+w/2, 570, value, size=42, color=color, weight=700, anchor='middle', max_width=w-24)
        s.lines(x+w/2, 608, caption, size=20, color=MUTED, anchor='middle', max_width=w-24)
    s.text(48, 672, 'Rescue AP', size=22, weight=600, max_width=160)
    s.rect(220, 648, 932, 36, SOFT, radius=6)
    s.text(686, 674, 'AP продолжает работать' if ru else 'AP remains available', size=22, color=ORANGE, anchor='middle', max_width=880)
    s.text(1152, 714, 'Затем AP STANDBY' if ru else 'Then AP STANDBY', size=22, color=GREY, anchor='end', max_width=460)
    s.text(48, 762, 'Число клиентов неизвестно → AP сохраняется.' if ru else
           'Client count unknown → keep the AP available.', size=22, color=MUTED, max_width=1104)
    s.rect(48, 792, 1104, 74, BLUE_BG, radius=12)
    s.text(72, 837, 'QR · '+str(f['qr'])+(' с' if ru else ' s'), size=28, color=BLUE, weight=700, max_width=210)
    s.lines(320, 821, ['Закрывается только карточка подключения.', 'Состояние AP определяется правилами выше.'] if ru else
            ['Only the connection card closes.', 'The AP follows the rules above.'], size=22, gap=28, color=BLUE, max_width=800)
    return s.finish()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Verify committed figures against current policy')
    args = parser.parse_args()
    facts = policy_facts()
    figures = {}
    for lang in ('en','ru'):
        suffix = '.ru' if lang == 'ru' else ''
        figures['rescue-wifi-lifecycle'+suffix+'.svg'] = lifecycle(lang, facts)
        figures['rescue-wifi-recovery'+suffix+'.svg'] = recovery(lang, facts)
    failures = []
    for name, content in figures.items():
        path = HERE/name
        if args.check:
            if not path.exists() or path.read_text(encoding='utf-8') != content:
                failures.append(name)
        else:
            path.write_text(content, encoding='utf-8', newline='\n')
    if failures:
        sys.exit('Missing or stale figures: '+', '.join(failures)+'. Run '+str(Path(__file__).relative_to(ROOT)))
    print(('Checked' if args.check else 'Built')+' 4 SVGs; policy timings: '+json.dumps(facts, sort_keys=True))


if __name__ == '__main__':
    main()
