#!/usr/bin/env python3
"""Probe a TFTP server from a laboratory veth using raw Ethernet, including ARP replies."""
import argparse
import os
import socket
import struct
import time


def checksum(data):
    if len(data) % 2:
        data += b'\0'
    value = sum(struct.unpack('!%dH' % (len(data) // 2), data))
    value = (value & 0xffff) + (value >> 16)
    value = (value & 0xffff) + (value >> 16)
    return (~value) & 0xffff


parser = argparse.ArgumentParser()
parser.add_argument('interface')
parser.add_argument('expected', choices=['data', 'none'])
args = parser.parse_args()
mac = bytes.fromhex(open('/sys/class/net/' + args.interface + '/address', encoding='ascii').read().strip().replace(':', ''))
client_ip = socket.inet_aton('172.20.0.60')
server_ip = socket.inet_aton('172.20.0.1')
client_port = 20000 + int.from_bytes(os.urandom(2), 'big') % 40000
request = b'\x00\x01probe.txt\x00octet\x00'
udp = struct.pack('!HHHH', client_port, 69, len(request) + 8, 0) + request
ip = struct.pack('!BBHHHBBH4s4s', 0x45, 0, len(udp) + 20, 1, 0, 64, 17, 0, client_ip, server_ip)
ip = ip[:10] + struct.pack('!H', checksum(ip)) + ip[12:]
sock = socket.socket(socket.AF_PACKET, socket.SOCK_RAW, socket.htons(3))
sock.bind((args.interface, 0))
sock.settimeout(.2)
# The server accepts the broadcast Ethernet frame addressed to its own IPv4 address.
sock.send(b'\xff' * 6 + mac + b'\x08\x00' + ip + udp)
deadline = time.monotonic() + 2
found = False
while time.monotonic() < deadline:
    try:
        packet = sock.recv(4096)
    except socket.timeout:
        continue
    if len(packet) >= 42 and packet[12:14] == b'\x08\x06':
        arp = packet[14:42]
        if arp[6:8] == b'\x00\x01' and arp[24:28] == client_ip:
            reply = (b'\x00\x01\x08\x00\x06\x04\x00\x02' + mac + client_ip + arp[8:14] + arp[14:18])
            sock.send(arp[8:14] + mac + b'\x08\x06' + reply)
    if len(packet) >= 46 and packet[12:14] == b'\x08\x00' and packet[23] == 17:
        offset = 14 + (packet[14] & 15) * 4
        if packet[offset + 2:offset + 4] == struct.pack('!H', client_port) and packet[offset + 8:offset + 12] == b'\x00\x03\x00\x01':
            found = packet[offset + 12:] == b'cmprovision-vlan-probe\n'
            break
print('%s TFTP: %s' % (args.interface, 'data' if found else 'none'))
raise SystemExit(0 if found == (args.expected == 'data') else 1)
