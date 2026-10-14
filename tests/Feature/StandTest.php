<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\EthernetSwitch;
use App\Services\Switch\Stand;
use App\Services\Switch\SwitchSnapshot;
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

    public function test_one_switch_under_two_addresses_is_drawn_once()
    {
        $this->stand();
        $this->secondSwitch();
        // 192.0.2.11 answers as the switch at 192.0.2.10: the same base MAC
        foreach ([$this->fake, $this->fake2] as $fake)
            $fake->tables[''][SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0'] = hex2bin('0057d297a180');

        $stand = Stand::current();

        $this->assertSame([null, 1], [$stand['switches'][1]['same_as'], $stand['switches'][2]['same_as']]);
        $this->assertSame([1], array_keys($stand['map']['switches']), 'its twin ports would tie every module');
        $this->assertSame('s1p8', Stand::tileOf($stand['map'], Cm::where('serial', '10000000e611110c')->first())['key']);
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
