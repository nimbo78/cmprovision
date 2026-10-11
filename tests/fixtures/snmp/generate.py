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

# Ports page: whole switches with IF-MIB, MAC tables and PoE
IFTYPE = '1.3.6.1.2.1.2.2.1.3'
IFOPER = '1.3.6.1.2.1.2.2.1.8'
IFHIGH = '1.3.6.1.2.1.31.1.1.1.15'
IFCONN = '1.3.6.1.2.1.31.1.1.1.17'
PETH_DET = '1.3.6.1.2.1.105.1.1.1.6'
PETH_CLASS = '1.3.6.1.2.1.105.1.1.1.10'
HW_POE_STATUS = '1.3.6.1.4.1.2011.5.25.195.3.1.7'
HW_POE_POWER = '1.3.6.1.4.1.2011.5.25.195.3.1.10'
CPE_POWER = '1.3.6.1.4.1.9.9.402.1.2.1.9'
CPE_ENTPHY = '1.3.6.1.4.1.9.9.402.1.2.1.11'
ENT_ALIAS = '1.3.6.1.2.1.47.1.3.2.1.2'

def ports(spec):
    """spec: {ifIndex: (name, ifType, connector, oper, highSpeed)}"""
    recs = []
    for i, (name, typ, conn, oper, high) in spec.items():
        recs += [(f'{IFDESCR}.{i}', 4, name), (f'{IFNAME}.{i}', 4, name), (f'{IFALIAS}.{i}', 4, ''),
                 (f'{IFTYPE}.{i}', 2, typ), (f'{IFCONN}.{i}', 2, conn), (f'{IFOPER}.{i}', 2, oper), (f'{IFHIGH}.{i}', 66, high)]
    return recs

# Huawei S5731-S24P4X: 24 GE PoE + 4 XGE, HUAWEI-L2MAM and HUAWEI-POE-MIB by interface; the office LAN
# (00:11:22:33:44:55..59) behind XGE0/0/1
S5731 = {i: (f'GE0/0/{i}', 6, 1, 1 if i in (1, 3) else 2, 1000) for i in range(1, 25)}
S5731.update({24 + i: (f'XGE0/0/{i}', 6, 1, 1 if i == 1 else 2, 10000) for i in range(1, 5)})
S5731[50] = ('Vlanif1', 136, 2, 1, 1000)
FILES['s5731'] = [
    (SYSDESCR, 4, 'S5731-S24P4X'),
    (f'{HWFDB}.{MODULE}.10.0', 2, 3),
    (f'{HW_POE_STATUS}.3', 4, 'Powered'), (f'{HW_POE_POWER}.3', 2, 6900),
] + [(f'{HWFDB}.0.17.34.51.68.{85 + n}.1.0', 2, 25) for n in range(5)] \
  + [(f'{HW_POE_STATUS}.{i}', 4, 'Detecting') for i in range(1, 25) if i != 3] + ports(S5731)

# Cisco IOS (Catalyst 9200L-like): 8 Gi PoE + 2 Te, BRIDGE-MIB per VLAN, PoE through ENTITY-MIB
C9200 = {10100 + i: (f'Gi1/0/{i}', 6, 1, 1 if i == 5 else 2, 1000) for i in range(1, 9)}
C9200.update({10200 + i: (f'Te1/1/{i}', 6, 1, 1 if i == 1 else 2, 10000) for i in range(1, 3)})
FILES['c9200'] = [
    (SYSDESCR, 4, 'Cisco IOS Software, C9200L'),
    (f'{FDB}.{UPLINK_PEER}', 2, 9), (f'{BASE}.9', 2, 10201),
    (f'{VTP}.1.1', 2, 1), (f'{VTP}.1.20', 2, 1),
] + [(f'{PETH_DET}.1.{i}', 2, 3 if i == 5 else 2) for i in range(1, 9)] \
  + [(f'{PETH_CLASS}.1.5', 2, 5), (f'{CPE_POWER}.1.5', 66, 7100)] \
  + [(f'{CPE_ENTPHY}.1.{i}', 2, 1000 + i) for i in range(1, 9)] \
  + [(f'{ENT_ALIAS}.{1000 + i}.0', 6, f'1.3.6.1.2.1.2.2.1.1.{10100 + i}') for i in range(1, 9)] + ports(C9200)
FILES['c9200@1'] = [(f'{FDB}.{UPLINK_PEER}', 2, 9), (f'{BASE}.9', 2, 10201)]
FILES['c9200@20'] = [(f'{FDB}.{MODULE}', 2, 5), (f'{BASE}.5', 2, 10105)]

# Cisco Business CBS350-8P-2G: Q-BRIDGE with interface indexes, RFC 3621 port number = ifIndex
CBS350 = {i: (f'gi{i}', 6, 1, 1 if i == 2 else 2, 1000) for i in range(1, 11)}
FILES['cbs350'] = [
    (SYSDESCR, 4, 'CBS350-8P-2G 8-Port Gigabit PoE Managed Switch'),
    (f'{QFDB}.1.{MODULE}', 2, 2),
] + [(f'{PETH_DET}.1.{i}', 2, 3 if i == 2 else 2) for i in range(1, 9)] + ports(CBS350)

# MikroTik CRS309-like: ether ports, an sfp+ cage, the bridge interface; no PoE
CRS = {i: (f'ether{i}', 6, 1, 1 if i == 4 else 2, 1000) for i in range(1, 9)}
CRS[9] = ('sfp-sfpplus1', 6, 1, 2, 10000)
CRS[12] = ('bridge', 209, 2, 1, 1000)
FILES['crs'] = [
    (SYSDESCR, 4, 'RouterOS CRS309'),
    (f'{QFDB}.0.{MODULE}', 2, 4),
    (f'{BASE}.4', 2, 4),
] + ports(CRS)

def key(rec):
    return tuple(int(p) for p in rec[0].split('.'))

out = sys.argv[1]
os.makedirs(out, exist_ok=True)
for name, recs in FILES.items():
    with open(os.path.join(out, name + '.snmprec'), 'w', newline='\n') as f:
        for oid, typ, val in sorted(recs, key=key):
            f.write(f'{oid}|{typ}|{val}\n')
    print(name, len(recs), 'records')
