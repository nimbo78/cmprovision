<?php

namespace Tests\Feature;

use App\Models\EthernetSwitch;
use App\Services\Switch\Stand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** Every switch of the stand in one map; each keeps what it said about itself */
class StandTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    public function test_every_switch_is_read_and_remembers_what_it_said()
    {
        $this->stand();
        $this->secondSwitch();

        $stand = Stand::current();

        $this->assertSame([1, 2], array_keys($stand['switches']));
        $this->assertSame(['sw01', 'sw02'], array_column($stand['map']['switches'], 'name'));
        $this->assertArrayHasKey('s2p8', $stand['map']['tiles']);
        $this->assertSame(['sw01', 'huawei'], [EthernetSwitch::find(1)->sys_name, EthernetSwitch::find(1)->method_detected]);
    }

    public function test_a_silent_switch_does_not_hide_the_other()
    {
        $this->stand();
        $this->secondSwitch();
        $this->fake2->timeout = true;

        $stand = Stand::current();

        $this->assertFalse(Stand::answering($stand['switches'][2]));
        $this->assertTrue(Stand::answering($stand['switches'][1]));
        $this->assertSame('module', $stand['map']['tiles']['s1p8']['state']);
        $this->assertSame('192.0.2.11', $stand['map']['switches'][2]['name'], 'never answered: named by its address');
    }

    public function test_a_module_is_found_on_its_own_port_of_the_stand()
    {
        $one = $this->stand();
        $two = $this->secondSwitch();

        $map = Stand::current()['map'];

        $this->assertSame(['s1p8', 's2p8'], [Stand::tileOf($map, $one)['key'], Stand::tileOf($map, $two)['key']]);
    }

    public function test_a_link_found_between_the_switches_is_remembered()
    {
        $this->stand();
        $this->secondSwitch();
        // sw01 GE0/0/1 leads to sw02: its table holds sw02's base MAC
        $this->fake2->tables[''][\App\Services\Switch\SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0'] = hex2bin('0057d297a180');
        $this->fake->tables[''][\App\Services\Switch\MacTable::OID_HUAWEI_FDB_PORT.'.0.87.210.151.161.128.1.0'] = 6;

        $stand = Stand::current();

        $this->assertSame('sw02', $stand['map']['tiles']['s1p6']['link']);
        $this->assertSame([6 => 2], EthernetSwitch::find(1)->links);
        $this->assertSame('00:57:d2:97:a1:80', EthernetSwitch::find(2)->base_mac);
    }
}
