<?php

namespace Tests\Feature;

use App\Http\Livewire\ProvisioningStatus;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Identify": a module that finished stays in the utility OS and asks the server every few seconds
 * whether to blink; the operator presses Identify on the module card or the dashboard, and the
 * module alternates its LEDs for 30 seconds. The button is there only for a finished module that
 * still asks, i.e. is on the bench.
 */
class ModuleIdentifyTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function module(array $attributes = [])
    {
        return Cm::create(array_merge([
            'serial' => self::SERIAL, 'mac' => 'e4:5f:01:62:9f:d3', 'phase' => 'done', 'timeline' => [],
            'provisioning_started_at' => now()->subMinutes(5), 'provisioning_complete_at' => now()->subMinute(),
            'phase_started_at' => now()->subMinute(),
        ], $attributes));
    }

    protected function poll($serial = self::SERIAL)
    {
        return $this->get('/scriptexecute?serial='.$serial.'&identify=poll')->assertOk()->getContent();
    }

    public function test_a_module_blinks_only_while_the_operator_wants_it()
    {
        $cm = $this->module();
        $this->assertSame('', $this->poll());

        $cm->update(['identify_until' => now()->addSeconds(30)]);
        $this->assertSame('identify', $this->poll());

        Carbon::setTestNow(now()->addSeconds(31));
        $this->assertSame('', $this->poll());
    }

    public function test_polls_are_not_logged_and_mark_the_module_on_the_bench_at_most_once_a_minute()
    {
        $this->module();

        $this->poll();
        $this->assertEquals(now(), Cm::first()->polled_at);

        Carbon::setTestNow(now()->addSeconds(30));
        $this->poll();
        $this->assertEquals(now()->subSeconds(30), Cm::first()->polled_at, 'no database write for every poll');

        Carbon::setTestNow(now()->addSeconds(31));
        $this->poll();
        $this->assertEquals(now(), Cm::first()->polled_at);
        $this->assertSame(0, Cmlog::count());
    }

    public function test_an_unknown_module_gets_an_empty_answer()
    {
        $this->assertSame('', $this->poll('1000000000000bad'));
    }

    public function test_a_new_run_forgets_the_bench_state()
    {
        $this->module(['polled_at' => now(), 'identify_until' => now()->addSeconds(20)]);

        $this->get('/scriptexecute?serial='.self::SERIAL.'&model=CM4&mac=e4:5f:01:62:9f:d3')->assertOk();

        $cm = Cm::first();
        $this->assertNull($cm->polled_at);
        $this->assertNull($cm->identify_until);
    }

    public function test_the_card_lets_the_operator_identify_a_finished_module_on_the_bench()
    {
        $this->module(['polled_at' => now()->subSeconds(10)]);

        Livewire::test('cm-card', ['serial' => self::SERIAL])
            ->assertSee('Identify')
            ->call('identify')
            ->assertSee('Blinking until');

        $this->assertEquals(now()->addSeconds(30), Cm::first()->identify_until);
    }

    public function test_identify_is_not_offered_for_a_module_that_does_not_ask()
    {
        $this->module(['polled_at' => null]);
        Livewire::test('cm-card', ['serial' => self::SERIAL])->assertDontSee('wire:click="identify"', false)
            ->call('identify');
        $this->assertNull(Cm::first()->identify_until, 'a module that does not poll would never see it');

        Cm::first()->update(['polled_at' => now()->subMinutes(5)]);
        Livewire::test('cm-card', ['serial' => self::SERIAL])->assertDontSee('wire:click="identify"', false);

        Cm::first()->update(['polled_at' => now(), 'phase' => 'write']);
        Livewire::test('cm-card', ['serial' => self::SERIAL])->assertDontSee('wire:click="identify"', false);
    }

    public function test_a_failed_module_can_be_identified_too()
    {
        $this->module(['phase' => 'failed', 'phase_detail' => 'Error during dd. Return code 1.', 'polled_at' => now()]);

        Livewire::test('cm-card', ['serial' => self::SERIAL])->call('identify');

        $this->assertNotNull(Cm::first()->identify_until);
    }

    public function test_the_dashboard_offers_identify_for_modules_on_the_bench()
    {
        $cm = $this->module(['polled_at' => now()->subSeconds(10)]);

        Livewire::test(ProvisioningStatus::class)
            ->assertSee('Identify')
            ->call('identify', $cm->id)
            ->assertSee('blinking');

        $this->assertEquals(now()->addSeconds(30), Cm::first()->identify_until);
    }

    public function test_the_operator_can_stop_the_blinking_on_the_card()
    {
        $this->module(['polled_at' => now(), 'identify_until' => now()->addSeconds(20)]);

        Livewire::test('cm-card', ['serial' => self::SERIAL])
            ->assertSee('wire:click="stopIdentify"', false)
            ->assertDontSee('wire:click="identify"', false)
            ->call('stopIdentify')
            ->assertDontSee('Blinking until')
            ->assertSee('wire:click="identify"', false);

        $this->assertNull(Cm::first()->identify_until);
        $this->assertSame('', $this->poll(), 'the module stops at its next question');
    }

    public function test_the_operator_can_stop_the_blinking_on_the_dashboard()
    {
        $cm = $this->module(['polled_at' => now(), 'identify_until' => now()->addSeconds(20)]);

        Livewire::test(ProvisioningStatus::class)
            ->assertSee('Stop')
            ->call('stopIdentify', $cm->id)
            ->assertDontSee('blinking');

        $this->assertNull(Cm::first()->identify_until);
    }

    public function test_the_card_page_with_identify_needs_a_login()
    {
        $this->module(['polled_at' => now()]);

        $this->get('/cms/'.self::SERIAL)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/cms/'.self::SERIAL)->assertOk()->assertSee('Identify');
    }
}
