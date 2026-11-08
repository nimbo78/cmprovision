<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Services\NetworkChangeGate;
use App\Services\StationPower;
use App\Services\StationPowerControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tests\Support\FakeStationPowerControl;

class StationPowerTest extends TestCase
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
    private function active(int $id = 1): Cm
    {
        return Cm::create(['serial' => '100000000000000'.$id, 'mac' => 'e4:5f:01:00:00:0'.$id,
            'phase' => 'write', 'provisioning_started_at' => now()]);
    }
    public function test_prepare_never_schedules_and_idle_confirmation_schedules_once(): void
    {
        $power = app(StationPower::class);
        $ticket = $power->prepare('reboot', 'web:1:session-a');
        $this->assertSame('confirm', $ticket['stage']);
        $this->assertSame([], $this->control->calls);
        $result = $power->confirm($ticket['token'], 'web:1:session-a');
        $this->assertSame('scheduled', $result['stage']);
        $this->assertSame(['reboot'], $this->control->calls);
        $this->expectException(\RuntimeException::class);
        $power->confirm($ticket['token'], 'web:1:session-a');
    }
    public function test_active_modules_require_a_separate_interruption_confirmation(): void
    {
        $this->active();
        $power = app(StationPower::class);
        $ticket = $power->prepare('poweroff', 'fpms');
        $warning = $power->confirm($ticket['token'], 'fpms');
        $this->assertSame('interrupt', $warning['stage']);
        $this->assertSame(1, $warning['active_count']);
        $this->assertSame([], $this->control->calls);
        $this->assertSame('scheduled', $power->confirm($ticket['token'], 'fpms', true)['stage']);
        $this->assertSame(['poweroff'], $this->control->calls);
    }
    public function test_interrupt_flag_cannot_skip_the_first_confirmation(): void
    {
        $this->active();
        $power = app(StationPower::class);
        $ticket = $power->prepare('reboot', 'fpms');
        $this->assertSame('interrupt', $power->confirm($ticket['token'], 'fpms', true)['stage']);
        $this->assertSame([], $this->control->calls);
    }
    public function test_new_modules_after_the_warning_require_an_updated_confirmation(): void
    {
        $this->active();
        $power = app(StationPower::class);
        $ticket = $power->prepare('reboot', 'fpms');
        $power->confirm($ticket['token'], 'fpms');
        $this->active(2);
        $result = $power->confirm($ticket['token'], 'fpms', true);
        $this->assertSame('interrupt', $result['stage']);
        $this->assertSame(2, $result['active_count']);
        $this->assertSame([], $this->control->calls);
        $this->assertSame('scheduled', $power->confirm($ticket['token'], 'fpms', true)['stage']);
    }
    public function test_ticket_cannot_move_between_users_sessions_or_fpms(): void
    {
        $power = app(StationPower::class);
        foreach (['web:2:session-b', 'web:1:session-b', 'fpms'] as $other) {
            $ticket = $power->prepare('reboot', 'web:1:session-a');
            try { $power->confirm($ticket['token'], $other); $this->fail('Wrong actor accepted'); }
            catch (\RuntimeException $e) { $this->assertSame([], $this->control->calls); }
        }
    }
    public function test_cancel_and_expiry_do_not_schedule(): void
    {
        $power = app(StationPower::class);
        $ticket = $power->prepare('reboot', 'fpms');
        $power->cancel($ticket['token'], 'fpms');
        try { $power->confirm($ticket['token'], 'fpms'); $this->fail('Cancelled ticket accepted'); }
        catch (\RuntimeException $e) { $this->assertSame([], $this->control->calls); }
        $ticket = $power->prepare('reboot', 'fpms');
        $this->travel(121)->seconds();
        $this->expectException(\RuntimeException::class);
        $power->confirm($ticket['token'], 'fpms');
    }
    public function test_unknown_action_missing_backend_and_execution_failure_are_errors(): void
    {
        $power = app(StationPower::class);
        foreach (['restart nginx', 'halt', 'reboot; id'] as $action) {
            try { $power->prepare($action, 'fpms'); $this->fail('Unknown action accepted'); }
            catch (\InvalidArgumentException $e) { $this->assertSame([], $this->control->calls); }
        }
        $ticket = $power->prepare('reboot', 'fpms');
        $this->control->refuse = true;
        try { $power->confirm($ticket['token'], 'fpms'); $this->fail('Controller failure ignored'); }
        catch (\RuntimeException $e) { $this->assertSame([], $this->control->calls); }
        $this->control->installed = false;
        $this->expectException(\RuntimeException::class);
        $power->prepare('reboot', 'fpms');
    }
    public function test_scheduled_power_rejects_new_module_starts_without_creating_a_run(): void
    {
        $this->control->scheduled = ['action' => 'poweroff'];
        $this->get('/scriptexecute?serial=1000000000000001&model=CM4&mac=e4:5f:01:00:00:01')
            ->assertStatus(503)->assertHeader('Retry-After', '10');
        $this->assertSame(0, Cm::count());
    }
}
