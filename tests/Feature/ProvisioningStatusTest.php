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
use Tests\Support\PoeStand;
use Tests\TestCase;

/** The dashboard shows every module being provisioned: phase, progress bar, speed and remaining time. */
class ProvisioningStatusTest extends TestCase
{
    use RefreshDatabase, PoeStand;

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
            ->assertSee('e4:5f:01:00:00:01')
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
            ->assertSee('e4:5f:01:00:00:05')
            ->assertSee('Done')
            ->assertDontSee('e4:5f:01:00:00:06');
    }

    public function test_the_dashboard_embeds_the_status_panel()
    {
        $this->module('1000000000000a07', ['phase' => 'verify', 'phase_started_at' => now()->subSeconds(10),
                                           'progress_updated_at' => now(), 'progress_bytes' => 0, 'progress_total' => 100]);

        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertOk()->assertSee('e4:5f:01:00:00:07')->assertSee('Verifying');
    }

    public function test_modules_are_listed_running_first_then_by_the_time_they_were_flashed_the_latest_on_top()
    {
        // the board column says nothing about the order: the ports are shuffled on purpose
        $this->module('1000000000000b01', ['provisioning_board' => 'GE0/0/1', 'phase' => 'done', 'provisioning_started_at' => now()->subMinutes(60),
                                           'phase_started_at' => now()->subMinutes(30), 'provisioning_complete_at' => now()->subMinutes(30)]);
        $this->module('1000000000000b02', ['provisioning_board' => 'GE0/0/2', 'phase' => 'failed', 'provisioning_started_at' => now()->subMinutes(50),
                                           'phase_started_at' => now()->subMinutes(10)]);
        $this->module('1000000000000b03', ['provisioning_board' => 'GE0/0/3', 'phase' => 'done', 'provisioning_started_at' => now()->subMinutes(70),
                                           'phase_started_at' => now()->subMinutes(55), 'provisioning_complete_at' => now()->subMinutes(55)]);
        $this->module('1000000000000b04', ['provisioning_board' => 'GE0/0/4', 'phase' => 'write', 'provisioning_started_at' => now()->subMinutes(8),
                                           'phase_started_at' => now()->subMinutes(7), 'progress_updated_at' => now()]);
        $this->module('1000000000000b05', ['provisioning_board' => 'GE0/0/5', 'phase' => 'write', 'provisioning_started_at' => now()->subMinutes(2),
                                           'phase_started_at' => now()->subMinutes(1), 'progress_updated_at' => now()]);

        // b05 and b04 run (the later start first); then the failure 10 minutes ago, the done ones 30 and 55 minutes ago
        Livewire::test(ProvisioningStatus::class)
            ->assertSeeInOrder(['e4:5f:01:00:00:05', 'e4:5f:01:00:00:04', 'e4:5f:01:00:00:02', 'e4:5f:01:00:00:01', 'e4:5f:01:00:00:03']);
    }

    public function test_a_module_that_finishes_leaves_the_running_ones_and_stands_first_among_the_finished()
    {
        $this->module('1000000000000c01', ['phase' => 'done', 'provisioning_started_at' => now()->subMinutes(40),
                                           'phase_started_at' => now()->subMinutes(20), 'provisioning_complete_at' => now()->subMinutes(20)]);
        $this->module('1000000000000c02', ['phase' => 'write', 'provisioning_started_at' => now()->subMinutes(9),
                                           'phase_started_at' => now()->subMinutes(8), 'progress_updated_at' => now()]);
        $b = $this->module('1000000000000c03', ['phase' => 'write', 'provisioning_started_at' => now()->subMinutes(3),
                                                'phase_started_at' => now()->subMinutes(2), 'progress_updated_at' => now()]);
        $page = Livewire::test(ProvisioningStatus::class)->assertSeeInOrder(['e4:5f:01:00:00:03', 'e4:5f:01:00:00:02', 'e4:5f:01:00:00:01']);

        // c03 finishes: it leaves the running c02 behind it and takes the first place among the finished
        $b->update(['phase' => 'done', 'phase_started_at' => now(), 'provisioning_complete_at' => now()]);
        $page->call('$refresh')->assertSeeInOrder(['e4:5f:01:00:00:02', 'e4:5f:01:00:00:03', 'e4:5f:01:00:00:01']);
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

    public function test_the_usb_prep_tile_ends_the_row_of_the_summary_tiles_of_an_open_batch()
    {
        $batch = $this->openBatch();
        $this->module('1000000000000d01', ['phase' => 'write', 'notification_batch_id' => $batch->id, 'phase_started_at' => now(), 'progress_updated_at' => now()]);

        $html = Livewire::test(ProvisioningStatus::class)->assertSeeLivewire(\App\Http\Livewire\UsbPrepPanel::class)->html();
        $this->assertStringContainsString('min-[1440px]:grid-cols-[repeat(3,minmax(0,160px))_minmax(0,1fr)_minmax(0,320px)]', $html);
        $this->assertStringContainsString('Start USB prep', $html);
        $this->assertLessThan(strpos($html, 'Start USB prep'), strpos($html, 'batch since'), 'after the batch tile');
    }

    public function test_restart_is_offered_by_the_write_access_of_the_module_s_switch()
    {
        $this->stand(['snmp_write_community' => 'private']);                 // sw01 may write
        $this->secondSwitch(['write_community' => '']);                      // sw02 may not
        $batch = $this->openBatch();
        $one = Cm::where('serial', '10000000e611110c')->first();
        $one->update(['switch_id' => 1, 'notification_batch_id' => $batch->id]);
        $two = Cm::where('serial', '10000000e611110e')->first();
        $two->update(['switch_id' => 2, 'notification_batch_id' => $batch->id]);

        $html = Livewire::test(ProvisioningStatus::class)->html();

        $this->assertStringContainsString('restart('.$one->id.')', $html);
        $this->assertStringNotContainsString('restart('.$two->id.')', $html);
        $this->assertStringContainsString('Restart failed (1)', $html);
    }

    public function test_a_stand_of_two_24_port_switches_fits_the_panel()
    {
        $batch = $this->openBatch();
        for ($i = 1; $i <= 60; $i++)
            Cm::create(['serial' => sprintf('1000000000%06d', $i), 'mac' => sprintf('e4:5f:01:00:%02x:%02x', intdiv($i, 256), $i % 256),
                'phase' => 'write', 'provisioning_started_at' => now()->subMinutes($i), 'notification_batch_id' => $batch->id]);

        $html = Livewire::test(ProvisioningStatus::class)->html();
        $this->assertStringContainsString('e4:5f:01:00:00:3c', $html, 'the first module of 60 is not cut off');
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
            ->assertSee('e4:5f:01:00:00:01');   // a module of the batch stays, however long ago it finished
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
            ->assertSee('e4:5f:01:00:00:04');
    }

    public function test_a_module_running_in_another_batch_is_listed_too()
    {
        $batch = $this->openBatch();
        // closed by New batch while this module still ran
        $other = NotificationBatch::create(['project_id' => $batch->project_id, 'started_at' => now()->subHour(), 'last_event_at' => now(), 'closed_at' => now()]);
        $this->module('1000000000000c05', ['phase' => 'verify', 'notification_batch_id' => $other->id, 'phase_started_at' => now(), 'progress_updated_at' => now()]);

        Livewire::test(ProvisioningStatus::class)->assertSee('e4:5f:01:00:00:05');
    }

    public function test_a_long_failure_reason_wraps_and_a_narrow_table_scrolls()
    {
        // one line of the reason no longer widens the table past Progress, Time and Identify
        $reason = 'Error during dd. Return code 1. Diagnosis: the connection to the provisioning server was closed before the whole image arrived. Check the cable, the switch port and the server log.';
        $this->module('1000000000000a09', ['phase' => 'failed', 'phase_detail' => $reason, 'phase_started_at' => now()]);

        Livewire::test(ProvisioningStatus::class)
            ->assertSeeHtml('<div class="line-clamp-2" title="Failed: '.e($reason).'">')
            ->assertSeeHtml('<div class="cmp-card cmp-card-scroll">');
    }

    public function test_the_failed_tile_is_highlighted_only_when_a_module_failed()
    {
        $batch = $this->openBatch();
        $this->module('1000000000000c06', ['phase' => 'write', 'notification_batch_id' => $batch->id, 'phase_started_at' => now(), 'progress_updated_at' => now()]);
        Livewire::test(ProvisioningStatus::class)->assertDontSeeHtml('cmp-tile-fail');

        $this->module('1000000000000c07', ['phase' => 'failed', 'notification_batch_id' => $batch->id, 'phase_started_at' => now()]);
        Livewire::test(ProvisioningStatus::class)->assertSeeHtml('<div class="cmp-tile cmp-tile-fail"><div class="num text-neg">1</div>');
    }

    public function test_a_module_without_a_report_is_named_in_the_batch_card_and_not_counted_as_running()
    {
        $batch = $this->openBatch();
        $this->module('1000000000000c08', ['phase' => 'write', 'notification_batch_id' => $batch->id,
                                           'phase_started_at' => now()->subMinutes(4), 'progress_updated_at' => now()->subMinutes(3)]);

        Livewire::test(ProvisioningStatus::class)
            ->assertSeeHtml('<div class="num text-info">0</div>')
            ->assertSeeHtml('<span class="text-warn">1 no report</span>');
    }

    public function test_a_last_batch_closed_on_another_day_says_the_day()
    {
        config(['app.display_timezone' => 'UTC']);
        $batch = $this->openBatch();
        $batch->forceFill(['closed_at' => now()->subDay()->setTime(13, 22)])->save();
        Livewire::test(ProvisioningStatus::class)->assertSee('Last batch, closed on 08.10 at 13:22');
    }

    public function test_finished_modules_of_an_older_batch_are_not_listed()
    {
        $batch = $this->openBatch();
        $old = NotificationBatch::create(['project_id' => $batch->project_id, 'started_at' => now()->subHours(3), 'last_event_at' => now()->subHours(2),
                                          'closed_at' => now()->subHours(2)]);
        $this->module('1000000000000c09', ['phase' => 'done', 'notification_batch_id' => $old->id, 'phase_started_at' => now()->subMinutes(30),
                                           'provisioning_complete_at' => now()->subMinutes(30)]);
        $this->module('1000000000000c10', ['phase' => 'done', 'notification_batch_id' => $batch->id, 'phase_started_at' => now()->subMinutes(20),
                                           'provisioning_complete_at' => now()->subMinutes(20)]);

        Livewire::test(ProvisioningStatus::class)->assertSee('e4:5f:01:00:00:10')->assertDontSee('e4:5f:01:00:00:09');
    }

    public function test_nothing_to_show_says_so()
    {
        Livewire::test(ProvisioningStatus::class)->assertSee('No modules are being provisioned');
    }
}
