#!/usr/bin/env python3
"""Exercise generated IPv4 address, DNS and route arguments with real NM in a private namespace."""
import importlib.machinery
import ipaddress
import json
import os
import subprocess
import sys

profile = importlib.machinery.SourceFileLoader('network_profile', sys.argv[1]).load_module()
os.environ['PATH'] = '/run/lab-bin:/usr/sbin:/usr/bin:/sbin:/bin'
subprocess.check_call(['nmcli', 'connection', 'add', 'type', 'vlan', 'ifname', 'cmp-route124', 'dev', 'cmp-test0',
                       'id', '124', 'con-name', 'cmp-routes', 'connection.autoconnect', 'no',
                       'ipv4.method', 'disabled', 'ipv6.method', 'disabled'], stdout=subprocess.DEVNULL)
try:
    args = profile.properties({'ipv4': {'method': 'manual', 'addresses': ['198.19.1.10/24'],
        'gateway': '198.19.1.1', 'dns': ['9.9.9.9', '1.1.1.1'], 'route_metric': 150,
        'routes': [{'destination': '203.0.113.0/24', 'via': '198.19.1.1', 'metric': 25},
                   {'destination': '203.0.114.0/24', 'metric': 35}]},
        'ipv6': {'method': 'manual', 'addresses': ['fd42:12::10/64'], 'dns': ['fd42:12::53'],
        'never_default': True, 'routes': [{'destination': 'fd42:13::/64', 'via': 'fd42:12::1', 'metric': 29}]}})
    subprocess.check_call(['nmcli', 'connection', 'modify', '--temporary', 'cmp-routes'] + args)
    subprocess.check_call(['nmcli', '--wait', '10', 'connection', 'up', 'cmp-routes'], stdout=subprocess.DEVNULL)
    full_profile = subprocess.check_output(['nmcli', '--escape', 'no', '-t', '-f', 'profile',
                                            'connection', 'show', 'cmp-routes'], text=True)
    assert 'ipv4.method:' in full_profile and 'GENERAL.STATE:' not in full_profile
    routes = json.loads(subprocess.check_output(['ip', '-j', '-4', 'route', 'show'], text=True))
    assert any(r.get('dst') == '203.0.113.0/24' and r.get('gateway') == '198.19.1.1' and r.get('metric') == 25 for r in routes)
    assert any(r.get('dst') == '203.0.114.0/24' and r.get('dev') == 'cmp-route124' and r.get('metric') == 35 for r in routes)
    assert any(r.get('dst') == 'default' and r.get('gateway') == '198.19.1.1' and r.get('metric') == 150 for r in routes)
    routes6 = json.loads(subprocess.check_output(['ip', '-j', '-6', 'route', 'show'], text=True))
    assert any(r.get('dst') == 'fd42:13::/64' and r.get('gateway') == 'fd42:12::1' and r.get('metric') == 29 for r in routes6)
    dns4 = subprocess.check_output(['nmcli', '--escape', 'no', '-g', 'ipv4.dns', 'connection', 'show', 'cmp-routes'], text=True).strip()
    assert [s.strip() for s in dns4.split(',')] == ['9.9.9.9', '1.1.1.1']
    route_values = subprocess.check_output(['nmcli', '--escape', 'no', '-g', 'ipv4.routes',
                                          'connection', 'show', 'cmp-routes'], text=True).strip()
    assert '203.0.114.0/24 35' in route_values
    dns6 = subprocess.check_output(['nmcli', '--escape', 'no', '-g', 'ipv6.dns', 'connection', 'show', 'cmp-routes'], text=True).strip()
    assert ipaddress.ip_address(dns6) == ipaddress.ip_address('fd42:12::53')
    # A recovery helper must be able to feed NM's saved property representation back unchanged.
    for prop in ['ipv4.method', 'ipv4.addresses', 'ipv4.gateway', 'ipv4.dns', 'ipv4.routes', 'ipv4.route-metric',
                 'ipv4.never-default', 'ipv4.ignore-auto-dns', 'ipv6.method', 'ipv6.addresses', 'ipv6.gateway',
                 'ipv6.dns', 'ipv6.routes', 'ipv6.route-metric', 'ipv6.never-default', 'ipv6.ignore-auto-dns']:
        saved = subprocess.check_output(['nmcli', '--escape', 'no', '-g', prop, 'connection', 'show', 'cmp-routes'], text=True).strip()
        subprocess.check_call(['nmcli', 'connection', 'modify', '--temporary', 'cmp-routes', prop, saved])
    addresses = json.loads(subprocess.check_output(['ip', '-j', '-4', 'address', 'show', 'dev', 'cmp-test0'], text=True))
    assert any(a.get('local') == '172.20.0.1' for a in addresses[0]['addr_info'])
finally:
    subprocess.call(['nmcli', 'connection', 'delete', 'cmp-routes'], stdout=subprocess.DEVNULL)
remaining = json.loads(subprocess.check_output(['ip', '-j', '-4', 'route', 'show'], text=True))
assert not any(r.get('gateway') == '198.19.1.1' for r in remaining)
print('PASS: validated IPv4/IPv6 address, gateway, DNS and routes accepted by NetworkManager')
