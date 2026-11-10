<?php

namespace Tests\Feature;

use App\Http\Livewire\StationPower;
use App\Models\Cm;
use App\Models\User;
use App\Services\NetworkChangeGate;
use App\Services\StationPowerControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\FakeStationPowerControl;
use Tests\TestCase;

class StationPowerPagesTest extends TestCase
{
    use RefreshDatabase;
    private FakeStationPowerControl $control;
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->app->instance(StationPowerControl::class, $this->control = new FakeStationPowerControl);
        $this->app->instance(NetworkChangeGate::class, new NetworkChangeGate(false));
    }
    private function login(bool $confirmed = true): void
    {
        $this->actingAs(User::factory()->create());
        if ($confirmed) $this->withSession(['auth.password_confirmed_at' => time()]);
    }
    public function test_status_requires_login_and_never_schedules(): void
    {
        $this->getJson('/settings/power/status')->assertUnauthorized();
        $this->login();
        $this->getJson('/settings/power/status')->assertOk()->assertJson(['boot_id' => $this->control->bootId(), 'pending' => null]);
        $this->assertSame([], $this->control->calls);
    }
    public function test_anonymous_and_unconfirmed_direct_component_calls_are_forbidden(): void
    {
        Livewire::test(StationPower::class)->call('begin', 'reboot')->assertForbidden();
        $this->login(false);
        Livewire::test(StationPower::class)->call('begin', 'reboot')->assertForbidden();
        $this->assertSame([], $this->control->calls);
    }
    public function test_system_has_controls_and_other_settings_tabs_do_not_duplicate_them(): void
    {
        $this->login();
        $this->get('/settings?tab=system')->assertOk()->assertSee('Station power')->assertSee('Restart station')->assertSee('Shut down station');
        $this->get('/settings?tab=status')->assertOk()->assertDontSee('Restart station');
        $this->get('/settings?tab=network')->assertOk()->assertDontSee('Restart station');
    }
    public function test_idle_action_requires_confirmation_and_emits_reconnect_state(): void
    {
        $this->login();
        $page = Livewire::test(StationPower::class)->call('begin', 'reboot')->assertSet('open', true);
        $this->assertSame([], $this->control->calls);
        $page->call('confirm')->assertSet('open', false)->assertDispatched('station-power-scheduled');
        $this->assertSame(['reboot'], $this->control->calls);
    }
    public function test_active_run_displays_a_separate_warning_before_execution(): void
    {
        Cm::create(['serial' => '1000000000000001', 'mac' => 'e4:5f:01:00:00:01', 'phase' => 'write', 'provisioning_started_at' => now()]);
        $this->login();
        $page = Livewire::test(StationPower::class)->call('begin', 'poweroff')->call('confirm')
            ->assertSet('confirmation.stage', 'interrupt')->assertSee('Interrupt provisioning?');
        $this->assertSame([], $this->control->calls);
        $page->call('confirm')->assertDispatched('station-power-scheduled');
        $this->assertSame(['poweroff'], $this->control->calls);
    }
    public function test_cancel_clears_the_ticket_and_direct_confirmation_cannot_execute(): void
    {
        $this->login();
        Livewire::test(StationPower::class)->call('begin', 'poweroff')->call('cancel')->call('confirm')->assertForbidden();
        $this->assertSame([], $this->control->calls);
    }
}
