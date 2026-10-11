<?php

namespace Tests\Unit;

use App\Services\Snmp\SnmpError;
use App\Services\Switch\MacTable;
use App\Services\Switch\SwitchSnapshot;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSnmp;

/**
 * One look at the switch for the Ports page: its physical ports, their link and speed, and the MAC
 * addresses learned on each.
 */
class SwitchSnapshotTest extends TestCase
{
    const MODULE = 'e4:5f:01:62:9f:d3';
    const MODULE_OID = '228.95.1.98.159.211';

    /** IF-MIB rows for [ifIndex => [name, ifType, ifConnectorPresent, ifOperStatus, ifHighSpeed]] */
    protected function interfaces(array $ports)
    {
        $t = [];
        foreach ($ports as $ifIndex => [$name, $type, $connector, $oper, $high])
        {
            $t[SwitchSnapshot::OID_IFNAME.'.'.$ifIndex] = $name;
            $t[SwitchSnapshot::OID_IFDESCR.'.'.$ifIndex] = 'description of '.$name;
            $t[SwitchSnapshot::OID_IFTYPE.'.'.$ifIndex] = $type;
            $t[SwitchSnapshot::OID_IFCONNECTOR.'.'.$ifIndex] = $connector;
            $t[SwitchSnapshot::OID_IFOPERSTATUS.'.'.$ifIndex] = $oper;
            $t[SwitchSnapshot::OID_IFHIGHSPEED.'.'.$ifIndex] = $high;
        }
        return $t;
    }

    protected function take(array $table)
    {
        $snmp = new FakeSnmp(['' => $table]);
        return (new SwitchSnapshot($snmp, new MacTable($snmp), null, '192.0.2.10'))->take();
    }

    public function test_physical_ethernet_ports_with_link_speed_and_macs()
    {
        $s = $this->take($this->interfaces([
            6 => ['GE0/0/1', 6, 1, 1, 1000],
            7 => ['GE0/0/2', 6, 1, 2, 1000],
            8 => ['GE0/0/3', 6, 1, 1, 1000],
            30 => ['XGE0/0/1', 6, 1, 1, 10000],
            50 => ['Vlanif1', 136, 2, 1, 1000],
            51 => ['Eth-Trunk1', 161, 2, 1, 2000],
            52 => ['NULL0', 1, 2, 1, 0],
        ]) + [
            SwitchSnapshot::OID_SYSDESCR.'.0' => "S5731-S24P4X\r\nHuawei Versatile Routing Platform Software",
            SwitchSnapshot::OID_IFALIAS.'.8' => 'stand 3',
            // HUAWEI-L2MAM: the module on GE0/0/3, the LAN behind the 10G uplink, the switch's own VLAN interface
            MacTable::OID_HUAWEI_FDB_PORT.'.'.self::MODULE_OID.'.10.0' => 8,
            MacTable::OID_HUAWEI_FDB_PORT.'.0.17.34.51.68.85.1.0' => 30,
            MacTable::OID_HUAWEI_FDB_PORT.'.0.17.34.51.68.86.1.0' => 50,
        ]);

        $this->assertSame([6, 7, 8, 30], array_keys($s['ports']), 'VLAN interfaces, trunks and NULL0 are not ports');
        $this->assertSame('S5731-S24P4X', $s['model']);
        $this->assertSame('huawei', $s['mac_method']);
        $this->assertSame('192.0.2.10', $s['id']);
        $this->assertFalse($s['poe']);
        $this->assertSame(['ifIndex' => 8, 'name' => 'GE0/0/3', 'alias' => 'stand 3', 'up' => true, 'speed' => 1000, 'changed' => null,
                           'macs' => [self::MODULE], 'mac_count' => 1, 'poe' => null], $s['ports'][8]);
        $this->assertFalse($s['ports'][7]['up']);
        $this->assertSame(1000, $s['ports'][7]['speed'], 'what the switch says without a link');
        $this->assertSame(['00:11:22:33:44:55'], $s['ports'][30]['macs']);
        $this->assertSame(10000, $s['ports'][30]['speed']);
    }

    public function test_an_old_switch_without_if_x_table_gets_names_from_if_descr_and_speed_from_if_speed()
    {
        $s = $this->take([
            SwitchSnapshot::OID_IFDESCR.'.1' => 'Port 1',
            SwitchSnapshot::OID_IFTYPE.'.1' => 6,
            SwitchSnapshot::OID_IFOPERSTATUS.'.1' => 1,
            SwitchSnapshot::OID_IFSPEED.'.1' => 100000000,
            SwitchSnapshot::OID_IFDESCR.'.2' => 'Port 2',
            SwitchSnapshot::OID_IFTYPE.'.2' => 6,
            SwitchSnapshot::OID_IFOPERSTATUS.'.2' => 2,
            SwitchSnapshot::OID_SYSDESCR.'.0' => '',
            SwitchSnapshot::OID_SYSNAME.'.0' => 'old-switch',
        ]);

        $this->assertSame(['Port 1', 'Port 2'], array_column($s['ports'], 'name'));
        $this->assertSame(100, $s['ports'][1]['speed']);
        $this->assertNull($s['ports'][2]['speed']);
        $this->assertSame('old-switch', $s['model']);
    }

    public function test_connector_flags_are_ignored_when_no_interface_has_one()
    {
        $s = $this->take($this->interfaces([
            1 => ['ether1', 6, 2, 1, 1000],
            2 => ['ether2', 6, 2, 2, 1000],
        ]));

        $this->assertSame(['ether1', 'ether2'], array_column($s['ports'], 'name'));
    }

    public function test_mikrotik_ports_and_its_bridge_interface()
    {
        $s = $this->take([
            SwitchSnapshot::OID_IFNAME.'.1' => 'ether1', SwitchSnapshot::OID_IFTYPE.'.1' => 6, SwitchSnapshot::OID_IFOPERSTATUS.'.1' => 1,
            SwitchSnapshot::OID_IFNAME.'.9' => 'sfp-sfpplus1', SwitchSnapshot::OID_IFTYPE.'.9' => 6, SwitchSnapshot::OID_IFOPERSTATUS.'.9' => 2,
            SwitchSnapshot::OID_IFNAME.'.12' => 'bridge', SwitchSnapshot::OID_IFTYPE.'.12' => 209, SwitchSnapshot::OID_IFOPERSTATUS.'.12' => 1,
        ]);

        $this->assertSame(['ether1', 'sfp-sfpplus1'], array_column($s['ports'], 'name'));
    }

    public function test_a_silent_switch_throws()
    {
        $snmp = new FakeSnmp(['' => []]);
        $snmp->timeout = true;
        $this->expectException(SnmpError::class);
        (new SwitchSnapshot($snmp, new MacTable($snmp)))->take();
    }

    public function test_a_switch_that_lists_no_interfaces_is_an_error_not_an_empty_stand()
    {
        // e.g. SNMPv3 with a wrong user: every table answers with an error, none with a timeout
        $this->expectException(SnmpError::class);
        $this->expectExceptionMessage('The switch lists no interfaces');
        $this->take([]);
    }

    public function test_old_fast_ethernet_types_are_ports_too()
    {
        $s = $this->take([
            SwitchSnapshot::OID_IFDESCR.'.1' => 'FastEthernet0/1', SwitchSnapshot::OID_IFTYPE.'.1' => 62, SwitchSnapshot::OID_IFOPERSTATUS.'.1' => 1,
            SwitchSnapshot::OID_IFDESCR.'.2' => 'FastEthernet0/2 FX', SwitchSnapshot::OID_IFTYPE.'.2' => 69, SwitchSnapshot::OID_IFOPERSTATUS.'.2' => 2,
        ]);

        $this->assertSame(['FastEthernet0/1', 'FastEthernet0/2 FX'], array_column($s['ports'], 'name'));
    }

    public function test_a_switch_without_ethernet_ports_is_an_error_not_an_empty_stand()
    {
        $this->expectException(SnmpError::class);
        $this->expectExceptionMessage('no Ethernet ports');
        $this->take([
            SwitchSnapshot::OID_IFNAME.'.1' => 'Vlanif1', SwitchSnapshot::OID_IFTYPE.'.1' => 136,
            SwitchSnapshot::OID_IFNAME.'.2' => 'NULL0', SwitchSnapshot::OID_IFTYPE.'.2' => 1,
        ]);
    }

    public function test_how_long_a_port_has_been_in_its_state_comes_from_if_last_change()
    {
        $s = $this->take($this->interfaces([1 => ['GE0/0/1', 6, 1, 1, 1000], 2 => ['GE0/0/2', 6, 1, 1, 1000]]) + [
            SwitchSnapshot::OID_SYSUPTIME.'.0' => 100000000,
            SwitchSnapshot::OID_IFLASTCHANGE.'.1' => 99990000,
            SwitchSnapshot::OID_IFLASTCHANGE.'.2' => 0,   // since before the agent last started
        ]);

        $this->assertSame(100, $s['ports'][1]['changed']);
        $this->assertSame(1000000, $s['ports'][2]['changed']);
    }

    public function test_if_descr_and_if_speed_are_asked_only_when_needed()
    {
        $snmp = new FakeSnmp(['' => $this->interfaces([1 => ['GE0/0/1', 6, 1, 1, 1000]])]);
        (new SwitchSnapshot($snmp, new MacTable($snmp)))->take();

        $walked = array_column($snmp->walks, 0);
        $this->assertNotContains(SwitchSnapshot::OID_IFDESCR, $walked, 'every port has an ifName');
        $this->assertNotContains(SwitchSnapshot::OID_IFSPEED, $walked, 'every port has an ifHighSpeed');
    }

    public function test_a_port_keeps_a_few_macs_and_counts_them_all()
    {
        $t = $this->interfaces([30 => ['XGE0/0/1', 6, 1, 1, 10000]]);
        for ($i = 1; $i <= 100; $i++)
            $t[MacTable::OID_HUAWEI_FDB_PORT.'.0.17.34.51.0.'.$i.'.1.0'] = 30;
        $s = $this->take($t);

        $this->assertSame(100, $s['ports'][30]['mac_count']);
        $this->assertCount(SwitchSnapshot::MAC_KEEP, $s['ports'][30]['macs']);
    }

    public function test_poe_lands_on_its_port()
    {
        $snmp = new FakeSnmp(['' => $this->interfaces([8 => ['GE0/0/3', 6, 1, 1, 1000]]) + [
            \App\Services\Switch\Poe::OID_HW_CONSUMING.'.8' => 6900,
        ]]);
        $s = (new SwitchSnapshot($snmp, new MacTable($snmp), new \App\Services\Switch\Poe($snmp)))->take();

        $this->assertTrue($s['poe']);
        $this->assertSame(6.9, $s['ports'][8]['poe']['watts']);
    }
}
