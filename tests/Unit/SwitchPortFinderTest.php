<?php

namespace Tests\Unit;

use App\Services\SwitchPortFinder;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSnmp;

/**
 * What the settings page's Test reads from the switch's MAC address table (SwitchPortFinder::scan()):
 * Q-BRIDGE-MIB, BRIDGE-MIB, Cisco IOS per-VLAN BRIDGE-MIB instances and HUAWEI-L2MAM-MIB. A module's port at its
 * start comes from the stand map (StandMapTest, PortLookupTest).
 */
class SwitchPortFinderTest extends TestCase
{
    const MODULE = 'e4:5f:01:62:9f:d3';
    const MODULE_OID = '228.95.1.98.159.211';

    protected function ifTable(array $names, array $aliases = [])
    {
        $t = [];
        foreach ($names as $ifIndex => $name)
            $t[SwitchPortFinder::OID_IFNAME.'.'.$ifIndex] = $name;
        foreach ($aliases as $ifIndex => $alias)
            $t[SwitchPortFinder::OID_IFALIAS.'.'.$ifIndex] = $alias;
        return $t;
    }

    public function test_q_bridge_table_with_vlans()
    {
        $snmp = new FakeSnmp(['' => [
            SwitchPortFinder::OID_QBRIDGE_FDB_PORT.'.10.'.self::MODULE_OID => 5,
            SwitchPortFinder::OID_QBRIDGE_FDB_PORT.'.1.0.17.34.51.68.85' => 8,
            SwitchPortFinder::OID_BASEPORT_IFINDEX.'.5' => 5,
            SwitchPortFinder::OID_BASEPORT_IFINDEX.'.8' => 8,
        ] + $this->ifTable([5 => 'gi5', 8 => 'gi8'])]);

        $scan = (new SwitchPortFinder($snmp))->scan();
        $this->assertSame('gi5', $scan['ports'][self::MODULE]);
        $this->assertSame('qbridge', $scan['method']);
    }

    public function test_bridge_table_when_there_is_no_q_bridge()
    {
        $snmp = new FakeSnmp(['' => [
            SwitchPortFinder::OID_BRIDGE_FDB_PORT.'.'.self::MODULE_OID => 7,
            SwitchPortFinder::OID_BASEPORT_IFINDEX.'.7' => 11,
        ] + $this->ifTable([11 => 'GE0/0/7'])]);

        $this->assertSame('GE0/0/7', (new SwitchPortFinder($snmp))->scan()['ports'][self::MODULE]);
    }

    public function test_cisco_ios_keeps_each_vlan_in_its_own_bridge_instance()
    {
        $snmp = new FakeSnmp([
            '' => [
                // the default instance only knows VLAN 1 (here: the uplink router)
                SwitchPortFinder::OID_BRIDGE_FDB_PORT.'.0.17.34.51.68.85' => 8,
                SwitchPortFinder::OID_BASEPORT_IFINDEX.'.8' => 10108,
                // vtpVlanState.<VTP domain>.<VLAN>: 1 = operational, 2 = suspended
                SwitchPortFinder::OID_CISCO_VLAN_STATE.'.1.1' => 1,
                SwitchPortFinder::OID_CISCO_VLAN_STATE.'.1.20' => 1,
                SwitchPortFinder::OID_CISCO_VLAN_STATE.'.1.30' => 2,
                SwitchPortFinder::OID_CISCO_VLAN_STATE.'.1.1002' => 1,
            ] + $this->ifTable([10105 => 'Gi0/5', 10108 => 'Gi0/8']),
            '20' => [
                SwitchPortFinder::OID_BRIDGE_FDB_PORT.'.'.self::MODULE_OID => 5,
                SwitchPortFinder::OID_BASEPORT_IFINDEX.'.5' => 10105,
            ],
        ]);

        $scan = (new SwitchPortFinder($snmp))->scan();
        $this->assertSame('Gi0/5', $scan['ports'][self::MODULE], 'VLAN 20 lives in its own instance');
        $this->assertStringEndsWith(' entries', $scan['tried']['cisco']);
        $this->assertNotContains([SwitchPortFinder::OID_BRIDGE_FDB_PORT, 1002], $snmp->walks, 'reserved VLANs 1002-1005 are skipped');
        $this->assertNotContains([SwitchPortFinder::OID_BRIDGE_FDB_PORT, 30], $snmp->walks, 'only operational VLANs are read');
    }

    public function test_huawei_l2if_maps_bridge_ports_where_the_bridge_mib_mapping_is_missing()
    {
        // Huawei YunShan: dot1dBasePortIfIndex is "not supported by some products"
        $snmp = new FakeSnmp(['' => [
            SwitchPortFinder::OID_BRIDGE_FDB_PORT.'.'.self::MODULE_OID => 32,
            SwitchPortFinder::OID_HUAWEI_L2IF_IFINDEX.'.32' => 35,
        ] + $this->ifTable([32 => 'GE1/0/29', 35 => 'GE1/0/30'])]);

        $this->assertSame('GE1/0/30', (new SwitchPortFinder($snmp))->scan()['ports'][self::MODULE]);
    }

    public function test_huawei_l2mam_table_gives_the_interface_directly()
    {
        $snmp = new FakeSnmp(['' => [
            // index: MAC (6 octets), VLAN, VSI name (length-prefixed, empty)
            SwitchPortFinder::OID_HUAWEI_FDB_PORT.'.'.self::MODULE_OID.'.20.0' => 9,
        ] + $this->ifTable([9 => 'GE0/0/3'])]);

        $scan = (new SwitchPortFinder($snmp))->scan();
        $this->assertSame('GE0/0/3', $scan['ports'][self::MODULE]);
        $this->assertSame('huawei', $scan['method']);
    }

    public function test_an_uplink_seeing_the_same_mac_loses_to_the_access_port()
    {
        $rows = [SwitchPortFinder::OID_BRIDGE_FDB_PORT.'.'.self::MODULE_OID => 3];
        for ($i = 1; $i <= 30; $i++)
            $rows[SwitchPortFinder::OID_QBRIDGE_FDB_PORT.'.1.0.0.0.0.0.'.$i] = 8;
        $rows[SwitchPortFinder::OID_QBRIDGE_FDB_PORT.'.20.'.self::MODULE_OID] = 3;
        $rows[SwitchPortFinder::OID_QBRIDGE_FDB_PORT.'.1.'.self::MODULE_OID] = 8;   // seen again behind the uplink
        $snmp = new FakeSnmp(['' => $rows + [
            SwitchPortFinder::OID_BASEPORT_IFINDEX.'.3' => 3, SwitchPortFinder::OID_BASEPORT_IFINDEX.'.8' => 8,
        ] + $this->ifTable([3 => 'ether3', 8 => 'ether8'])]);

        $this->assertSame('ether3', (new SwitchPortFinder($snmp))->scan()['ports'][self::MODULE]);
    }

    public function test_the_interface_description_is_preferred_when_set()
    {
        $snmp = new FakeSnmp(['' => [
            SwitchPortFinder::OID_BRIDGE_FDB_PORT.'.'.self::MODULE_OID => 2,
            SwitchPortFinder::OID_BASEPORT_IFINDEX.'.2' => 2,
        ] + $this->ifTable([2 => 'Gi0/2'], [2 => 'Slot 2'])]);

        $this->assertSame('Slot 2', (new SwitchPortFinder($snmp))->scan()['ports'][self::MODULE]);
    }

    public function test_an_unknown_mac_is_not_listed_and_a_silent_switch_is_asked_once()
    {
        $snmp = new FakeSnmp(['' => [SwitchPortFinder::OID_BRIDGE_FDB_PORT.'.0.17.34.51.68.85' => 1]]);
        $this->assertArrayNotHasKey(self::MODULE, (new SwitchPortFinder($snmp))->scan()['ports']);

        $silent = new FakeSnmp([]);
        $silent->timeout = true;
        $scan = (new SwitchPortFinder($silent))->scan();
        $this->assertFalse($scan['answered'], 'a switch that does not answer is an error, not an empty table');
        $this->assertStringContainsString('No response', reset($scan['tried']));
        $this->assertCount(1, $silent->walks, 'no point trying every method against a switch that does not answer');
    }

    public function test_scan_lists_ports_with_their_macs_for_the_settings_page()
    {
        $snmp = new FakeSnmp(['' => [
            SwitchPortFinder::OID_QBRIDGE_FDB_PORT.'.10.'.self::MODULE_OID => 5,
            SwitchPortFinder::OID_QBRIDGE_FDB_PORT.'.10.0.17.34.51.68.85' => 5,
            SwitchPortFinder::OID_BASEPORT_IFINDEX.'.5' => 5,
        ] + $this->ifTable([5 => 'gi5'])]);

        $scan = (new SwitchPortFinder($snmp))->scan();

        $this->assertSame('qbridge', $scan['method']);
        $this->assertSame([self::MODULE => 'gi5', '00:11:22:33:44:55' => 'gi5'], $scan['ports']);
        $this->assertSame(10, $scan['vlans'][self::MODULE]);
    }

    public function test_a_fixed_method_is_the_only_one_tried()
    {
        $snmp = new FakeSnmp(['' => [SwitchPortFinder::OID_QBRIDGE_FDB_PORT.'.10.'.self::MODULE_OID => 5]]);

        $this->assertSame([], (new SwitchPortFinder($snmp, 'bridge'))->scan()['ports']);
        foreach ($snmp->walks as $walk)
            $this->assertNotSame(SwitchPortFinder::OID_QBRIDGE_FDB_PORT, $walk[0]);
    }
}
