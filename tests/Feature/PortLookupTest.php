<?php

namespace Tests\Feature;

use App\Models\EthernetSwitch;
use App\Services\Switch\MacTable;
use App\Services\Switch\PortLookup;
use App\Services\Switch\Stand;
use App\Services\Switch\SwitchSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** The port of a module at its start: the stand map's port for its MAC, named "hostname port" */
class PortLookupTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    public function test_a_module_on_the_second_switch_gets_its_port()
    {
        $this->stand();
        $this->secondSwitch();
        $found = PortLookup::find('10000000e611110f', 'e4:5f:01:62:9f:d5', now()->getTimestamp());
        $this->assertSame(['sw02 GE0/0/3', 'GE0/0/3', 2, null],
            [$found['board'], $found['port'], $found['switch']->id, $found['warning']]);
    }

    public function test_a_module_behind_the_link_gets_the_port_of_its_own_switch()
    {
        $this->stand();
        $this->secondSwitch();
        $this->fake2->tables[''][SwitchSnapshot::OID_IFPHYSADDRESS.'.900'] = hex2bin('0057d297a1c0');   // sw02's SVI
        // sw01's GE0/0/2 leads to sw02: sw02's SVI MAC and its module are there; on sw02 the module is on GE0/0/3
        // with one more device, so both ports would hold two addresses without the link
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.213.10.0'] = 7;
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.0.87.210.151.161.192.10.0'] = 7;
        $this->fake2->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.170.0.0.0.0.1.10.0'] = 8;   // aa:00:00:00:00:01 beside it

        $found = PortLookup::find('10000000e611110f', 'e4:5f:01:62:9f:d5', now()->getTimestamp());

        $this->assertSame('sw02 GE0/0/3', $found['board'], 'without the link the two ports would tie');
        $this->assertSame('sw02', Stand::current()['map']['tiles']['s1p7']['link']);
    }

    public function test_a_silent_neighbour_leaves_its_modules_without_a_board()
    {
        $this->stand();
        $this->secondSwitch(['write_community' => 'private', 'sys_name' => 'sw02']);   // it answered before
        EthernetSwitch::find(1)->forceFill(['links' => [30 => 2]])->save();             // XGE0/0/1 was found leading to sw02
        $this->fake2->timeout = true;
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.213.1.0'] = 30;

        $found = PortLookup::find('10000000e611110f', 'e4:5f:01:62:9f:d5', now()->getTimestamp());

        $this->assertNull($found['board']);
        $this->assertStringContainsString('seen behind sw01 XGE0/0/1 (link to sw02)', $found['warning']);
        $this->assertStringContainsString('sw02 does not answer', $found['warning']);
    }

    public function test_a_silent_switch_is_named_even_when_the_other_found_the_port()
    {
        $this->stand();
        $this->secondSwitch(['write_community' => 'private', 'sys_name' => 'sw02']);
        $this->fake2->timeout = true;
        $found = PortLookup::find('10000000e611110c', 'e4:5f:01:62:9f:d3', now()->getTimestamp());
        $this->assertSame('sw01 GE0/0/3', $found['board']);
        $this->assertStringContainsString('sw02 does not answer', $found['warning']);
    }

    public function test_an_unknown_mac_names_the_switches_asked()
    {
        $this->stand();
        $found = PortLookup::find('10000000e611110f', 'e4:5f:01:00:00:99', now()->getTimestamp());
        $this->assertNull($found['board']);
        $this->assertSame('Switch port unknown: sw01 does not know MAC e4:5f:01:00:00:99', $found['warning']);
    }

    public function test_a_module_seen_alike_on_two_switches_is_on_multiple_ports()
    {
        $this->stand();
        $this->secondSwitch();
        $this->fake2->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.211.10.0'] = 6;   // e4:5f:01:62:9f:d3 alone on sw02 too
        $found = PortLookup::find('10000000e611110c', 'e4:5f:01:62:9f:d3', now()->getTimestamp());
        $this->assertSame(['[multiple ports]', null], [$found['board'], $found['switch']]);
    }

    public function test_starts_close_together_read_the_switch_once()
    {
        $this->stand();
        PortLookup::find('10000000e611110c', 'e4:5f:01:62:9f:d3', now()->getTimestamp());
        $walks = count($this->fake->walks);
        PortLookup::find('10000000e611110c', 'e4:5f:01:62:9f:d3', now()->getTimestamp() - 1);
        $this->assertSame($walks, count($this->fake->walks));
    }

    public function test_a_module_seen_only_on_the_link_has_its_own_switch_asked_again()
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->stand();
        $this->secondSwitch();
        $this->fake2->tables[''][SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0'] = hex2bin('0057d297a180');
        $module = MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.213.10.0';   // e4:5f:01:62:9f:d5
        unset($this->fake2->tables[''][$module]);                             // not up yet
        Stand::current();
        // sw02 is slow: its snapshot holds twice its poll time, here 20 s
        $state = Cache::get('switch-snapshot:192.0.2.11');
        $state['snapshot']['duration'] = 10;
        Cache::put('switch-snapshot:192.0.2.11', $state, 3600);
        // the module comes up on sw02 GE0/0/3; sw01 sees it on GE0/0/2, its link to sw02
        $this->fake2->tables[''][$module] = 8;
        $this->fake->tables[''][$module] = 7;
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.0.87.210.151.161.128.1.0'] = 7;
        Carbon::setTestNow('2026-10-14 10:00:06');

        $found = PortLookup::find('10000000e611110f', 'e4:5f:01:62:9f:d5', now()->getTimestamp());

        $this->assertSame('sw02 GE0/0/3', $found['board'], "sw02's snapshot is from before the module's request");
    }

    public function test_a_silent_switch_s_last_snapshot_gives_no_board()
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->stand();
        $this->secondSwitch(['write_community' => 'private', 'sys_name' => 'sw02']);
        Stand::current();                               // sw02 saw the module on GE0/0/3 then
        $this->fake2->timeout = true;
        Carbon::setTestNow('2026-10-14 11:00:00');      // an hour on, sw02 does not answer: the module may be anywhere

        $found = PortLookup::find('10000000e611110f', 'e4:5f:01:62:9f:d5', now()->getTimestamp());

        $this->assertSame([null, null, null], [$found['board'], $found['switch'], $found['port']]);
        $this->assertStringContainsString('Switch port unknown: MAC e4:5f:01:62:9f:d5 last seen on sw02 GE0/0/3 at ', $found['warning']);
        $this->assertStringContainsString('sw02 does not answer', $found['warning']);
    }

    public function test_a_module_that_came_after_the_last_poll_gets_a_new_one()
    {
        $this->stand();
        Stand::current();                                                         // a page polled a moment ago
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.212.10.0'] = 7;   // then e4:5f:01:62:9f:d4 came up
        $this->travel(1)->seconds();
        $found = PortLookup::find('10000000e611110d', 'e4:5f:01:62:9f:d4', now()->getTimestamp());
        $this->assertSame('sw01 GE0/0/2', $found['board']);
    }
}
