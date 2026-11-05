#!/usr/bin/env python3
"""Exercise the management-VLAN helper in hybrid-vlan.sh's private network namespace."""
import importlib.machinery
import io
import json
import os
import subprocess
import sys

helper = importlib.machinery.SourceFileLoader('vlan_networks', sys.argv[1]).load_module()
# The laboratory parent is a veth, not a physical Ethernet controller. Production does not override this check.
helper.physical = lambda name: name == 'cmp-test0'
os.makedirs('/run/lab-bin', exist_ok=True)
os.symlink(os.environ.get('CMP_LAB_NMCLI', '/usr/bin/nmcli'), '/run/lab-bin/nmcli')
os.environ['PATH'] = '/run/lab-bin:/usr/sbin:/usr/bin:/sbin:/bin'

record = helper.add({'parent': 'cmp-test0', 'vlan_id': 123, 'address': '198.18.12.10/24'})
# The legacy VLAN controls share the transaction gate; they cannot delete a profile
# while a Connect/Disconnect operation is waiting for confirmation.
store_module = importlib.machinery.SourceFileLoader('vlan_gate_store', os.path.join(os.path.dirname(sys.argv[1]), 'network-transaction-store.py')).load_module()
files = store_module.Store('/var/lib/cmprovision-network/transactions', '/run/cmprovision-network')
files.initialize()
files.set_pending()
argv, stdin = sys.argv, sys.stdin
try:
    sys.argv = ['vlan-networks', 'remove']
    sys.stdin = io.TextIOWrapper(io.BytesIO(json.dumps({'uuid': record['uuid']}).encode()))
    try:
        helper.main()
        raise AssertionError('the VLAN control ignored the pending network transaction')
    except helper.Refused as exc:
        assert 'pending' in str(exc)
finally:
    sys.argv, sys.stdin = argv, stdin
    files.clear_pending()
addresses = json.loads(subprocess.check_output(['ip', '-j', '-4', 'address', 'show'], text=True))
assert any(d['ifname'] == record['interface'] and any(a.get('local') == '198.18.12.10' for a in d['addr_info']) for d in addresses)
assert any(d['ifname'] == 'cmp-test0' and any(a.get('local') == '172.20.0.1' for a in d['addr_info']) for d in addresses)
assert not json.loads(subprocess.check_output(['ip', '-j', '-4', 'route', 'show', 'default'], text=True))
assert any(r['uuid'] == record['uuid'] and r['present'] and r['active'] for r in helper.list_managed({}))
try:
    helper.add({'parent': 'cmp-test0', 'vlan_id': 123, 'address': '198.19.12.10/24'})
    raise AssertionError('a duplicate VLAN was accepted')
except helper.Refused:
    pass
# Refuse to remove a profile that an administrator has repurposed.
subprocess.check_call(['nmcli', 'connection', 'modify', 'uuid', record['uuid'], 'connection.id', 'admin-owned'])
try:
    helper.remove({'uuid': record['uuid']})
    raise AssertionError('a changed profile was removed')
except helper.Refused:
    pass
subprocess.check_call(['nmcli', 'connection', 'modify', 'uuid', record['uuid'], 'connection.id', 'cmprovision-vlan-' + record['uuid']])
subprocess.check_call(['nmcli', 'connection', 'modify', 'uuid', record['uuid'], 'ipv4.addresses', '198.18.13.10/24'])
try:
    helper.remove({'uuid': record['uuid']})
    raise AssertionError('a changed IPv4 profile was removed')
except helper.Refused:
    pass
subprocess.check_call(['nmcli', 'connection', 'modify', 'uuid', record['uuid'], 'ipv4.addresses', record['address']])
# Even an owned VLAN cannot be removed if the provisioning address has been moved onto it.
subprocess.check_call(['ip', 'address', 'add', '172.20.0.1/16', 'dev', record['interface']])
try:
    helper.remove({'uuid': record['uuid']})
    raise AssertionError('the interface holding the provisioning address was removed')
except helper.Refused:
    pass
subprocess.check_call(['ip', 'address', 'delete', '172.20.0.1/16', 'dev', record['interface']])
subprocess.check_call(['ip', 'address', 'add', '172.20.0.2/16', 'dev', record['interface']])
try:
    helper.remove({'uuid': record['uuid']})
    raise AssertionError('the interface holding the second station address was removed')
except helper.Refused:
    pass
subprocess.check_call(['ip', 'address', 'delete', '172.20.0.2/16', 'dev', record['interface']])
try:
    helper.remove({'uuid': record['uuid']})
    raise AssertionError('an active VLAN was removed')
except helper.Refused:
    pass
subprocess.check_call(['nmcli', 'connection', 'down', 'uuid', record['uuid']], stdout=subprocess.DEVNULL)
helper.remove({'uuid': record['uuid']})
addresses = json.loads(subprocess.check_output(['ip', '-j', '-4', 'address', 'show'], text=True))
assert any(d['ifname'] == 'cmp-test0' and any(a.get('local') == '172.20.0.1' for a in d['addr_info']) for d in addresses)
empty = helper.add({'parent': 'cmp-test0', 'vlan_id': 125, 'address': ''})
assert subprocess.check_output(['nmcli', '-g', 'ipv4.method', 'connection', 'show', 'uuid', empty['uuid']], text=True).strip() == 'disabled'
subprocess.check_call(['nmcli', 'connection', 'down', 'uuid', empty['uuid']], stdout=subprocess.DEVNULL)
helper.remove({'uuid': empty['uuid']})
subprocess.check_call(['nmcli', 'connection', 'add', 'type', 'vlan', 'ifname', 'external124', 'dev', 'cmp-test0',
                       'id', '124', 'con-name', 'external-vlan', 'connection.autoconnect', 'no'], stdout=subprocess.DEVNULL)
try:
    helper.add({'parent': 'cmp-test0', 'vlan_id': 124, 'address': '198.19.12.10/24'})
    raise AssertionError('an inactive external VLAN profile was duplicated')
except helper.Refused:
    pass
subprocess.check_call(['nmcli', 'connection', 'delete', 'external-vlan'], stdout=subprocess.DEVNULL)
print('PASS: managed static VLAN can be added, duplicate rejected and safely removed')
