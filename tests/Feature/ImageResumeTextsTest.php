<?php

namespace Tests\Feature;

use App\Http\Livewire\CmCard;
use App\Http\Livewire\ProvisioningStatus;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\Switch\PoeRestart;
use App\Support\FailureAdvice;
use App\Support\RunTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\SwitchRow;
use Tests\TestCase;

/** 2.6.1: what the card, the steps, the advice and the restart dialogs say about a write that would go on */
class ImageResumeTextsTest extends TestCase
{
    use RefreshDatabase;

    protected $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 10:00:00');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file)
            if ($file && is_file($file))
                unlink($file);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A module whose image write failed at 3.5 GB of 6.2 GB, in an active project with the checkbox and a ready copy:
        the next start goes on from piece 6 (3.2 GB) */
    protected function resumable(array $attributes = [])
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'texts-'.uniqid().'.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 6200000000;
        $image->split_filename = 'pieces-'.uniqid().'.gz';
        $image->split_offsets = json_encode(range(0, 130, 10));   // the first MiB and 12 pieces of 512 MiB
        $image->split_piece_mb = 512;
        $image->save();
        $this->files[] = $image->splitPath();
        file_put_contents($image->splitPath(), str_repeat('x', 130));
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => true, 'os_check' => false, 'split_image' => true]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return Cm::create(array_merge(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'project_id' => $project->id,
            'phase' => 'failed', 'phase_detail' => 'Error during dd. Return code 1.', 'provisioning_started_at' => now()->subMinutes(30),
            'progress_total' => 6200000000, 'progress_bytes' => 3500000000, 'durable_bytes' => 3500000000, 'durable_image_id' => $image->id,
            'durable_at' => now()->subMinutes(10),
            'timeline' => [['phase' => 'write', 'detail' => null, 'at' => now()->getTimestamp() - 600],
                           ['phase' => 'failed', 'detail' => 'Error during dd. Return code 1.', 'at' => now()->getTimestamp()]]], $attributes));
    }

    public function test_the_card_offers_to_start_over_a_module_that_would_go_on()
    {
        $cm = $this->resumable();
        $this->actingAs(User::factory()->create(['name' => 'igor']));

        Livewire::test(CmCard::class, ['serial' => $cm->serial])
            ->assertSee('the next start continues from 3.2 GB of 6.2 GB')
            ->call('startOver')
            ->assertDontSee('the next start continues from');

        $this->assertNull($cm->fresh()->durable_bytes);
        $this->assertTrue(Cmlog::where('cm', $cm->mac)
            ->where('msg', 'Start over: the next start writes the image from the beginning (by igor).')->exists());
    }

    public function test_a_module_that_writes_normally_shows_no_resume_line()
    {
        $cm = $this->resumable(['phase' => 'write', 'phase_detail' => null, 'progress_updated_at' => now(),
            'timeline' => [['phase' => 'write', 'detail' => null, 'at' => now()->getTimestamp() - 60]]]);

        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertDontSee('the next start continues from');
    }

    public function test_the_advice_for_a_write_that_would_go_on()
    {
        $cm = $this->resumable();
        $this->assertStringEndsWith('Restart it (power cycle): it continues the write from 3.2 GB of 6.2 GB.', FailureAdvice::for($cm)['advice']);

        unlink(Image::first()->splitPath());
        $this->assertStringEndsWith(FailureAdvice::RESTART, FailureAdvice::for($cm->fresh())['advice'], 'without the copy it starts over');
    }

    public function test_the_advice_when_the_data_was_damaged()
    {
        $reason = 'Error during dd. Return code 1. Diagnosis: the decompressor stopped accepting data; see its message below.';
        $cm = $this->resumable(['phase_detail' => $reason, 'timeline' => [['phase' => 'write', 'detail' => null, 'at' => now()->getTimestamp() - 600],
            ['phase' => 'failed', 'detail' => $reason, 'at' => now()->getTimestamp()]]]);

        $this->assertStringEndsWith('it continues the write from 3.2 GB of 6.2 GB. If it stops at the same place again, the pieces copy on the '
            .'server is damaged: untick Split the image into pieces in the project, remove the pieces on the Images page and tick it again.',
            FailureAdvice::for($cm)['advice']);
    }

    public function test_the_dashboard_looks_up_the_active_project_once_for_all_its_rows()
    {
        $cm = $this->resumable();
        SwitchRow::put(['ip' => '192.0.2.10', 'snmp_community' => 'public', 'snmp_write_community' => 'private']);
        $batch = NotificationBatch::create(['project_id' => $cm->project_id, 'started_at' => now()->subMinutes(30), 'last_event_at' => now()]);
        $cm->update(['notification_batch_id' => $batch->id]);
        $settings = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ProvisioningStatus::class)->assertSee('Restart failed');
            DB::disableQueryLog();
            return count(array_filter(DB::getQueryLog(), function ($q) { return str_contains($q['query'], '"settings"'); }));
        };
        $one = $settings();
        foreach (range(1, 4) as $i)
            Cm::create(['serial' => '10000000e61111a'.$i, 'mac' => 'e4:5f:01:62:9f:a'.$i, 'project_id' => $cm->project_id,
                'phase' => 'failed', 'phase_detail' => 'Error during dd. Return code 1.', 'notification_batch_id' => $batch->id,
                'provisioning_started_at' => now()->subMinutes(30), 'phase_started_at' => now()->subMinute()]);

        $this->assertSame($one, $settings(), 'not once more for every row with a Restart');
    }

    public function test_the_advice_for_a_silent_module_that_would_go_on()
    {
        $cm = $this->resumable(['phase' => 'write', 'phase_detail' => null, 'progress_updated_at' => now()->subMinutes(5),
            'timeline' => [['phase' => 'write', 'detail' => null, 'at' => now()->getTimestamp() - 600]]]);

        $this->assertStringEndsWith('then restart it; it continues the write from 3.2 GB of 6.2 GB.', FailureAdvice::for($cm)['advice']);
    }

    public function test_the_restart_dialogs_say_the_write_goes_on()
    {
        $cm = $this->resumable();

        $this->assertStringEndsWith('the module boots from the network and continues the write from 3.2 GB of 6.2 GB.',
            PoeRestart::confirm($cm, 'sw01 Gi0/1'));
        $this->assertSame('Restart 3 modules (failed or without a report)? PoE goes off for 5 s on each port. '
            .'For 2 of them the write goes on where it stopped.', PoeRestart::confirmFailed(3, 2));
        $this->assertSame('Restart 3 modules (failed or without a report)? PoE goes off for 5 s on each port.', PoeRestart::confirmFailed(3));
        $this->assertSame(1, ProvisioningStatus::resuming(collect([$cm, new Cm(['phase' => 'failed'])]), Project::first()));
    }

    public function test_the_steps_of_a_run_that_went_on()
    {
        $cm = new Cm(['serial' => '10000000e611110c', 'progress_total' => 6000000000, 'progress_bytes' => 6000000000, 'timeline' => [
            ['phase' => 'write', 'detail' => null, 'at' => 1000],
            ['phase' => 'write', 'detail' => 'Resumed after a restart at 3.0 GB of 6.0 GB', 'at' => 1500, 'from' => 3000000000],
            ['phase' => 'verify', 'detail' => null, 'at' => 1800],
            ['phase' => 'done', 'detail' => null, 'at' => 1900]]]);

        $steps = RunTimeline::steps($cm);

        $this->assertNull($steps[0]['speed'], 'the cut-off write: how far it got is not known');
        $this->assertEqualsWithDelta(10000000, $steps[1]['speed'], 1, '3 GB in 300 s');
        $this->assertEqualsWithDelta(60000000, $steps[2]['speed'], 1, 'the verification reads the whole image');
    }
}
