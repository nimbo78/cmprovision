<?php

namespace Tests\Feature;

use App\Events\CmProvisioningStarted;
use App\Jobs\SplitImage;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 2.6.1: a module that stopped in the write of a project with "Split the image into pieces" goes on from its last durable
    piece; any other start writes from the beginning, as in 2.6.0 */
class ImageResumeStartTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3';
    const MIB = 1048576;

    protected $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file)
            if ($file && is_file($file))
                unlink($file);
        parent::tearDown();
    }

    /** An image of 4.5 MiB (the first MiB and four pieces of 1 MiB) and, unless $copy is false, its pieces copy */
    protected function imageWithCopy(bool $copy = true)
    {
        $data = str_repeat("\x55", 512).str_repeat("\0", self::MIB - 512).str_repeat('d', 3 * self::MIB + self::MIB / 2);
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'resume-'.uniqid().'.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = strlen($data);
        $image->uncompressed_sha256 = hash('sha256', $data);
        $image->save();
        $this->files[] = $image->imagepath();
        file_put_contents($image->imagepath(), gzencode($data));
        if ($copy)
        {
            $members = [gzencode(substr($data, 0, self::MIB))];
            for ($at = self::MIB; $at < strlen($data); $at += self::MIB)
                $members[] = gzencode(substr($data, $at, self::MIB));
            $offsets = [0];
            foreach ($members as $member)
                $offsets[] = end($offsets) + strlen($member);
            $image->split_filename = 'pieces-'.uniqid().'.gz';
            $image->split_offsets = json_encode($offsets);
            $image->split_piece_mb = 1;
            $image->save();
            $this->files[] = $image->splitPath();
            file_put_contents($image->splitPath(), implode('', $members));
        }
        return $image;
    }

    protected function project(array $attributes = [], ?Image $image = null)
    {
        $image = $image ?: $this->imageWithCopy();
        $project = Project::create(array_merge(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
            'image_id' => $image->id, 'label_moment' => 'never', 'verify' => true, 'os_check' => false, 'split_image' => true], $attributes));
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    protected function cm()
    {
        return Cm::where('serial', self::SERIAL)->firstOrFail();
    }

    /** The module reports it wrote the image durably up to $mib MiB */
    protected function durable(int $mib)
    {
        $this->get('/scriptexecute?serial='.self::SERIAL.'&progress=write&detail=&sectors=&durable_mb='.$mib)->assertOk();
    }

    public function test_a_fresh_run_of_the_project_writes_its_pieces_copy()
    {
        $project = $this->project();

        $script = $this->get(self::START)->assertOk()->getContent();

        $cm = $this->cm();
        $this->assertSame($project->image_id, $cm->durable_image_id);
        $this->assertNull($cm->durable_bytes);
        $this->assertStringContainsString('/uploads/'.$project->image->split_filename."'\n", $script);
        $this->assertStringContainsString("PIECES=4\nPIECE_MB=1\n", $script);
        $this->assertStringContainsString('| { dd of="$WORK/head" bs=1M count=1 iflag=fullblock 2>>"$WRITE_LOG" && write_pieces 0; }', $script);
        $this->assertStringContainsString('vcgencmd bootloader_version', $script);
    }

    public function test_durable_marks_count_only_in_the_write_of_a_run_on_the_copy_and_only_upwards()
    {
        $this->project();
        $this->get(self::START)->assertOk();

        $this->durable(3);
        $this->assertSame(3 * self::MIB, $this->cm()->durable_bytes);
        $this->durable(2);
        $this->assertSame(3 * self::MIB, $this->cm()->durable_bytes, 'never back');
        $this->durable(4);
        $this->assertSame(4 * self::MIB, $this->cm()->durable_bytes);

        $this->cm()->update(['durable_image_id' => null, 'durable_bytes' => null]);
        $this->durable(3);
        $this->assertNull($this->cm()->durable_bytes, 'a run on the original has no marks');
    }

    public function test_a_module_that_restarted_mid_write_continues_from_its_last_durable_piece()
    {
        Event::fake([CmProvisioningStarted::class]);
        $project = $this->project();
        $pre = Script::create(['name' => 'Bench: debug shell', 'script_type' => 'preinstall', 'bg' => false, 'priority' => 1, 'script' => 'true']);
        $project->scripts()->sync([$pre->id]);
        $this->get(self::START)->assertOk();
        $this->durable(3);   // pieces 0 and 1: the image from 1 to 3 MiB
        $this->cm()->update(['eeprom_before' => 'BUILD_TIMESTAMP=1700000000', 'pre_script_output' => 'flashed']);
        $steps = count($this->cm()->timeline);

        $script = $this->get(self::START)->assertOk()->getContent();

        $cm = $this->cm();
        $this->assertSame(['write', 'Resumed after a restart at 0.0 GB of 0.0 GB'], [$cm->phase, $cm->phase_detail]);
        $this->assertCount($steps + 1, $cm->timeline);
        $timeline = $cm->timeline;
        $last = end($timeline);
        $this->assertSame(['write', 3 * self::MIB], [$last['phase'], $last['from']]);
        $this->assertSame([3 * self::MIB, 3 * self::MIB], [$cm->progress_bytes, $cm->progress_base]);
        $this->assertSame(['BUILD_TIMESTAMP=1700000000', 'flashed'], [$cm->eeprom_before, $cm->pre_script_output]);
        Event::assertDispatchedTimes(CmProvisioningStarted::class, 1);
        $this->assertTrue(Cmlog::where('cm', self::SERIAL)
            ->where('msg', 'Provisioning resumed: the module restarted during the write; continuing from 0.0 GB of 0.0 GB. Utility OS: legacy.')->exists());

        $copy = $project->image->fresh();
        $offsets = $copy->splitOffsets();
        $this->assertStringContainsString("\n    fetch_image ".$offsets[3].' | { gzip -dc 2>>"$WRITE_LOG"; } | write_pieces 2', $script);
        $this->assertStringContainsString("PROGRESS_DETAIL='Resumed after a restart at 0.0 GB of 0.0 GB'\nPROGRESS_BASE_SECTORS=6144\n", $script);
        $this->assertStringContainsString(chunk_split(base64_encode(substr(file_get_contents($copy->splitPath()), 0, $offsets[1])), 76, "\n"), $script);
        $this->assertStringNotContainsString('vcgencmd bootloader_version', $script, 'the bootloader "before" stays');
        $this->assertStringNotContainsString('blkdiscard -v', $script);
        $this->assertStringNotContainsString('/tmp/pre-'.$pre->id.'.sh', $script);
    }

    public function test_a_failed_write_continues_too()
    {
        $this->project();
        $this->get(self::START)->assertOk();
        $this->durable(3);
        $this->post('/scriptexecute?serial='.self::SERIAL.'&retcode=1&phase=dd', ['log' => UploadedFile::fake()
            ->createWithContent('dd.log', "no data from the provisioning server for 10 minutes (curl exit code 7)\n")])->assertOk();
        $this->assertSame(['failed', null], [$this->cm()->phase, $this->cm()->image_filename]);

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertSame('write', $this->cm()->phase);
        $this->assertSame('wlanpi-os.img.gz', $this->cm()->image_filename, 'back after the failure cleared it');
        $this->assertStringContainsString('write_pieces 2', $script);
    }

    public function test_a_second_restart_before_a_new_piece_resumes_from_the_same_piece_again()
    {
        $this->project();
        $this->get(self::START)->assertOk();
        $this->durable(3);
        $this->get(self::START)->assertOk();
        $steps = count($this->cm()->timeline);

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString('write_pieces 2', $script);
        $this->assertCount($steps + 1, $this->cm()->timeline, 'a step of its own, although it reads the same');
    }

    public function test_a_resumed_run_keeps_its_os_check()
    {
        $this->project(['os_check' => true]);
        $this->get(self::START)->assertOk();
        $this->durable(3);

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString('write_pieces 2', $script, 'the run went on');
        $this->assertSame('pending', $this->cm()->os_check);
        $this->assertStringContainsString('cat >/tmp/oscheck/run << "CMPROVISIONINGEOF"', $script);
        $this->assertStringContainsString("reboot -f\n", $script);
    }

    public static function startsOver(): array
    {
        return [
            'the verification failed' => ['verify'],
            'not one piece durable' => ['short'],
            'another project is active' => ['project'],
            'the project writes another image' => ['image'],
            'the checkbox is off' => ['checkbox'],
            'the copy is gone' => ['file'],
            'the operator pressed Start over' => ['over'],
            'a run started after the marks' => ['later'],
            'another card' => ['card'],
            'a card of another size' => ['size'],
            'the storage was written since' => ['written'],
            'a mark past the pieces of the copy' => ['past'],
        ];
    }

    #[DataProvider('startsOver')]
    public function test_a_start_that_cannot_go_on_writes_from_the_beginning($case)
    {
        Queue::fake();
        $project = $this->project();
        $start = self::START.'&cid=1b534d4e4d4d4301';
        $this->get($start)->assertOk();
        $this->durable($case === 'short' ? 1 : 3);
        switch ($case)
        {
            case 'verify':
                $cm = $this->cm();
                $cm->setPhase('verify');
                $cm->markFailed('Error during postinstall. Return code 2.')->save();
                break;
            case 'project':
                $this->project(['name' => 'other'], $project->image);
                break;
            case 'image':
                $project->update(['image_id' => $this->imageWithCopy()->id]);
                break;
            case 'checkbox':
                $project->update(['split_image' => false]);
                break;
            case 'file':
                unlink($project->image->splitPath());
                break;
            case 'over':
                $this->cm()->update(['durable_bytes' => null]);
                break;
            case 'later':
                // a run that knows nothing of the marks (2.6.0 after a rollback) left them as they were
                $this->cm()->update(['provisioning_started_at' => now()->addMinute()]);
                break;
            case 'card':
                $start = self::START.'&cid=1b534d4e4d4d4302';
                break;
            case 'size':
                $start = str_replace('storagesize=62500000', 'storagesize=31250000', $start);
                break;
            case 'written':
                $this->get($start)->assertOk();   // the run goes on and finds a partition table on the storage
                $this->post('/scriptexecute?serial='.self::SERIAL.'&retcode=1&phase=dd', ['log' => UploadedFile::fake()
                    ->createWithContent('dd.log', "1+0 records in\n1+0 records out\nthe storage was written after the write stopped\n")])->assertOk();
                break;
            case 'past':
                $this->cm()->update(['durable_bytes' => 5 * self::MIB]);   // the copy has pieces 0-3: piece 4 is none of them
                break;
        }

        $script = $this->get($start)->assertOk()->getContent();

        $this->assertStringContainsString("PROGRESS_DETAIL=''\n", $script);
        $this->assertStringContainsString('vcgencmd bootloader_version', $script);
        $this->assertNull($this->cm()->durable_bytes);
        $this->assertCount(1, $this->cm()->timeline, 'a new run');
        if ($case === 'file')
            Queue::assertPushed(SplitImage::class);
    }

    public function test_without_a_copy_the_start_writes_the_original_and_leaves_the_copy_to_the_pages()
    {
        Queue::fake();
        $image = $this->imageWithCopy(false);
        $image->split_error = 'not enough free space: need about 0.0 GB, 0.0 GB free';
        $image->save();
        $project = $this->project([], $image);

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString('/uploads/'.$project->image->filename_on_server."'\n", $script);
        $this->assertStringContainsString('| write_plain', $script);
        $this->assertNull($this->cm()->durable_image_id);
        // saving the project and Retry on the Images page make it; a failed one waits for Retry, not for every start
        Queue::assertNotPushed(SplitImage::class);
    }

    public function test_a_copy_that_cannot_be_queued_does_not_fail_the_start()
    {
        $project = $this->project();
        unlink($project->image->splitPath());   // its file is gone: the start has it made again
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('database is locked'));
        });

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString('| write_plain', $script);
        $this->assertSame('write', $this->cm()->phase);
        $this->assertNull($project->image->fresh()->split_queued_at, 'no "preparing…" without a job');
    }

    public function test_the_same_card_goes_on()
    {
        $this->project();
        $this->get(self::START.'&cid=1b534d4e4d4d4301')->assertOk();
        $this->durable(3);

        $this->assertStringContainsString('write_pieces 2', $this->get(self::START.'&cid=1b534d4e4d4d4301')->assertOk()->getContent());
    }

    public function test_a_run_that_goes_on_over_usb_skips_its_os_check()
    {
        $this->project(['os_check' => true]);
        $this->get(self::START)->assertOk();
        $this->durable(3);
        $this->assertSame('pending', $this->cm()->os_check);

        $script = $this->get(self::START.'&bootmode=3')->assertOk()->getContent();

        $this->assertStringContainsString('write_pieces 2', $script, 'the run went on');
        $this->assertSame('skipped', $this->cm()->os_check, 'a reboot would bring it back to USB boot');
        $this->assertStringNotContainsString('cat >/tmp/oscheck/run', $script);
        $this->assertStringNotContainsString("reboot -f\n", $script);
        $this->assertTrue(Cmlog::where('cm', self::SERIAL)->where('msg', 'like', 'Provisioning resumed:%OS check skipped: the module booted over USB.')->exists());
    }

    public function test_marks_past_the_image_or_outside_the_write_do_not_count()
    {
        $this->project();
        $this->get(self::START)->assertOk();
        $this->durable(3);

        $this->durable(5);   // the image is 4.5 MiB: no piece starts that far
        $this->assertSame(3 * self::MIB, $this->cm()->durable_bytes);
        $this->get('/scriptexecute?serial='.self::SERIAL.'&progress=verify&detail=&sectors=&durable_mb=4')->assertOk();
        $this->assertSame(3 * self::MIB, $this->cm()->durable_bytes, 'only the write marks pieces');
    }

    public function test_a_project_without_the_checkbox_writes_as_before()
    {
        Queue::fake();
        $project = $this->project(['split_image' => false]);

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString('/uploads/'.$project->image->filename_on_server."'\n", $script);
        $this->assertStringContainsString('| write_plain', $script);
        Queue::assertNotPushed(SplitImage::class);
    }
}
