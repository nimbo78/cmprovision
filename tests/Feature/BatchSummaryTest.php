<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\Project;
use App\Models\Setting;
use App\Support\BatchSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The batch the stand works on (the one the bots report) and what the top bar, the tab and the dashboard say of it */
class BatchSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 07:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function project($name = '78', $active = true)
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => $name, 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false]);
        if ($active)
            Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    protected function batch($project, array $attributes = [])
    {
        return NotificationBatch::create(array_merge(['project_id' => $project->id, 'started_at' => now()->subMinutes(38),
            'last_event_at' => now()->subMinute()], $attributes));
    }

    protected function module($batch, $n, $phase, array $attributes = [])
    {
        return Cm::create(array_merge(['serial' => sprintf('10000000a1f3c2%02x', $n), 'mac' => sprintf('e4:5f:01:a1:c2:%02x', $n),
            'phase' => $phase, 'notification_batch_id' => $batch->id, 'project_id' => $batch->project_id,
            'provisioning_started_at' => now()->subMinutes(10), 'phase_started_at' => now()->subMinutes(5)], $attributes));
    }

    public function test_the_open_batch_counts_running_done_and_failed_modules()
    {
        $batch = $this->batch($this->project());
        $this->module($batch, 1, 'done', ['provisioning_started_at' => now()->subMinutes(20), 'provisioning_complete_at' => now()->subMinutes(15)]);
        $this->module($batch, 2, 'done', ['provisioning_started_at' => now()->subMinutes(12), 'provisioning_complete_at' => now()->subMinutes(6)]);
        $this->module($batch, 3, 'failed');
        $this->module($batch, 4, 'write', ['progress_bytes' => 300, 'progress_total' => 900,
            'phase_started_at' => now()->subSeconds(100), 'progress_updated_at' => now()]);

        $s = BatchSummary::current();
        $this->assertTrue($s->open);
        $this->assertSame('78', $s->project->name);
        $this->assertSame(['active' => 1, 'done' => 2, 'failed' => 1], $s->counts);
        $this->assertSame(330, $s->averageSeconds);   // 5:00 and 6:00
        $this->assertSame(200, $s->leftSeconds);      // 600 bytes at 3 bytes/s
        $this->assertSame('fail', $s->state);
        $this->assertSame('1 failed · 1 running', $s->title());
    }

    public function test_states_and_titles()
    {
        $batch = $this->batch($this->project());
        $this->module($batch, 1, 'done', ['provisioning_complete_at' => now()]);
        $this->assertSame(['done', '✓ 1 done'], [BatchSummary::current()->state, BatchSummary::current()->title()]);

        $running = $this->module($batch, 2, 'verify');
        $this->assertSame(['run', '1 running'], [BatchSummary::current()->state, BatchSummary::current()->title()]);

        $running->update(['phase' => 'failed']);
        $this->assertSame(['fail', '1 failed · 1 done'], [BatchSummary::current()->state, BatchSummary::current()->title()]);
    }

    public function test_a_module_without_a_report_is_told_apart_and_left_out_of_the_estimate()
    {
        $batch = $this->batch($this->project());
        $this->module($batch, 1, 'write', ['progress_bytes' => 300, 'progress_total' => 900,
            'phase_started_at' => now()->subSeconds(100), 'progress_updated_at' => now()]);
        // pulled out mid-write five minutes ago: its estimate would stay at 800 s for good
        $this->module($batch, 2, 'write', ['progress_bytes' => 100, 'progress_total' => 900,
            'phase_started_at' => now()->subSeconds(400), 'progress_updated_at' => now()->subSeconds(300)]);

        $s = BatchSummary::current();
        $this->assertSame(['active' => 2, 'done' => 0, 'failed' => 0], $s->counts);   // as the bots count
        $this->assertSame(1, $s->silent);
        $this->assertSame(200, $s->leftSeconds);
        $this->assertSame(['stale', '1 no report · 1 running'], [$s->state, $s->title()]);
    }

    public function test_a_failure_outweighs_a_module_without_a_report()
    {
        $batch = $this->batch($this->project());
        $this->module($batch, 1, 'failed');
        $this->module($batch, 2, 'postinstall', ['phase_started_at' => now()->subMinutes(30), 'progress_updated_at' => now()->subMinutes(20)]);
        $this->module($batch, 3, 'done', ['provisioning_complete_at' => now()]);

        $s = BatchSummary::current();
        $this->assertSame(['fail', '1 failed · 1 no report · 1 done'], [$s->state, $s->title()]);
    }

    public function test_a_batch_without_modules_is_idle()
    {
        $this->batch($this->project());
        $s = BatchSummary::current();
        $this->assertSame(['idle', '', null, null], [$s->state, $s->title(), $s->averageSeconds, $s->leftSeconds]);
    }

    public function test_an_idle_batch_is_not_open_any_more()
    {
        $this->batch($this->project(), ['last_event_at' => now()->subHours(NotificationBatch::IDLE_HOURS)->subMinute()]);
        $this->assertNull(NotificationBatch::open());
        $this->assertNull(BatchSummary::current());
        $this->assertFalse(BatchSummary::forDashboard()->open, 'the dashboard still shows it, as the last batch');
    }

    public function test_a_batch_of_another_project_than_the_active_one_is_not_open()
    {
        $batch = $this->batch($this->project('stage1-test', false));
        $this->project('78');
        $this->assertNull(NotificationBatch::open());
        $this->assertSame($batch->id, NotificationBatch::lastClosed()->id);
    }

    public function test_without_an_active_project_nothing_is_open()
    {
        $this->batch($this->project('78', false));
        $this->assertNull(NotificationBatch::open());
    }

    public function test_a_batch_closed_by_hand_is_not_open_and_nothing_is_created()
    {
        $batch = $this->batch($this->project());
        NotificationBatch::startNew();
        $this->assertNull(NotificationBatch::open());
        $this->assertSame($batch->id, NotificationBatch::lastClosed()->id);
        $this->assertSame(1, NotificationBatch::count());
    }

    public function test_no_batch_at_all()
    {
        $this->assertNull(BatchSummary::current());
        $this->assertNull(BatchSummary::forDashboard());
        $this->assertNull(NotificationBatch::lastClosed());
    }
}
