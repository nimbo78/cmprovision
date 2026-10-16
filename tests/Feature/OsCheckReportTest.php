<?php

namespace Tests\Feature;

use App\Events\CmProvisioningComplete;
use App\Events\CmProvisioningFailed;
use App\Http\Controllers\ScriptExecuteController;
use App\Jobs\FirstBootTimeout;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Label;
use App\Models\Project;
use App\Models\Setting;
use App\Services\Switch\PoeRestart;
use App\Support\StandMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The server's side of the OS check (2.6.0): "written" turns into waiting for the new system, its report finishes the
 * run, a module back at network boot did not start its system, 15 minutes without a report fail the run, and the
 * label after the check names the host.
 */
class OsCheckReportTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function project($labelMoment = 'never', ?Label $label = null)
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => $labelMoment, 'label_id' => $label ? $label->id : null, 'verify' => false, 'os_check' => true]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    protected function label($file)
    {
        return Label::create(['name' => 'host', 'printer_type' => 'command', 'print_command' => 'cat $file >> '.escapeshellarg($file),
            'template' => '$serial $hostname', 'file_extension' => 'txt']);
    }

    protected function cm()
    {
        return Cm::where('serial', self::SERIAL)->firstOrFail();
    }

    /* The module starts and reports its image written: then it reboots into the new system */
    protected function written()
    {
        $this->get(self::START)->assertOk();
        $this->get('/scriptexecute?serial='.self::SERIAL.'&alldone=1&temp=50.0&verify=0')->assertOk();
    }

    protected function osReport($retcode = 0, array $fields = [], $log = "=== first boot script 'services'\nactive\n")
    {
        return $this->post('/scriptexecute?serial='.self::SERIAL.'&firstboot=1&retcode='.$retcode, array_merge([
            'log' => UploadedFile::fake()->createWithContent('firstboot.log', $log),
            'hostname' => 'wlanpi-a1b', 'state' => 'degraded', 'failed_units' => 'firefox-setup.service ', 'reason' => '',
        ], $fields))->assertOk();
    }

    public function test_written_waits_for_the_new_system()
    {
        Event::fake([CmProvisioningComplete::class]);
        Queue::fake();
        $this->project();

        $this->written();

        $cm = $this->cm();
        $this->assertSame(['firstboot', 'waiting', null], [$cm->phase, $cm->os_check, $cm->provisioning_complete_at]);
        $this->assertSame('Checking the OS', $cm->phaseLabel());
        Event::assertNotDispatched(CmProvisioningComplete::class);
        Queue::assertPushed(FirstBootTimeout::class, function ($job) use ($cm) {
            return $job->cmId === $cm->id && $job->runStartedAt === $cm->provisioning_started_at->getTimestamp()
                && $job->delay->getTimestamp() === now()->getTimestamp() + Cm::FIRSTBOOT_SECONDS;
        });
        $this->assertSame(1, Cmlog::where('msg', 'Image written. Rebooting into the new system to check it.')->count());
    }

    public function test_a_passing_report_completes_the_run()
    {
        Event::fake([CmProvisioningComplete::class, CmProvisioningFailed::class]);
        $this->project();
        $this->written();
        Carbon::setTestNow(now()->addMinutes(2));

        $this->osReport(0);

        $cm = $this->cm();
        $this->assertSame(['done', 'passed', 'wlanpi-a1b', 'degraded', 'firefox-setup.service'],
            [$cm->phase, $cm->os_check, $cm->hostname, $cm->os_state, $cm->os_failed_units]);
        $this->assertSame(now()->getTimestamp(), $cm->provisioning_complete_at->getTimestamp());
        $this->assertSame(now()->getTimestamp(), $cm->os_checked_at->getTimestamp());
        $this->assertStringContainsString("first boot script 'services'", $cm->os_check_output);
        Event::assertDispatched(CmProvisioningComplete::class);
        Event::assertNotDispatched(CmProvisioningFailed::class);
        $this->assertSame(1, Cmlog::where('msg', 'like', 'OS check passed on wlanpi-a1b (systemd: degraded; failed: firefox-setup.service).%')->count());
    }

    public function test_a_failing_report_fails_the_run_with_its_reason()
    {
        Event::fake([CmProvisioningComplete::class, CmProvisioningFailed::class]);
        $this->project();
        $this->written();

        $this->osReport(1, ['reason' => "first boot script 'services' exited 3"]);

        $cm = $this->cm();
        $this->assertSame(['failed', 'failed', "OS check failed: first boot script 'services' exited 3"],
            [$cm->phase, $cm->os_check, $cm->phase_detail]);
        $this->assertTrue($cm->failedInItsSystem());
        Event::assertDispatched(CmProvisioningFailed::class);
        Event::assertNotDispatched(CmProvisioningComplete::class);
        $this->assertSame('error', Cmlog::where('msg', 'like', 'OS check failed:%')->value('loglevel'));
    }

    public function test_the_host_name_keeps_only_what_a_host_name_may_hold()
    {
        $this->project();
        $this->written();

        $this->osReport(0, ['hostname' => "wlanpi-a1b; rm -rf /\n".str_repeat('x', 100)]);

        $this->assertSame(substr('wlanpi-a1brm-rf'.str_repeat('x', 100), 0, 64), $this->cm()->hostname);
    }

    public function test_a_log_bigger_than_the_limit_is_cut()
    {
        $this->project();
        $this->written();

        $this->osReport(0, [], str_repeat('a', ScriptExecuteController::MAX_LOG_SIZE + 10));

        $output = $this->cm()->os_check_output;
        $this->assertStringEndsWith("\nLog was bigger than max allowed size. Truncated.", $output);
        $this->assertLessThan(ScriptExecuteController::MAX_LOG_SIZE + 100, strlen($output));
    }

    public function test_a_module_back_at_network_boot_did_not_start_its_new_system()
    {
        Event::fake([CmProvisioningFailed::class]);
        $this->project();
        $this->written();
        $startedAt = $this->cm()->provisioning_started_at->getTimestamp();
        Carbon::setTestNow(now()->addMinute());

        $script = $this->get(self::START)->assertOk()->getContent();

        $cm = $this->cm();
        $this->assertSame(['failed', 'failed', 'The new system did not start: the module came back to network boot.'],
            [$cm->phase, $cm->os_check, $cm->phase_detail]);
        $this->assertSame($startedAt, $cm->provisioning_started_at->getTimestamp(), 'no new run');
        $this->assertStringContainsString('finish fail', $script);
        $this->assertStringNotContainsString("\nwrite_image\n", $script);
        Event::assertDispatched(CmProvisioningFailed::class);

        // its next start is a new run
        $this->assertStringContainsString("\nwrite_image\n", $this->get(self::START)->assertOk()->getContent());
    }

    public function test_no_report_in_15_minutes_fails_the_run_and_a_late_one_still_counts()
    {
        Event::fake([CmProvisioningComplete::class, CmProvisioningFailed::class]);
        $this->project();
        $this->written();   // the tests' sync queue ran the timeout at once: early, it did nothing
        $cm = $this->cm();
        $this->assertSame('firstboot', $cm->phase);

        Carbon::setTestNow(now()->addSeconds(Cm::FIRSTBOOT_SECONDS));
        (new FirstBootTimeout($cm->id, $cm->provisioning_started_at->getTimestamp()))->handle();

        $cm = $this->cm();
        $this->assertSame(['failed', 'failed', Cm::OS_NO_REPORT], [$cm->phase, $cm->os_check, $cm->phase_detail]);
        Event::assertDispatchedTimes(CmProvisioningFailed::class, 1);
        $this->assertSame(1, Cmlog::where('msg', 'like', Cm::OS_NO_REPORT.'%')->count());

        $this->osReport(0);
        $this->assertSame(['done', 'passed'], [$this->cm()->phase, $this->cm()->os_check]);
        Event::assertDispatched(CmProvisioningComplete::class);
    }

    public function test_the_timeout_leaves_a_newer_run_alone()
    {
        $this->project();
        $this->written();
        $old = $this->cm();
        Carbon::setTestNow(now()->addMinute());
        $this->get(self::START)->assertOk();   // back at network boot: that run failed
        $this->get(self::START)->assertOk();   // the next start is a new run

        Carbon::setTestNow(now()->addMinutes(20));
        (new FirstBootTimeout($old->id, $old->provisioning_started_at->getTimestamp()))->handle();

        $this->assertSame('write', $this->cm()->phase);
    }

    public function test_a_report_nobody_waits_for_is_ignored()
    {
        Event::fake([CmProvisioningComplete::class]);
        $this->project();
        $this->written();
        $this->osReport(0);

        // the service ran again after a reboot of its own
        $this->osReport(1, ['reason' => "first boot script 'services' exited 3"]);

        $this->assertSame(['done', 'passed'], [$this->cm()->phase, $this->cm()->os_check]);
        Event::assertDispatchedTimes(CmProvisioningComplete::class, 1);
    }

    public function test_only_the_stand_network_reports()
    {
        $this->project();
        $this->written();

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.68.10'])
            ->post('/scriptexecute?serial='.self::SERIAL.'&firstboot=1&retcode=0', ['hostname' => 'x'])->assertForbidden();

        $this->assertSame('waiting', $this->cm()->os_check);
    }

    public function test_the_label_after_the_os_check_names_the_host()
    {
        if (!is_executable('/bin/sh'))
            $this->markTestSkipped('the print command runs in a POSIX shell');
        $file = sys_get_temp_dir().'/cmprovision-label-'.uniqid();
        $this->project('firstboot', $this->label($file));
        $this->written();
        $this->assertFileDoesNotExist($file);

        $this->osReport(0);

        $this->assertSame(self::SERIAL.' wlanpi-a1b', trim(file_get_contents($file)));
        @unlink($file);
    }

    public function test_the_label_after_writing_prints_before_the_reboot()
    {
        if (!is_executable('/bin/sh'))
            $this->markTestSkipped('the print command runs in a POSIX shell');
        $file = sys_get_temp_dir().'/cmprovision-label-'.uniqid();
        $this->project('postinstall', $this->label($file));

        $this->written();

        $this->assertSame(self::SERIAL, trim(file_get_contents($file)), 'no host name yet');
        @unlink($file);
    }

    public function test_a_module_in_the_check_runs_quietly_for_15_minutes()
    {
        $this->project();
        $this->written();

        Carbon::setTestNow(now()->addMinutes(14));
        $this->assertTrue($this->cm()->isActive());
        $this->assertNull($this->cm()->silentFor());
        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertNotNull($this->cm()->silentFor());
    }

    public function test_a_repeated_written_report_changes_nothing()
    {
        Event::fake([CmProvisioningComplete::class]);
        $this->project();
        $this->written();

        // report() sends it again when the first answer takes long (a slow label printer)
        $this->get('/scriptexecute?serial='.self::SERIAL.'&alldone=1&temp=50.0&verify=0')->assertOk();

        $cm = $this->cm();
        $this->assertSame(['firstboot', 'waiting', null], [$cm->phase, $cm->os_check, $cm->provisioning_complete_at]);
        Event::assertNotDispatched(CmProvisioningComplete::class);
        $this->assertSame(1, Cmlog::where('msg', 'like', 'Image written.%')->count());
    }

    public function test_a_failed_timeout_job_queues_another()
    {
        $this->project();
        $this->written();
        $cm = $this->cm();
        Queue::fake();

        (new FirstBootTimeout($cm->id, $cm->provisioning_started_at->getTimestamp()))->failed(new \RuntimeException('database is locked'));

        Queue::assertPushed(FirstBootTimeout::class, function ($job) use ($cm) {
            return $job->cmId === $cm->id && $job->runStartedAt === $cm->provisioning_started_at->getTimestamp() && $job->delay !== null;
        });
    }

    public function test_a_failed_timeout_job_of_a_finished_run_queues_nothing()
    {
        $this->project();
        $this->written();
        $this->osReport(0);
        $cm = $this->cm();
        Queue::fake();

        (new FirstBootTimeout($cm->id, $cm->provisioning_started_at->getTimestamp()))->failed(new \RuntimeException('database is locked'));

        Queue::assertNothingPushed();
    }

    public function test_a_start_long_after_the_reboot_closes_the_old_run_and_starts_anew()
    {
        Event::fake([CmProvisioningFailed::class]);
        $this->project();
        $this->written();
        Carbon::setTestNow(now()->addSeconds(Cm::FIRSTBOOT_SECONDS + 60));   // its timeout job was lost

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString("\nwrite_image\n", $script, 'a new run');
        $this->assertSame('write', $this->cm()->phase);
        Event::assertDispatched(CmProvisioningFailed::class);
        $this->assertSame(1, Cmlog::where('msg', 'like', Cm::OS_NO_REPORT.'%')->count());
    }

    public function test_a_usb_boot_during_the_check_is_a_new_run()
    {
        Event::fake([CmProvisioningFailed::class]);
        $this->project();
        $this->written();

        $script = $this->get(self::START.'&bootmode=3')->assertOk()->getContent();

        $this->assertStringContainsString("\nwrite_image\n", $script, 'the operator re-flashes it over USB');
        $this->assertSame('skipped', $this->cm()->os_check);
        $this->assertSame(1, Cmlog::where('msg', 'Interrupted: the module was booted over USB.')->count());
        Event::assertDispatched(CmProvisioningFailed::class);
    }

    public function test_progress_while_the_module_reboots_keeps_the_check()
    {
        $this->project();
        $this->written();

        $this->get('/scriptexecute?serial='.self::SERIAL.'&progress=postinstall&detail=late')->assertOk();

        $this->assertSame('firstboot', $this->cm()->phase);
    }

    public function test_a_restart_during_the_check_says_what_happens()
    {
        $cm = new Cm(['serial' => self::SERIAL, 'mac' => 'e4:5f:01:62:9f:d3', 'phase' => 'firstboot', 'os_check' => 'waiting',
            'provisioning_started_at' => now(), 'progress_updated_at' => now()]);
        $text = 'Restart '.self::SERIAL.' on sw01 Gi0/1? PoE goes off for 5 s; the module boots its new system again.';
        $this->assertSame($text, PoeRestart::confirm($cm, 'sw01 Gi0/1'));

        $cm->phase = 'failed';
        $cm->phase_detail = Cm::OS_NO_REPORT;
        $cm->os_check = 'failed';
        $this->assertSame($text, PoeRestart::confirm($cm, 'sw01 Gi0/1'));
    }

    public function test_the_timeout_waits_in_the_boards_queue_and_fails_the_run_when_due()
    {
        config(['queue.default' => 'database']);
        Event::fake([CmProvisioningFailed::class]);
        $this->project();
        $this->written();
        $job = DB::table('jobs')->where('queue', 'boards')->first();
        $this->assertNotNull($job, 'the timeout waits in the boards queue');
        $this->assertSame(now()->getTimestamp() + Cm::FIRSTBOOT_SECONDS, (int) $job->available_at);

        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'boards', '--once' => true, '--sleep' => 0]);
        $this->assertSame('firstboot', $this->cm()->phase, 'not due yet: no worker takes it');

        Carbon::setTestNow(now()->addSeconds(Cm::FIRSTBOOT_SECONDS));
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'boards', '--once' => true, '--sleep' => 0]);
        $this->assertSame(['failed', Cm::OS_NO_REPORT], [$this->cm()->phase, $this->cm()->phase_detail]);
        Event::assertDispatched(CmProvisioningFailed::class);
    }

    public function test_a_module_that_failed_in_its_own_system_is_not_restarted()
    {
        $cm = new Cm(['serial' => self::SERIAL, 'mac' => 'e4:5f:01:62:9f:d3', 'phase' => 'failed', 'os_check' => 'failed', 'os_checked_at' => now()]);
        $tile = ['label' => 'sw01 Gi0/1', 'link' => null, 'state' => 'module', 'poe' => ['state' => 'delivering'],
                 'modules' => [$cm], 'others' => 0, 'macs' => [$cm->mac]];

        $this->assertSame('the module on sw01 Gi0/1 runs its new system: a restart would boot it again, not provision it',
            StandMap::restartBlocker($tile));
        $cm->os_checked_at = null;   // it did not start, or did not report: a restart provisions it again
        $this->assertNull(StandMap::restartBlocker($tile));
    }

    public function test_the_ports_page_marks_a_module_in_the_check()
    {
        $cm = new Cm(['serial' => '10000000000000a1', 'mac' => 'aa:00:00:00:00:01', 'phase' => 'firstboot', 'progress_updated_at' => now()]);
        $snapshot = ['id' => '192.0.2.10', 'model' => 'S5731', 'taken_at' => 0, 'duration' => 0.3, 'mac_method' => 'huawei',
            'identity' => ['revision' => null, 'serial' => null, 'name' => 'sw01', 'mac' => null], 'poe' => false,
            'ports' => [1 => ['ifIndex' => 1, 'name' => 'ether1', 'alias' => '', 'up' => true, 'speed' => 1000, 'macs' => [$cm->mac], 'poe' => null]]];

        $map = (new StandMap([['id' => 1, 'name' => 'sw01', 'snapshot' => $snapshot, 'answering' => true]], [$cm], '02:00:00:00:00:01'))->build();

        $this->assertSame('OS', $map['tiles']['s1p1']['mark']);
    }
}
