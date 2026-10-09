<?php

namespace Tests\Feature;

use App\Http\Livewire\ProvisioningStatus;
use App\Models\Cm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** The dashboard shows every module being provisioned: phase, progress bar, speed and remaining time. */
class ProvisioningStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-09 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function module($serial, array $attributes)
    {
        $cm = Cm::create(array_merge([
            'serial' => $serial, 'mac' => 'e4:5f:01:00:00:'.substr($serial, -2),
            'provisioning_started_at' => now()->subMinutes(5),
        ], $attributes));
        $cm->forceFill($attributes)->save();
        return $cm;
    }

    public function test_writing_module_shows_percent_speed_and_remaining_time()
    {
        $mib = 1024 * 1024;
        $this->module('1000000000000a01', [
            'provisioning_board' => 'GE0/0/5',
            'phase' => 'write', 'phase_started_at' => now()->subSeconds(50), 'progress_updated_at' => now(),
            'progress_bytes' => 250 * $mib, 'progress_total' => 1000 * $mib,
        ]);

        Livewire::test(ProvisioningStatus::class)
            ->assertSee('1000000000000a01')
            ->assertSee('GE0/0/5')
            ->assertSee('Writing image')
            ->assertSee('25%')
            ->assertSee('250 MB of 1000 MB')
            ->assertSee('5.0 MB/s')
            ->assertSee('2:30 left');
    }

    public function test_a_module_that_stopped_reporting_is_flagged()
    {
        $this->module('1000000000000a02', [
            'phase' => 'write', 'phase_started_at' => now()->subMinutes(4), 'progress_updated_at' => now()->subMinutes(3),
            'progress_bytes' => 1, 'progress_total' => 100,
        ]);

        Livewire::test(ProvisioningStatus::class)->assertSee('No report for 3 min');
    }

    public function test_scripts_may_run_quietly_for_a_while_without_being_flagged()
    {
        $this->module('1000000000000a03', [
            'phase' => 'postinstall', 'phase_detail' => 'Resize ext4 partition',
            'phase_started_at' => now()->subMinutes(3), 'progress_updated_at' => now()->subMinutes(3),
        ]);

        Livewire::test(ProvisioningStatus::class)
            ->assertSee('Post-install: Resize ext4 partition')
            ->assertDontSee('No report');
    }

    public function test_failed_and_finished_modules_stay_visible_for_a_while()
    {
        $this->module('1000000000000a04', ['phase' => 'failed', 'phase_detail' => 'Error during dd. Return code 1.',
                                           'phase_started_at' => now()->subMinutes(10)]);
        $this->module('1000000000000a05', ['phase' => 'done', 'phase_started_at' => now()->subMinutes(20),
                                           'provisioning_complete_at' => now()->subMinutes(20)]);
        $this->module('1000000000000a06', ['phase' => 'done', 'phase_started_at' => now()->subHours(5),
                                           'provisioning_started_at' => now()->subHours(5),
                                           'provisioning_complete_at' => now()->subHours(5)]);

        Livewire::test(ProvisioningStatus::class)
            ->assertSee('Error during dd. Return code 1.')
            ->assertSee('1000000000000a05')
            ->assertSee('Done')
            ->assertDontSee('1000000000000a06');
    }

    public function test_the_dashboard_embeds_the_status_panel()
    {
        $this->module('1000000000000a07', ['phase' => 'verify', 'phase_started_at' => now()->subSeconds(10),
                                           'progress_updated_at' => now(), 'progress_bytes' => 0, 'progress_total' => 100]);

        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertOk()->assertSee('1000000000000a07')->assertSee('Verifying');
    }

    public function test_nothing_to_show_says_so()
    {
        Livewire::test(ProvisioningStatus::class)->assertSee('No modules are being provisioned');
    }
}
