<?php

namespace Tests\Unit;

use App\Services\Snmp\SnmpError;
use App\Services\Switch\MacTable;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSnmp;

/**
 * The switch's MAC address table as rows the Ports page and the port finder share.
 */
class MacTableTest extends TestCase
{
    const MODULE = 'e4:5f:01:62:9f:d3';
    const MODULE_OID = '228.95.1.98.159.211';

    public function test_entries_come_from_the_first_method_with_rows_and_later_ones_add_only_missing_macs()
    {
        $snmp = new FakeSnmp(['' => [
            MacTable::OID_QBRIDGE_FDB_PORT.'.10.'.self::MODULE_OID => 5,
            MacTable::OID_BASEPORT_IFINDEX.'.5' => 105,
            // BRIDGE-MIB knows the module as well (on another port: not taken) and one MAC Q-BRIDGE lacks
            MacTable::OID_BRIDGE_FDB_PORT.'.'.self::MODULE_OID => 7,
            MacTable::OID_BRIDGE_FDB_PORT.'.0.17.34.51.68.85' => 7,
            MacTable::OID_BASEPORT_IFINDEX.'.7' => 107,
        ]]);

        $entries = (new MacTable($snmp))->entries();

        $this->assertSame('qbridge', $entries['method']);
        $this->assertEquals([
            ['mac' => self::MODULE, 'vlan' => 10, 'ifIndex' => 105],
            ['mac' => '00:11:22:33:44:55', 'vlan' => null, 'ifIndex' => 107],
        ], $entries['rows']);
    }

    public function test_the_method_that_found_modules_before_is_enough_when_it_has_rows()
    {
        $snmp = new FakeSnmp(['' => [
            MacTable::OID_QBRIDGE_FDB_PORT.'.10.'.self::MODULE_OID => 5,
            MacTable::OID_BRIDGE_FDB_PORT.'.0.17.34.51.68.85' => 7,
        ]]);

        $entries = (new MacTable($snmp, 'auto', 'qbridge'))->entries();

        $this->assertSame([self::MODULE], array_column($entries['rows'], 'mac'));
        $this->assertNotContains(MacTable::OID_BRIDGE_FDB_PORT, array_column($snmp->walks, 0), 'no second walk of the MAC table');
    }

    public function test_entries_of_a_switch_without_a_mac_table_are_empty()
    {
        $entries = (new MacTable(new FakeSnmp(['' => []])))->entries();
        $this->assertSame(['method' => null, 'rows' => []], $entries);
    }

    public function test_a_silent_switch_is_an_error()
    {
        $snmp = new FakeSnmp(['' => []]);
        $snmp->timeout = true;
        $this->expectException(SnmpError::class);
        (new MacTable($snmp))->entries();
    }

    public function test_pick_gives_a_mac_seen_on_two_ports_to_the_one_with_fewer_macs()
    {
        $rows = [
            ['mac' => 'aa:aa:aa:aa:aa:01', 'ifIndex' => 1, 'vlan' => 1],
            ['mac' => 'aa:aa:aa:aa:aa:01', 'ifIndex' => 9, 'vlan' => 1],
            ['mac' => 'aa:aa:aa:aa:aa:02', 'ifIndex' => 9, 'vlan' => 1],
        ];
        $this->assertSame(['aa:aa:aa:aa:aa:01' => 1, 'aa:aa:aa:aa:aa:02' => 9], MacTable::pick($rows));
    }
}
