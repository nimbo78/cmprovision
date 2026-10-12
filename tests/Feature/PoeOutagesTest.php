<?php

namespace Tests\Feature;

use App\Http\Livewire\PoeOutages;
use App\Http\Livewire\Ports;
use App\Models\PoeOutage;
use App\Models\User;
use App\Services\Switch\Poe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** A port a restart left off: shown on the dashboard and the Ports page with Power on and Forget until it is on */
class PoeOutagesTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    protected function outage(array $attributes = [])
    {
        return PoeOutage::create(array_merge(['switch_host' => '192.0.2.10', 'if_index' => 8, 'port' => 'GE0/0/3',
            'oid' => Poe::OID_HW_ENABLE.'.8', 'on_value' => 1, 'cm_serial' => '10000000e611110c', 'user' => 'igor',
            'off_at' => now()], $attributes));
    }

    public function test_a_port_left_off_is_shown_on_the_dashboard_and_the_ports_page()
    {
        $this->mockNetwork();
        config(['app.display_timezone' => 'UTC']);
        $this->outage(['error' => 'noAccess', 'off_at' => Carbon::parse('2026-10-12 11:05:00')]);
        $user = User::factory()->create();
        foreach (['/dashboard', '/ports'] as $url)
        {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('PoE on GE0/0/3 stayed off after a restart at 11:05 (by igor): noAccess', $html, $url);
            $this->assertStringContainsString('Power on', $html, $url);
        }
    }

    public function test_a_restart_in_its_dark_seconds_is_not_a_port_left_off()
    {
        $this->outage();
        Livewire::test(PoeOutages::class)->assertDontSee('stayed off');
    }

    public function test_power_on_switches_the_port_on_and_closes_it()
    {
        Sleep::fake();
        $this->stand();
        $this->fake->tables[''][Poe::OID_HW_ENABLE.'.8'] = 2;
        $outage = $this->outage(['error' => 'noAccess']);
        Livewire::actingAs(User::factory()->create(['name' => 'anna']))->test(PoeOutages::class)
            ->call('powerOn', $outage->id)
            ->assertSee('PoE on GE0/0/3 is on again.')
            ->assertDontSee('stayed off');
        $this->assertSame([[Poe::OID_HW_ENABLE.'.8', 'i', 1]], $this->fake->sets);
        $this->assertNotNull($outage->fresh()->on_at);
        $this->assertDatabaseHas('cmlogs', ['board' => 'GE0/0/3', 'msg' => 'PoE on GE0/0/3 switched on by anna']);
    }

    public function test_power_on_that_fails_keeps_the_port_listed_with_the_new_reason()
    {
        Sleep::fake();
        $this->stand();
        $this->fake->refuse[Poe::OID_HW_ENABLE.'.8'] = 'genError';
        $outage = $this->outage(['error' => 'noAccess']);
        Livewire::test(PoeOutages::class)->call('powerOn', $outage->id)->assertSee('PoE on GE0/0/3 is still off: genError');
        $this->assertSame('genError', $outage->fresh()->error);
        $this->assertNull($outage->fresh()->on_at);
    }

    public function test_power_on_does_not_write_to_another_switch()
    {
        $this->stand();
        $outage = $this->outage(['error' => 'noAccess', 'switch_host' => '192.0.2.99']);
        Livewire::test(PoeOutages::class)->call('powerOn', $outage->id)->assertSee('The switch has changed: switch GE0/0/3 on at the switch.');
        $this->assertSame([], $this->fake->sets);
    }

    public function test_forget_closes_without_writing()
    {
        $this->stand();
        $outage = $this->outage(['error' => 'noAccess']);
        Livewire::test(PoeOutages::class)
            ->assertSeeHtml('wire:confirm="The port may still be off on the switch. Forget it?"')
            ->call('forget', $outage->id);
        $this->assertNotNull($outage->fresh()->on_at);
        $this->assertSame([], $this->fake->sets);
        $this->assertDatabaseHas('cmlogs', ['board' => 'GE0/0/3', 'loglevel' => 'warning']);
    }

    public function test_the_ports_page_closes_a_port_the_switch_shows_with_poe_on()
    {
        $this->stand();
        $outage = $this->outage(['error' => 'noAccess', 'off_at' => now()->subMinute()]);
        Livewire::test(Ports::class);
        $this->assertNotNull($outage->fresh()->on_at);
    }

    public function test_the_ports_page_keeps_a_port_whose_poe_is_still_switched_off()
    {
        $this->stand();
        // the port's own object still says off, whatever words the PoE status uses (Huawei's are not verified)
        $this->fake->tables[''][Poe::OID_HW_ENABLE.'.8'] = 2;
        $outage = $this->outage(['error' => 'noAccess', 'off_at' => now()->subMinute()]);
        Livewire::test(Ports::class);
        $this->assertNull($outage->fresh()->on_at);
    }

    public function test_the_port_of_a_booting_pi_is_powered_on_and_forgotten_without_a_serial()
    {
        Sleep::fake();
        $this->stand();
        $first = $this->outage(['error' => 'noAccess', 'cm_serial' => null]);
        $second = $this->outage(['error' => 'noAccess', 'cm_serial' => null, 'if_index' => 7, 'port' => 'GE0/0/2', 'oid' => Poe::OID_HW_ENABLE.'.7']);
        Livewire::test(PoeOutages::class)
            ->call('powerOn', $first->id)->assertSee('PoE on GE0/0/3 is on again.')
            ->call('forget', $second->id);
        $this->assertDatabaseHas('cmlogs', ['cm' => '', 'board' => 'GE0/0/3', 'loglevel' => 'info']);
        $this->assertDatabaseHas('cmlogs', ['cm' => '', 'board' => 'GE0/0/2', 'loglevel' => 'warning']);
    }
}
