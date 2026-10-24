<?php

namespace Tests\Feature;

use App\Http\Livewire\ProvisioningStatus;
use App\Models\Cm;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\Project;
use App\Models\Setting;
use App\Support\BatchSummary;
use App\Support\SlowRuns;
use App\Support\WallboardSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** A module that runs well past what the finished modules of its batch took (2.19.0) */
class SlowRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-24 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function batch()
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
        return NotificationBatch::create(['project_id' => $project->id, 'started_at' => now()->subHour(), 'last_event_at' => now()->subMinute()]);
    }

    protected $n = 0;

    protected function module($batch, $phase, array $attributes = [])
    {
        $n = ++$this->n;
        return Cm::create(array_merge(['serial' => sprintf('10000000a1f3c2%02x', $n), 'mac' => sprintf('e4:5f:01:a1:c2:%02x', $n),
            'phase' => $phase, 'notification_batch_id' => $batch->id, 'project_id' => $batch->project_id,
            'provisioning_started_at' => now()->subMinutes(10), 'phase_started_at' => now()->subMinutes(5),
            'progress_updated_at' => now()], $attributes));
    }

    /* a finished run of $seconds */
    protected function finished($batch, int $seconds, array $attributes = [])
    {
        return $this->module($batch, 'done', array_merge(['provisioning_started_at' => now()->subMinutes(40),
            'provisioning_complete_at' => now()->subMinutes(40)->addSeconds($seconds)], $attributes));
    }

    /* a run that has gone on for $seconds and reports */
    protected function running($batch, int $seconds, array $attributes = [])
    {
        return $this->module($batch, 'write', array_merge(['provisioning_started_at' => now()->subSeconds($seconds)], $attributes));
    }

    public function test_the_typical_time_is_the_median_of_the_finished_runs()
    {
        $batch = $this->batch();
        $this->finished($batch, 300);
        $this->finished($batch, 320);
        $this->finished($batch, 4000);   // one run that took more than an hour does not move it
        $this->assertSame(320, SlowRuns::typical($batch->cms()->get()));

        $this->finished($batch, 340);   // an even number: the middle two
        $this->assertSame(330, SlowRuns::typical($batch->cms()->get()));
    }

    public function test_with_fewer_than_three_finished_runs_there_is_nothing_to_compare_with()
    {
        $batch = $this->batch();
        $this->finished($batch, 300);
        $this->finished($batch, 300);
        $slow = $this->running($batch, 3600);
        $this->assertNull(SlowRuns::typical($batch->cms()->get()));
        $this->assertNull(SlowRuns::over($slow, null));
        $this->assertSame([], BatchSummary::current()->slow);
    }

    public function test_a_module_is_slow_past_one_and_a_half_times_the_typical_time_and_the_margin()
    {
        $batch = $this->batch();
        foreach ([300, 300, 300] as $seconds)
            $this->finished($batch, $seconds);
        // typical 300: the limit is 450 (1.5 times beats 300 plus the 2 minute margin)
        $this->assertNull(SlowRuns::over($this->running($batch, 450), 300), 'exactly at the limit is not past it');
        $this->assertSame(160, SlowRuns::over($this->running($batch, 460), 300));
        $this->assertNull(SlowRuns::over($this->running($batch, 200), 300));
    }

    public function test_a_fast_batch_needs_the_margin_and_a_slow_one_the_factor()
    {
        $batch = $this->batch();
        // a minute per module: 1.5 times is 90 s, but a module is not flagged for half a minute more than a minute: the limit is 3 minutes
        $this->assertNull(SlowRuns::over($this->running($batch, 170), 60));
        $this->assertSame(125, SlowRuns::over($this->running($batch, 185), 60));
        // a quarter of an hour: the margin of 2 minutes is 17 minutes, the factor 22.5 minutes
        $this->assertNull(SlowRuns::over($this->running($batch, 20 * 60), 900));
        $this->assertSame(1350 - 900 + 10, SlowRuns::over($this->running($batch, 1360), 900));
    }

    public function test_only_a_module_that_runs_and_reports_can_be_slow()
    {
        $batch = $this->batch();
        $silent = $this->running($batch, 3600, ['progress_updated_at' => now()->subMinutes(30)]);   // it stopped reporting: its own flag
        $failed = $this->module($batch, 'failed', ['provisioning_started_at' => now()->subHour()]);
        $done = $this->finished($batch, 3000);
        $pulled = $this->running($batch, 20 * 3600);   // started more than ACTIVE_HOURS ago: unplugged, not slow
        $this->assertNotNull($silent->silentFor());
        foreach ([$silent, $failed, $done, $pulled] as $cm)
            $this->assertNull(SlowRuns::over($cm, 300), $cm->phase);
    }

    public function test_a_board_set_up_over_usb_is_not_a_run_of_the_batch()
    {
        $batch = $this->batch();
        $this->finished($batch, 300);
        $this->finished($batch, 300);
        $this->finished($batch, 20, ['usb_prep_at' => now()->subHour()]);
        $this->assertNull(SlowRuns::typical($batch->cms()->get()), 'two real runs are not enough');
    }

    public function test_the_summary_names_the_slow_modules_of_the_batch()
    {
        $batch = $this->batch();
        foreach ([300, 310, 320] as $seconds)
            $this->finished($batch, $seconds);
        $fine = $this->running($batch, 200);
        $slow = $this->running($batch, 700);

        $summary = BatchSummary::current();
        $this->assertSame(310, $summary->typicalSeconds);
        $this->assertSame([$slow->id => 390], $summary->slow);
        $this->assertArrayNotHasKey($fine->id, $summary->slow);
        $this->assertSame('run', $summary->state, 'a slow module is not a failure: the tab does not turn red');
    }

    public function test_the_dashboard_flags_the_slow_module_and_counts_it()
    {
        $batch = $this->batch();
        foreach ([300, 310, 320] as $seconds)
            $this->finished($batch, $seconds);
        $this->running($batch, 200);
        $slow = $this->running($batch, 700);

        $html = Livewire::test(ProvisioningStatus::class)->assertSee('1 slower than the rest')->html();
        $this->assertSame(1, substr_count($html, 'Slow +6:30'));
        $this->assertStringContainsString('took 5:10 as a rule', $html);
        $this->assertMatchesRegularExpression('/'.preg_quote($slow->mac, '/').'.*?Slow \+6:30/s', $html);
    }

    public function test_the_dashboard_says_nothing_when_nothing_is_slow()
    {
        $batch = $this->batch();
        foreach ([300, 310, 320] as $seconds)
            $this->finished($batch, $seconds);
        $this->running($batch, 400);
        Livewire::test(ProvisioningStatus::class)->assertDontSee('Slow +')->assertDontSee('slower than the rest');
    }

    public function test_the_display_gets_the_seconds_past_the_typical_time_of_a_slow_module_only()
    {
        $batch = $this->batch();
        foreach ([300, 310, 320] as $seconds)
            $this->finished($batch, $seconds, ['polled_at' => now()]);
        $slow = $this->running($batch, 700, ['polled_at' => now()]);
        $fine = $this->running($batch, 200, ['polled_at' => now()]);

        $modules = array_column(app(WallboardSnapshot::class)->current()['modules'], null, 'serial');
        $this->assertSame(390, $modules[$slow->serial]['slow']);
        $this->assertNull($modules[$fine->serial]['slow']);
    }
}
