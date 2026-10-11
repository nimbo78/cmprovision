<?php

namespace Tests\Unit;

use App\Services\Switch\Poe;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSnmp;

/**
 * PoE of the switch's ports: Huawei's own table by interface, Cisco IOS through ENTITY-MIB, and the
 * plain POWER-ETHERNET-MIB (RFC 3621) by port number.
 */
class PoeTest extends TestCase
{
    protected function read(array $table, array $ports)
    {
        return (new Poe(new FakeSnmp(['' => $table])))->read($ports);
    }

    public function test_huawei_gives_power_by_interface_in_milliwatts()
    {
        $poe = $this->read([
            Poe::OID_HW_CONSUMING.'.8' => 6900,
            Poe::OID_HW_POWER_STATUS.'.8' => 'Powered',
            Poe::OID_HW_CONSUMING.'.9' => 0,
            Poe::OID_HW_POWER_STATUS.'.9' => 'Detecting',
            Poe::OID_HW_POWER_STATUS.'.10' => 'Disabled',
        ], [8 => 'GE0/0/3', 9 => 'GE0/0/4', 10 => 'GE0/0/5', 30 => 'XGE0/0/1']);

        $this->assertSame([
            8 => ['state' => 'delivering', 'class' => null, 'watts' => 6.9],
            9 => ['state' => 'searching', 'class' => null, 'watts' => 0.0],
            10 => ['state' => 'disabled', 'class' => null, 'watts' => null],
        ], $poe);
    }

    public function test_cisco_ios_maps_poe_ports_to_interfaces_through_entity_mib()
    {
        $poe = $this->read([
            Poe::OID_PETH_DETECTION.'.1.3' => 3,           // deliveringPower
            Poe::OID_PETH_CLASS.'.1.3' => 5,               // class4
            Poe::OID_CPE_ENTPHY.'.1.3' => 1003,
            Poe::OID_ENT_ALIAS.'.1003.0' => '.1.3.6.1.2.1.2.2.1.1.10103',
            Poe::OID_CPE_POWER.'.1.3' => 7100,
            Poe::OID_PETH_DETECTION.'.1.4' => 2,           // searching
            Poe::OID_CPE_ENTPHY.'.1.4' => 1004,
            Poe::OID_ENT_ALIAS.'.1004.0' => '.1.3.6.1.2.1.2.2.1.1.10104',
        ], [10103 => 'Gi1/0/3', 10104 => 'Gi1/0/4']);

        $this->assertSame([
            10103 => ['state' => 'delivering', 'class' => 4, 'watts' => 7.1],
            10104 => ['state' => 'searching', 'class' => null, 'watts' => null],
        ], $poe);
    }

    public function test_a_poe_port_number_that_is_an_interface_index_is_that_interface()
    {
        // Cisco Business and many others: pethPsePortIndex = ifIndex
        $poe = $this->read([Poe::OID_PETH_DETECTION.'.1.5' => 2], [5 => 'gi5', 6 => 'gi6']);
        $this->assertSame([5 => ['state' => 'searching', 'class' => null, 'watts' => null]], $poe);
    }

    public function test_otherwise_the_last_number_of_the_port_name_when_it_is_unique()
    {
        $poe = $this->read([
            Poe::OID_PETH_DETECTION.'.1.3' => 3,
            Poe::OID_PETH_DETECTION.'.1.7' => 3,
        ], [10103 => 'GE0/0/3', 10107 => 'GE0/0/7', 10207 => 'GE1/0/7']);

        $this->assertSame([10103 => ['state' => 'delivering', 'class' => null, 'watts' => null]], $poe,
            'port 7 is ambiguous (GE0/0/7 and GE1/0/7) and stays out');
    }

    public function test_a_switch_without_poe_tables_has_no_poe()
    {
        $this->assertSame([], $this->read([], [1 => 'ether1']));
    }
}
