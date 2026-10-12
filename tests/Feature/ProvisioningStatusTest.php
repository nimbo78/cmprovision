<?php

namespace Tests\Feature;

use App\Http\Livewire\ProvisioningStatus;
use App\Models\Cm;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\Project;
use App\Models\Setting;
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

        Livewire::test(ProvisioningStatus::class)
            ->assertSee('No report for 3 min')
            ->assertDontSee(' left ')          // no speed or remaining time from stale numbers
            ->assertDontSee('MB/s');
    }

    public function test_a_failed_module_shows_how_long_it_ran()
    {
        $this->module('1000000000000a08', ['phase' => 'failed', 'phase_detail' => 'Missing eMMC/SD card.',
            'provisioning_started_at' => now()->subMinutes(10), 'phase_started_at' => now()->subMinutes(8)]);

        Livewire::test(ProvisioningStatus::class)->assertSee('failed after 2:00');
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
                                           'phase_started_at' => now()->subMinutes(10),
                                           'progress_bytes' => 24, 'progress_total' => 100]);
        $this->module('1000000000000a05', ['phase' => 'done', 'phase_started_at' => now()->subMinutes(20),
                                           'provisioning_complete_at' => now()->subMinutes(20)]);
        $this->module('1000000000000a06', ['phase' => 'done', 'phase_started_at' => now()->subHours(5),
                                           'provisioning_started_at' => now()->subHours(5),
                                           'provisioning_complete_at' => now()->subHours(5)]);

        Livewire::test(ProvisioningStatus::class)
            ->assertSee('Error during dd. Return code 1.')
            ->assertSee('24%')
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

    public function test_modules_are_listed_in_port_order_so_rows_stay_in_place()
    {
        $this->module('1000000000000b10', ['provisioning_board' => 'GE0/0/10', 'phase' => 'write',
                                           'phase_started_at' => now(), 'progress_updated_at' => now()]);
        $this->module('1000000000000b02', ['provisioning_board' => 'GE0/0/2', 'phase' => 'done',
                                           'phase_started_at' => now(), 'provisioning_complete_at' => now()]);
        $this->module('1000000000000b03', ['provisioning_board' => 'GE0/0/3', 'phase' => 'failed',
                                           'phase_started_at' => now()]);

        Livewire::test(ProvisioningStatus::class)->assertSeeInOrder(['GE0/0/2', 'GE0/0/3', 'GE0/0/10']);
    }

    protected function openBatch()
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return NotificationBatch::create(['project_id' => $project->id, 'started_at' => now()->subHours(4), 'last_event_at' => now()->subMinute()]);
    }

    public function test_the_summary_counts_the_open_batch()
    {
        $batch = $this->openBatch();
        $this->module('1000000000000c01', ['phase' => 'done', 'notification_batch_id' => $batch->id, 'phase_started_at' => now()->subHours(3),
                                           'provisioning_started_at' => now()->subHours(3)->subMinutes(5), 'provisioning_complete_at' => now()->subHours(3)]);
        $this->module('1000000000000c02', ['phase' => 'failed', 'notification_batch_id' => $batch->id, 'phase_started_at' => now()]);
        $this->module('1000000000000c03', ['phase' => 'write', 'notification_batch_id' => $batch->id, 'phase_started_at' => now(), 'progress_updated_at' => now()]);

        Livewire::test(ProvisioningStatus::class)
            ->assertSeeHtml('<div class="num text-info">1</div>')
            ->assertSeeHtml('<div class="num text-pos">1</div>')
            ->assertSeeHtml('<div class="num text-neg">1</div>')
            ->assertSee('Project 78 · batch since')
            ->assertSee('3 modules · 5:00 per module')
            ->assertSee('1000000000000c01');   // a module of the batch stays, however long ago it finished
    }

    public function test_new_batch_closes_the_open_one()
    {
        $batch = $this->openBatch();
        Livewire::test(ProvisioningStatus::class)->assertSeeHtml('wire:confirm=')->call('newBatch');
        $this->assertNotNull($batch->fresh()->closed_at);
    }

    public function test_after_the_batch_the_last_one_is_shown_as_such()
    {
        $batch = $this->openBatch();
        $this->module('1000000000000c04', ['phase' => 'done', 'notification_batch_id' => $batch->id, 'phase_started_at' => now()->subHours(5),
                                           'provisioning_complete_at' => now()->subHours(5)]);
        NotificationBatch::startNew();

        Livewire::test(ProvisioningStatus::class)
            ->assertSee('Last batch, closed at')
            ->assertSee('1000000000000c04');
    }

    public function test_a_module_running_in_another_batch_is_listed_too()
    {
        $batch = $this->openBatch();
        // closed by New batch while this module still ran
        $other = NotificationBatch::create(['project_id' => $batch->project_id, 'started_at' => now()->subHour(), 'last_event_at' => now(), 'closed_at' => now()]);
        $this->module('1000000000000c05', ['phase' => 'verify', 'notification_batch_id' => $other->id, 'phase_started_at' => now(), 'progress_updated_at' => now()]);

        Livewire::test(ProvisioningStatus::class)->assertSee('1000000000000c05');
    }

    public function test_nothing_to_show_says_so()
    {
        Livewire::test(ProvisioningStatus::class)->assertSee('No modules are being provisioned');
    }
}
