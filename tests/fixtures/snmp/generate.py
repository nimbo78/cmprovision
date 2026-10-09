"""Generate snmpsim data files (oid|type|value, sorted by OID) for the switch lookup tests.
Usage: python3 tests/fixtures/snmp/generate.py tests/fixtures/snmp"""
import os, sys

MODULE = '228.95.1.98.159.211'      # e4:5f:01:62:9f:d3
UPLINK_PEER = '0.17.34.51.68.85'    # 00:11:22:33:44:55

QFDB = '1.3.6.1.2.1.17.7.1.2.2.1.2'
FDB = '1.3.6.1.2.1.17.4.3.1.2'
BASE = '1.3.6.1.2.1.17.1.4.1.2'
IFDESCR = '1.3.6.1.2.1.2.2.1.2'
IFNAME = '1.3.6.1.2.1.31.1.1.1.1'
IFALIAS = '1.3.6.1.2.1.31.1.1.1.18'
SYSDESCR = '1.3.6.1.2.1.1.1.0'
VTP = '1.3.6.1.4.1.9.9.46.1.3.1.1.2'   # vtpVlanState.<domain>.<vlan>
HWFDB = '1.3.6.1.4.1.2011.5.25.42.2.1.3.1.4'
L2IF = '1.3.6.1.4.1.2011.5.25.42.1.1.1.3.1.2'

def interfaces(names, aliases=None):
    aliases = aliases or {}
    recs = []
    for i, n in names.items():
        recs += [(f'{IFDESCR}.{i}', 4, n), (f'{IFNAME}.{i}', 4, n), (f'{IFALIAS}.{i}', 4, aliases.get(i, ''))]
    return recs

FILES = {
    # VLAN-aware switch: Huawei VRP/YunShan, MikroTik, Cisco Business
    'qbridge': [
        (SYSDESCR, 4, 'bench Q-BRIDGE switch'),
        (f'{QFDB}.10.{MODULE}', 2, 5),
        (f'{QFDB}.1.{UPLINK_PEER}', 2, 8),
        (f'{BASE}.5', 2, 5), (f'{BASE}.8', 2, 8),
    ] + interfaces({5: 'GE0/0/5', 8: 'GE0/0/8'}, {8: 'uplink'}),
    # Cisco IOS: the default instance only has VLAN 1, every VLAN has its own instance
    'ciscoios': [
        (SYSDESCR, 4, 'bench Cisco IOS'),
        (f'{FDB}.{UPLINK_PEER}', 2, 8), (f'{BASE}.8', 2, 10108),
        (f'{VTP}.1.1', 2, 1), (f'{VTP}.1.20', 2, 1), (f'{VTP}.1.30', 2, 2), (f'{VTP}.1.1002', 2, 1),
    ] + interfaces({10105: 'Gi0/5', 10108: 'Gi0/8'}),
    'ciscoios@1': [(f'{FDB}.{UPLINK_PEER}', 2, 8), (f'{BASE}.8', 2, 10108)],
    'ciscoios@20': [(f'{FDB}.{MODULE}', 2, 5), (f'{BASE}.5', 2, 10105)],
    # the same VLAN instance as an SNMPv3 context (Cisco: context "vlan-<id>")
    'vlan-20': [(f'{FDB}.{MODULE}', 2, 5), (f'{BASE}.5', 2, 10105)],
    # Huawei YunShan (S5735-S-V2): BRIDGE-MIB table, bridge ports mapped by HUAWEI-L2IF-MIB only
    'yunshan': [
        (SYSDESCR, 4, 'bench Huawei YunShan'),
        (f'{FDB}.{MODULE}', 2, 32),
        (f'{FDB}.{UPLINK_PEER}', 2, -1),    # seen on real Huawei captures, must be ignored
        (f'{L2IF}.32', 2, 35),
    ] + interfaces({32: 'GE1/0/29', 35: 'GE1/0/30'}),
    # MikroTik RouterOS 7: Q-BRIDGE with FdbId 0 entries next to the VLAN ones, comments as ifAlias
    'routeros': [
        (SYSDESCR, 4, 'bench RouterOS'),
        (f'{QFDB}.0.{MODULE}', 2, 3),
        (f'{QFDB}.1.{UPLINK_PEER}', 2, 1),
        (f'{BASE}.1', 2, 2), (f'{BASE}.3', 2, 4),
    ] + interfaces({2: 'ether1', 4: 'ether3'}, {4: 'line slot 3'}),
    # Huawei: proprietary table, interface index directly; index MAC.VLAN.VSI-name(empty)
    'huawei': [
        (SYSDESCR, 4, 'bench Huawei L2MAM'),
        (f'{HWFDB}.{MODULE}.20.0', 2, 9),
    ] + interfaces({9: 'GE0/0/3'}),
}

def key(rec):
    return tuple(int(p) for p in rec[0].split('.'))

out = sys.argv[1]
os.makedirs(out, exist_ok=True)
for name, recs in FILES.items():
    with open(os.path.join(out, name + '.snmprec'), 'w', newline='\n') as f:
        for oid, typ, val in sorted(recs, key=key):
            f.write(f'{oid}|{typ}|{val}\n')
    print(name, len(recs), 'records')
