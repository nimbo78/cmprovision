"""What the QEMU run of tools/utility-os/test.sh left in its directory: one ok/FAIL line per check; the tools' messages
go to the fixture tests/Unit/UtilityOsMessagesTest.php reads. Exit 1 when a check failed.
    verify.py <smoke dir> <expected OS version> <fixture> [expected Linux version]"""
import base64
import hashlib
import os
import re
import sys

SMOKE, VERSION, FIXTURE = sys.argv[1:4]
LINUX = sys.argv[4] if len(sys.argv) > 4 else None
MESSAGES = ['gz-cut', 'gz-garbage', 'xz-cut', 'xz-garbage', 'bz2-cut', 'bz2-garbage', 'dd-full', 'dd-records',
            'sha256-mismatch', 'flashrom-written', 'flashrom-identical', 'flashrom-missing']
PARAMETERS = ['serial', 'model', 'storagesize', 'mac', 'inversejumper', 'memorysize', 'temp', 'cid', 'csd', 'bootmode', 'uos']
fails = 0


def check(ok, text):
    global fails
    print(('ok   ' if ok else 'FAIL ') + text)
    if not ok:
        fails += 1


def read(name):
    path = os.path.join(SMOKE, name)
    return open(path, encoding='utf-8', errors='replace').read() if os.path.exists(path) else None


start = (read('start') or '').strip()
check(start != '', 'qemu: the init asked the server for its script')
values = dict(pair.split('=', 1) if '=' in pair else (pair, '') for pair in start.split('&')) if start else {}
check([p.split('=', 1)[0] for p in start.split('&')] == PARAMETERS, 'start: the parameters of the legacy OS, then uos')
check(values.get('uos') == VERSION, 'start: uos=' + VERSION)
check(values.get('model') == 'linux,dummy-virt', 'start: the model from the device tree')
check(values.get('mac') == '52:54:00:12:34:56', 'start: the MAC of eth0')
check(values.get('memorysize', '').isdigit() and int(values['memorysize']) > 500000, 'start: MemTotal in kB')
check(all(values.get(k) == '' for k in ['serial', 'storagesize', 'inversejumper', 'temp', 'cid', 'csd', 'bootmode']),
      'start: what QEMU lacks is empty (serial, eMMC, jumpers, temperature, boot mode)')
check('{' not in start, 'start: every placeholder filled in')
check(read('ran-502') is None, 'start: the page of the 502 never ran')
check((read('requests') or '').count('/scriptexecute?') >= 2, 'start: the init asked again after the 502')

result = read('result.txt')
check(result is not None, 'qemu: the check script sent its result')
sections, name = {}, None
for line in (result or '').splitlines():
    m = re.fullmatch(r'\[([a-z0-9-]+)\]', line)
    if m:
        name = m.group(1)
        sections[name] = []
    elif name:
        sections[name].append(line)
text = {k: '\n'.join(v).strip() for k, v in sections.items()}

check(text.get('uos') == VERSION, 'os: /etc/cmprovision-uos')
check('-cmprovision' in text.get('kernel', ''), 'os: the kernel is ours (-cmprovision)')
if LINUX is not None:
    check(bool(re.fullmatch(re.escape(LINUX) + r'-cmprovision(?:[+-].*)?', text.get('kernel', ''))),
          'os: Linux version matches the pinned ' + LINUX)
agent = sections.get('usb-agent', [])
agent_ok = False
try:
    status, header = agent[0].split(), agent[1].split()
    nonce = 'a' * 64
    assert len(status) == 10 and status[:4] == ['CMPDIAG1', 'STATUS', nonce, '1000000000000001']
    assert status[5] == status[4] and status[6] == 'discovery' and status[9] == '-'
    assert len(header) == 8 and header[:5] == ['CMPDIAG1', 'BEGIN', nonce, status[3], status[4]]
    assert agent[-1] == 'CMPDIAG1 END ' + nonce
    snapshot = base64.b64decode(''.join(agent[2:-1]), validate=True)
    assert len(snapshot) == int(header[5]) and hashlib.sha256(snapshot).hexdigest() == header[6]
    assert b'utility_os=' + VERSION.encode() + b'\n' in snapshot and b'context=discovery\n' in snapshot
    agent_ok = True
except (AssertionError, IndexError, ValueError):
    pass
check(agent_ok, 'USB diagnostics: shipped agent exchanges a valid snapshot with the built OS tools')

tools = sections.get('versions', [])
check(len(tools) == 21, 'tools: 21 tools answered')
for line in tools:
    m = re.match(r'(\S+) rc=(\d+) ?(.*)', line)
    good = m and int(m.group(2)) not in (126, 127, 132, 134, 139) and 'error while loading shared libraries' not in line
    check(bool(good), 'tools: ' + line[:100])
whole = (read('image.sha256') or '').strip()
for kind in ['gz', 'xz', 'bz2']:
    check(text.get(kind + '-whole') == whole, kind + ': a whole image decompresses right')
check(text.get('od') == '3', 'od: the 4th byte as a number')
check(text.get('base64') == 'hello', 'base64 -d')
check(text.get('zeros') == '0', 'tr -d NUL | wc -c')
check(text.get('awk') == '1099511627777', 'awk: 64-bit sums')
check(text.get('cmp') == 'rc=0', 'cmp -s')
check(text.get('dd-flags') == 'rc=0', 'dd iflag=fullblock conv=fsync,notrunc')
check(text.get('curl') == '404 rc=22', 'curl -w %{http_code} and -f')
check(text.get('ledpattern', '').startswith('rc=') and 'usage: ledpattern' in text.get('ledpattern', ''),
      'ledpattern (32-bit) runs')
check(bool(re.search(r'^\d+\+\d+ records in$', text.get('dd-records', ''), re.M)), 'dd: N+M records in')
noop = text.get('flashrom-identical', '')
written = text.get('flashrom-written', '')
missing = text.get('flashrom-missing', '')
check('Chip content is identical' in noop and bool(re.search(r'^rc=0$', noop, re.M)),
      'flashrom: a real no-op reports identical')
check('VERIFIED.' in written and 'identical' not in written and bool(re.search(r'^rc=0$', written, re.M)),
      'flashrom: a changed image is written and verified')
check('No EEPROM/flash device found' in missing and bool(re.search(r'^rc=1$', missing, re.M)),
      'flashrom: missing chip has the expected diagnosis')
for name in MESSAGES:
    check(text.get(name, '') != '', 'messages: ' + name)

if fails == 0:
    with open(FIXTURE, 'w', encoding='utf-8', newline='\n') as f:
        f.write('# The messages of the tools of the utility OS %s, recorded under QEMU by tools/utility-os/test.sh;\n' % VERSION)
        f.write('# tests/Unit/UtilityOsMessagesTest.php checks that the server recognises them. Do not edit: run test.sh.\n')
        for name in MESSAGES:
            f.write('[%s]\n%s\n' % (name, text[name]))
    print('ok   messages written to ' + FIXTURE)
sys.exit(1 if fails else 0)
