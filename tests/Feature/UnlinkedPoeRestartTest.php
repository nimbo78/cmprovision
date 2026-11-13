<?php

namespace Tests\Feature;

use App\Http\Livewire\Ports;
use App\Models\Cm;
use App\Models\EthernetSwitch;
use App\Models\User;
use App\Services\Switch\MacTable;
use App\Services\Switch\Poe;
use App\Services\Switch\PoeControl;
use App\Services\Switch\PoeRestart;
use App\Services\Switch\Stand;
use App\Services\Switch\SwitchSnapshot;
use App\Support\StandMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\PoeStand;
use Tests\TestCase;

class UnlinkedPoeRestartTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    protected function poweredPortWithoutLink(array $settings = ['snmp_write_community' => 'private']): Cm
    {
        $cm = $this->stand($settings);
        $this->fake->tables[''][SwitchSnapshot::OID_IFOPERSTATUS.'.8'] = 2;
        unset($this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.211.10.0']);
        return $cm;
    }

    public function test_a_powered_port_without_link_can_be_restarted_by_port_without_identifying_a_module()
    {
        Sleep::fake();
        $this->poweredPortWithoutLink();

        $result = PoeRestart::ports(['s1p8'], 'igor');

        $this->assertSame(['s1p8'], array_keys($result['restarted']));
        $this->assertNull($result['restarted']['s1p8']['serial']);
        $this->assertSame([[Poe::OID_HW_ENABLE.'.8', 'i', PoeControl::OFF],
                          [Poe::OID_HW_ENABLE.'.8', 'i', PoeControl::ON]], $this->fake->sets);
        Sleep::assertSequence([Sleep::for(5)->seconds()]);
    }

    public function test_the_ports_page_explains_unknown_identity_and_offers_confirmed_power_restart()
    {
        $this->poweredPortWithoutLink();
        $this->actingAs(User::factory()->create());

        Livewire::test(Ports::class)->call('select', 's1p8')
            ->assertSee('PoE is delivering power without an Ethernet link.')
            ->assertSee('The connected device cannot be identified.')
            ->assertSeeHtml('wire:click="restart(\'s1p8\')"')
            ->assertSeeHtml('wire:confirm="Restart PoE on sw01 GE0/0/3? The connected device cannot be identified without an Ethernet link. PoE goes off for 5 s."');
    }

    public function test_a_port_only_searching_for_a_device_is_not_restarted()
    {
        $this->poweredPortWithoutLink();
        $this->fake->tables[''][Poe::OID_HW_POWER_STATUS.'.8'] = 'Detecting';
        $this->fake->tables[''][Poe::OID_HW_CONSUMING.'.8'] = 0;

        $result = PoeRestart::ports(['s1p8'], 'igor');

        $this->assertSame([], $this->fake->sets);
        $this->assertSame([], $result['restarted']);
        $this->assertSame('sw01 GE0/0/3 has no link', $result['skipped']['sw01 GE0/0/3']);
    }

    public function test_a_powered_port_without_link_preserves_station_and_switch_link_protection()
    {
        $this->poweredPortWithoutLink();
        $tile = Stand::current()['map']['tiles']['s1p8'];
        $tile['server'] = true;
        $this->assertSame('sw01 GE0/0/3 is the uplink', StandMap::restartBlocker($tile));
        $tile['server'] = false;
        $tile['station'] = ['name' => 'another-station'];
        $this->assertSame('sw01 GE0/0/3 is the uplink', StandMap::restartBlocker($tile));
        $tile['station'] = null;
        $tile['link'] = 'sw02';
        $this->assertSame('sw01 GE0/0/3 is the link to sw02', StandMap::restartBlocker($tile));
    }

    public function test_the_last_moment_eeprom_guard_also_protects_a_powered_port_without_link()
    {
        $cm = $this->poweredPortWithoutLink();
        $cm->update(['phase' => 'preinstall', 'switch_id' => 1, 'switch_port' => 'GE0/0/3',
            'phase_detail' => 'Flash EEPROM firmware (default/pieeprom.bin)',
            'phase_started_at' => now(), 'progress_updated_at' => now()]);

        $result = PoeRestart::ports(['s1p8'], 'igor');

        $this->assertSame([], $this->fake->sets);
        $this->assertSame('the module on sw01 GE0/0/3 is writing its bootloader EEPROM', $result['skipped']['sw01 GE0/0/3']);
    }

    public function test_a_remembered_powered_switch_without_link_stays_protected_across_polls()
    {
        $this->poweredPortWithoutLink();
        $this->secondSwitch(['write_community' => 'private', 'sys_name' => 'sw02']);
        EthernetSwitch::find(1)->forceFill(['links' => [8 => 2]])->save();
        $this->fake2->timeout = true;

        for ($poll = 0; $poll < 3; $poll++)
        {
            $tile = Stand::current()['map']['tiles']['s1p8'];
            $this->assertSame('free', $tile['state']);
            $this->assertSame('sw01 GE0/0/3 is the link to sw02', StandMap::restartBlocker($tile));
            $this->assertSame([], EthernetSwitch::find(1)->links, 'Down ports do not retain live topology links');
        }
        $result = PoeRestart::ports(['s1p8'], 'igor');
        $this->assertSame([], $result['restarted']);
        $this->assertSame([], $this->fake->sets);
    }

    public function test_powered_switch_safety_memory_does_not_classify_a_replacement_module_as_an_uplink()
    {
        $cm = $this->poweredPortWithoutLink();
        $this->secondSwitch(['write_community' => 'private', 'sys_name' => 'sw02']);
        EthernetSwitch::find(1)->forceFill(['links' => [8 => 2]])->save();
        $this->fake2->timeout = true;
        // The FDB may retain a downstream module while the switch's own MAC has aged out.
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.211.10.0'] = 8;
        $this->assertNotNull(StandMap::restartBlocker(Stand::current()['map']['tiles']['s1p8']));

        $this->fake->tables[''][SwitchSnapshot::OID_IFOPERSTATUS.'.8'] = 1;
        $this->travel(6)->seconds();
        Cache::forget('switch-snapshot:192.0.2.10');
        $map = Stand::current()['map'];
        $this->assertSame('module', $map['tiles']['s1p8']['state']);
        $this->assertSame($cm->serial, $map['tiles']['s1p8']['modules'][0]->serial);
        $this->assertNull($map['tiles']['s1p8']['restart_link']);
        $this->assertSame([], Cache::get('switch-powered-links:1')['links']);
    }

    public function test_disconnected_historical_modules_are_not_added_to_batch_restart_selection()
    {
        $cm = $this->poweredPortWithoutLink();
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertSame([], $result['restarted']);
        $this->assertSame([], $this->fake->sets);
    }

    public function test_a_powered_port_without_link_still_needs_switch_write_access()
    {
        $this->poweredPortWithoutLink([]);
        $result = PoeRestart::ports(['s1p8'], 'igor');
        $this->assertSame([], $result['restarted']);
        $this->assertSame([], $this->fake->sets);
        $this->assertStringContainsString('No write access', $result['error']);
    }

    public function test_a_deliberately_disabled_port_without_link_is_not_switched_on()
    {
        $this->poweredPortWithoutLink();
        $this->fake->tables[''][Poe::OID_HW_ENABLE.'.8'] = 2;
        $this->fake->tables[''][Poe::OID_HW_POWER_STATUS.'.8'] = 'Disabled';
        $result = PoeRestart::ports(['s1p8'], 'igor');
        $this->assertSame([], $result['restarted']);
        $this->assertSame([], $this->fake->sets);
    }
}
