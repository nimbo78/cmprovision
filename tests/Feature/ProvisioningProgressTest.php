<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Each module tells the server which phase it is in and, while the image is written or verified,
 * how many sectors have gone to (or come from) the storage device. The web interface turns that
 * into a progress bar with speed and remaining time.
 */
class ProvisioningProgressTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000feedf00d';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:00:00:03';

    protected function activeProject($withPreScript = true, $verify = false)
    {
        $image = new Image;
        $image->filename = 'os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_sha256 = str_repeat('b', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();

        $project = Project::create([
            'name' => 'p', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
            'image_id' => $image->id, 'label_moment' => 'never', 'verify' => $verify,
        ]);
        if ($withPreScript) {
            $script = Script::create(['name' => "Set 'serial' file", 'script_type' => 'preinstall', 'priority' => 50,
                                      'bg' => false, 'script' => "#!/bin/sh\necho hi\n"]);
            $project->scripts()->sync([$script->id]);
        }
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    protected function cm()
    {
        return Cm::where('serial', self::SERIAL)->firstOrFail();
    }

    protected function progress($query)
    {
        return $this->get('/scriptexecute?serial='.self::SERIAL.'&'.$query);
    }

    public function test_start_records_the_first_phase_and_the_amount_to_write()
    {
        $this->activeProject(true);
        $this->get(self::START)->assertOk();

        $cm = $this->cm();
        $this->assertSame('preinstall', $cm->phase);
        $this->assertEquals(8 * 1024 * 1024, $cm->progress_total);
        $this->assertNull($cm->progress_bytes);
        $this->assertNotNull($cm->phase_started_at);
    }

    public function test_without_pre_install_scripts_the_module_starts_with_the_image()
    {
        $this->activeProject(false);
        $this->get(self::START)->assertOk();

        $this->assertSame('write', $this->cm()->phase);
    }

    public function test_progress_report_updates_phase_and_bytes_without_filling_the_log()
    {
        $this->activeProject();
        $this->get(self::START);
        $logRows = Cmlog::count();

        $this->progress('progress=write&sectors=4096')->assertOk()->assertSee('', false);

        $cm = $this->cm();
        $this->assertSame('write', $cm->phase);
        $this->assertEquals(4096 * 512, $cm->progress_bytes);
        $this->assertNotNull($cm->progress_updated_at);
        $this->assertSame($logRows, Cmlog::count(), 'progress reports must not go to the web log');
    }

    public function test_a_new_phase_or_script_restarts_the_phase_clock()
    {
        $this->activeProject();
        $this->get(self::START);
        Carbon::setTestNow(now()->addMinutes(3));
        $this->progress('progress=write&sectors=100');
        $writeStarted = $this->cm()->phase_started_at;

        Carbon::setTestNow(now()->addMinutes(2));
        $this->progress('progress=write&sectors=900');
        $this->assertEquals($writeStarted, $this->cm()->phase_started_at, 'same phase keeps its clock');

        $this->progress('progress=verify&sectors=0');
        $cm = $this->cm();
        $this->assertSame('verify', $cm->phase);
        $this->assertTrue($cm->phase_started_at->gt($writeStarted));
        $this->assertEquals(0, $cm->progress_bytes);

        $this->progress('progress=postinstall&detail='.rawurlencode('Resize ext4 partition'));
        $this->assertSame('Resize ext4 partition', $this->cm()->phase_detail);
        Carbon::setTestNow();
    }

    public function test_unknown_phases_and_unknown_modules_are_ignored()
    {
        $this->activeProject();
        $this->get(self::START);

        $this->progress('progress=rm-rf&sectors=1')->assertOk();
        $this->assertSame('preinstall', $this->cm()->phase);

        $this->get('/scriptexecute?serial=1000000000000bad&progress=write&sectors=1')->assertOk();
        $this->assertSame(0, Cm::where('serial', '1000000000000bad')->count());
    }

    public function test_a_failed_write_marks_the_module_failed_with_the_diagnosis()
    {
        $this->activeProject();
        $this->get(self::START);
        $this->progress('progress=write&sectors=2048');

        $log = UploadedFile::fake()->createWithContent('dd.log', "curl: (18) transfer closed\ncurl exit code 18\n");
        $this->post('/scriptexecute?serial='.self::SERIAL.'&retcode=1&phase=dd', ['log' => $log])->assertOk();

        $cm = $this->cm();
        $this->assertSame('failed', $cm->phase);
        $this->assertStringContainsString('connection to the provisioning server was closed', $cm->phase_detail);
        $this->assertEquals(2048 * 512, $cm->progress_bytes, 'how far it got stays visible');

        $this->progress('progress=write&sectors=4096');
        $this->assertSame('failed', $this->cm()->phase, 'a late progress report does not revive a failed module');
    }

    public function test_pre_install_success_moves_on_to_the_image_and_completion_marks_done()
    {
        $this->activeProject();
        $this->get(self::START);

        $log = UploadedFile::fake()->createWithContent('pre.log', "ok\n");
        $this->post('/scriptexecute?serial='.self::SERIAL.'&retcode=0&phase=preinstall', ['log' => $log]);
        $this->assertSame('write', $this->cm()->phase);

        $this->get('/scriptexecute?serial='.self::SERIAL.'&alldone=1&temp=50.0C&verify=0')->assertOk();
        $cm = $this->cm();
        $this->assertSame('done', $cm->phase);
        $this->assertEquals($cm->progress_total, $cm->progress_bytes);
    }

    public function test_refusing_to_provision_marks_the_module_failed()
    {
        $this->activeProject();
        $this->get('/scriptexecute?serial='.self::SERIAL.'&model=CM4&mac=e4:5f:01:00:00:03')->assertOk();   // no eMMC

        $cm = $this->cm();
        $this->assertSame('failed', $cm->phase);
        $this->assertStringContainsString('Missing eMMC', $cm->phase_detail);
    }

    public function test_the_module_script_reports_phases_and_counts_sectors_while_writing_and_verifying()
    {
        $this->activeProject(true, true);
        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString('progress_mark()', $script);
        $this->assertStringContainsString("progress_mark preinstall 'Set '\\''serial'\\'' file'", $script, 'script names are shell-quoted');
        $this->assertMatchesRegularExpression('/progress_start write 7\n.*\| dd of=\$STORAGE/s', $script);
        $this->assertMatchesRegularExpression('/progress_start verify 3\n.*post-0\.sh.*\nRETCODE=\$\?\nprogress_stop/s', $script);
        $this->assertStringContainsString('/sys/block/$(basename $STORAGE)/stat', $script);
        $this->assertStringNotContainsString('--retry', $this->extractFunction($script, 'progress_mark'),
            'progress reports are best effort: no retries that could hold up provisioning');
    }

    public function test_every_script_run_is_followed_by_its_own_return_code_check()
    {
        // A Blade directive at the end of a line swallows the newline, which once glued
        // "RETCODE=$?" onto the script command line.
        $project = $this->activeProject(true, true);
        $bg = Script::create(['name' => 'Background job', 'script_type' => 'postinstall', 'priority' => 90,
                              'bg' => true, 'script' => "#!/bin/sh\nsleep 1\n"]);
        $project->scripts()->attach($bg->id);

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#sh -v /tmp/pre-\d+\.sh >>/tmp/pre\.log 2>&1\nRETCODE=\$\?\n#', $script);
        $this->assertMatchesRegularExpression('#sh -v /tmp/post-0\.sh >>/tmp/post\.log 2>&1\nRETCODE=\$\?\n#', $script);
        $this->assertMatchesRegularExpression('#sh -v /tmp/post-'.$bg->id.'\.sh >>/tmp/post\.log 2>&1 &\nRETCODE=\$\?\n#', $script);
    }

    private function extractFunction($script, $name)
    {
        $this->assertMatchesRegularExpression('/^'.$name.'\(\) \{\n(.*?)\n\}/ms', $script);
        preg_match('/^'.$name.'\(\) \{\n(.*?)\n\}/ms', $script, $m);
        return $m[1];
    }
}
