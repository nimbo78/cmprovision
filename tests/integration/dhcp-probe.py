#!/usr/bin/env python3
"""Send DHCPDISCOVER on one laboratory veth and report matching offers, without configuring it."""
import argparse
import os
import socket
import struct
import time


def checksum(data):
    if len(data) % 2:
        data += b'\0'
    total = sum(struct.unpack('!%dH' % (len(data) // 2), data))
    total = (total & 0xffff) + (total >> 16)
    total = (total & 0xffff) + (total >> 16)
    return (~total) & 0xffff


parser = argparse.ArgumentParser()
parser.add_argument('interface')
parser.add_argument('expected', choices=['offer', 'none'])
args = parser.parse_args()
mac = bytes.fromhex(open('/sys/class/net/' + args.interface + '/address', encoding='ascii').read().strip().replace(':', ''))
xid = os.urandom(4)
bootp = (b'\x01\x01\x06\x00' + xid + b'\x00\x00\x80\x00' + bytes(16) + mac + bytes(10 + 64 + 128))
dhcp = bootp + b'\x63\x82\x53\x63\x35\x01\x01\x37\x03\x01\x03\x06\xff'
udp = struct.pack('!HHHH', 68, 67, len(dhcp) + 8, 0) + dhcp
header = struct.pack('!BBHHHBBH4s4s', 0x45, 0, len(udp) + 20, 1, 0, 64, 17, 0, bytes(4), b'\xff' * 4)
header = header[:10] + struct.pack('!H', checksum(header)) + header[12:]
frame = b'\xff' * 6 + mac + b'\x08\x00' + header + udp
sock = socket.socket(socket.AF_PACKET, socket.SOCK_RAW, socket.htons(3))
sock.bind((args.interface, 0))
sock.settimeout(0.2)
sock.send(frame)
deadline = time.monotonic() + 2
found = False
while time.monotonic() < deadline:
    try:
        packet = sock.recv(4096)
    except socket.timeout:
        continue
    if len(packet) < 14 + 20 + 8 + 240 or packet[12:14] != b'\x08\x00':
        continue
    offset = 14 + (packet[14] & 15) * 4
    if packet[23] == 17 and packet[offset:offset + 4] == struct.pack('!HH', 67, 68):
        body = packet[offset + 8:]
        if body[0] == 2 and body[4:8] == xid and b'\x35\x01\x02' in body[240:]:
            found = True
            break
print('%s: %s' % (args.interface, 'offer' if found else 'none'))
raise SystemExit(0 if found == (args.expected == 'offer') else 1)
