#!/usr/bin/python3
"""Read-only CDC ACM diagnostic receiver. Runs as www-data with this service's dialout group.
Only devices advertising our dedicated product are opened; no shell or disk commands are sent.
"""
import argparse
import base64
import hashlib
import json
import logging
import os
from pathlib import Path
import re
import secrets
import select
import stat
import subprocess
import termios
import time
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass

MAX_REPORT = 32768
MAX_FRAME = 49152
UUID = re.compile(r"[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\Z")
SERIAL = re.compile(r"[0-9a-f]{16}\Z")
TOKEN = re.compile(r"(?:-|[A-Za-z0-9]{40})\Z")
CONTEXTS = {"idle", "discovery", "startup", "failure", "complete", "manual"}


@dataclass(frozen=True)
class Device:
    path: str
    serial: str
    usb_path: str


def discover(sys_root=Path('/sys'), dev_root=Path('/dev')):
    devices = []
    for tty in sorted((sys_root / 'class/tty').glob('ttyACM[0-9]*')):
        if not re.fullmatch(r'ttyACM[0-9]+', tty.name):
            continue
        try:
            current = (tty / 'device').resolve(strict=True)
            for parent in (current, *current.parents):
                if not (parent / 'idVendor').is_file():
                    continue
                value = lambda name: (parent / name).read_text().strip()
                if (value('idVendor'), value('idProduct'), value('manufacturer'), value('product')) != (
                        '1d6b', '0104', 'cmprovision', 'cmprovision diagnostics'):
                    break
                serial = value('serial').lower()
                if SERIAL.fullmatch(serial) and serial != '0' * 16:
                    devices.append(Device(str(dev_root / tty.name), serial, parent.name))
                break
        except (OSError, UnicodeError):
            continue
    # Ambiguous duplicate descriptors cannot identify two physical targets reliably.
    return [d for d in devices if sum(other.serial == d.serial for other in devices) == 1][:64]


class Channel:
    def __init__(self, path):
        self.path = path
        self.fd = None
        self.buffer = bytearray()

    def __enter__(self):
        self.fd = os.open(self.path, os.O_RDWR | os.O_NOCTTY | os.O_NONBLOCK | os.O_CLOEXEC | os.O_NOFOLLOW)
        try:
            if not stat.S_ISCHR(os.fstat(self.fd).st_mode):
                raise ValueError('Not a character device')
            attrs = termios.tcgetattr(self.fd)
            attrs[0] = attrs[1] = attrs[3] = 0
            attrs[2] = termios.CS8 | termios.CLOCAL | termios.CREAD
            attrs[4] = attrs[5] = termios.B115200
            attrs[6][termios.VMIN] = attrs[6][termios.VTIME] = 0
            termios.tcsetattr(self.fd, termios.TCSANOW, attrs)
            termios.tcflush(self.fd, termios.TCIFLUSH)
            return self
        except BaseException:
            os.close(self.fd)
            self.fd = None
            raise

    def __exit__(self, *args):
        if self.fd is not None:
            os.close(self.fd)

    def send(self, line):
        data = (line + '\n').encode('ascii')
        until = time.monotonic() + 3
        while data:
            left = until - time.monotonic()
            if left <= 0 or not select.select([], [self.fd], [], left)[1]:
                raise TimeoutError('USB write timeout')
            try:
                count = os.write(self.fd, data)
                data = data[count:]
            except BlockingIOError:
                continue

    def line(self, until):
        while b'\n' not in self.buffer:
            left = until - time.monotonic()
            if left <= 0 or not select.select([self.fd], [], [], left)[0]:
                raise TimeoutError('USB response timeout')
            data = os.read(self.fd, 1024)
            if not data:
                raise EOFError('USB disconnected')
            self.buffer.extend(data)
            if len(self.buffer) > MAX_FRAME:
                raise ValueError('USB frame too large')
        line, _, rest = self.buffer.partition(b'\n')
        self.buffer = bytearray(rest)
        if len(line) > 1024:
            raise ValueError('USB line too long')
        return line.rstrip(b'\r').decode('ascii')


def hello(channel, device):
    nonce = secrets.token_hex(32)
    channel.send('CMPDIAG1 HELLO ' + nonce)
    fields = channel.line(time.monotonic() + 5).split()
    if len(fields) != 10 or fields[:3] != ['CMPDIAG1', 'STATUS', nonce]:
        raise ValueError('Invalid USB status')
    _, _, _, serial, boot, event, context, age, collected, token = fields
    if (serial != device.serial or not UUID.fullmatch(boot) or context not in CONTEXTS
            or (context != 'idle' and not UUID.fullmatch(event)) or not TOKEN.fullmatch(token)
            or not age.isdecimal() or not collected.isdecimal() or max(int(age), int(collected)) > 10000000000):
        raise ValueError('Invalid USB identity')
    return dict(serial=serial, descriptor_serial=device.serial, boot_id=boot, event_id=event,
                context=context, age=int(age), collected=int(collected), token=token)


def receive(channel, status, decision):
    nonce = secrets.token_hex(32)
    event, context = decision['event_id'], decision['context']
    if not UUID.fullmatch(event) or context not in CONTEXTS - {'idle'}:
        raise ValueError('Invalid collector decision')
    channel.send(f'CMPDIAG1 REPORT {nonce} {event} {context}')
    until = time.monotonic() + 10
    fields = channel.line(until).split()
    if (len(fields) != 8 or fields[:5] != ['CMPDIAG1', 'BEGIN', nonce, status['serial'], status['boot_id']]
            or not fields[5].isdecimal() or not 0 < int(fields[5]) <= MAX_REPORT
            or not re.fullmatch('[0-9a-f]{64}', fields[6]) or fields[7] != status['token']):
        raise ValueError('Invalid USB report header')
    encoded = ''
    while True:
        line = channel.line(until)
        if line == 'CMPDIAG1 END ' + nonce:
            break
        if not re.fullmatch('[A-Za-z0-9+/=]*', line):
            raise ValueError('Invalid USB payload')
        encoded += line
        if len(encoded) > ((MAX_REPORT + 2) // 3) * 4:
            raise ValueError('USB report too large')
    payload = base64.b64decode(encoded, validate=True)
    if len(payload) != int(fields[5]) or hashlib.sha256(payload).hexdigest() != fields[6]:
        raise ValueError('USB report checksum mismatch')
    return encoded, fields[7]


def application(app, payload):
    result = subprocess.run(['/usr/bin/php', str(Path(app) / 'artisan'), 'diagnostics:usb'],
                            input=json.dumps(payload).encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            timeout=15, check=True)
    reply = json.loads(result.stdout)
    if not isinstance(reply, dict) or reply.get('action') not in {'collect', 'wait', 'skip', 'stored', 'ignored'}:
        raise ValueError('Invalid application reply')
    return reply


def inspect(device, app, call=application):
    try:
        with Channel(device.path) as channel:
            status = hello(channel, device)
            # HELLO is metadata only. The full snapshot is requested only after this application decision.
            decision = call(app, dict(operation='status', status=status))
            if decision['action'] != 'collect':
                return decision['action']
            report, token = receive(channel, status, decision)
            reply = call(app, dict(operation='accept', status=status, report=report, token=token, usb_path=device.usb_path))
            if reply['action'] == 'stored':
                logging.info('USB fallback report received for %s', device.serial)
            return reply['action']
    except (OSError, ValueError, EOFError, TimeoutError, subprocess.SubprocessError) as error:
        # Device bytes and run tokens never enter logs or shell command lines.
        logging.warning('USB diagnostics %s: %s', device.serial, type(error).__name__)
        return 'error'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--app', default='/var/lib/cmprovision')
    parser.add_argument('--once', action='store_true')
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO, format='%(message)s')
    with ThreadPoolExecutor(max_workers=2) as pool:
        while True:
            devices = discover()
            list(pool.map(lambda d: inspect(d, args.app), devices))
            if args.once:
                return
            time.sleep(10)


if __name__ == '__main__':
    main()
