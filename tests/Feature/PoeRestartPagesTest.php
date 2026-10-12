<?php

namespace Tests\Feature;

use App\Http\Livewire\CmCard;
use App\Models\Cm;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** The Restart buttons of the module card and the dashboard */
class PoeRestartPagesTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    public function test_the_card_shows_the_port_and_restarts_the_module()
    {
        Sleep::fake();
        $cm = $this->stand();
        Livewire::test(CmCard::class, ['serial' => $cm->serial])
            ->assertSee('Power (PoE)')
            ->assertSee('GE0/0/3 · delivering 6.9 W')
            ->assertSeeHtml('wire:confirm="Restart 10000000e611110c on GE0/0/3? PoE goes off for 5 s; the module boots from the network and is provisioned again."')
            ->call('restart')
            ->assertSee('Restarted GE0/0/3 (10000000e611110c): PoE was off for 5 s.');
    }

    public function test_the_card_says_why_there_is_no_restart()
    {
        $cm = $this->stand([]);
        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertSee('no restart: no write access (Settings → Ethernet switch)');
        Setting::create(['key' => 'ethernetswitch_snmp_write_community', 'value' => 'private']);
        $away = Cm::create(['serial' => '10000000e611ffff', 'mac' => 'e4:5f:01:00:ff:ff', 'phase' => 'failed']);
        Livewire::test(CmCard::class, ['serial' => $away->serial])->assertSee('no restart: not seen on the switch');
    }

    public function test_a_card_without_a_switch_has_no_power_row()
    {
        $cm = Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'phase' => 'done']);
        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertDontSee('Power (PoE)');
    }
}
