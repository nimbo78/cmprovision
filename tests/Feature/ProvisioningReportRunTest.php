<?php

namespace Tests\Feature;

use App\Events\CmProvisioningComplete;
use App\Http\Controllers\ScriptExecuteController;
use App\Models\Cm;
use App\Models\Label;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProvisioningReportRunTest extends TestCase
{
    use RefreshDatabase;

    private const SERIAL = '10000000feedface';
    private const URL = '/scriptexecute?serial='.self::SERIAL;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Queue::fake();
        $project = Project::create(['name' => 'run reports', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'never']);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
    }

    private function start(): Cm
    {
        $this->get(self::URL.'&model=CM4&mac=e4:5f:01:00:00:a1&storagesize=62500000')->assertOk();
        return Cm::where('serial', self::SERIAL)->firstOrFail();
    }

    public function test_previous_executor_cannot_rewind_progress_or_finish_a_fresh_run(): void
    {
        $old = $this->start()->report_token;
        $cm = $this->start();
        $cm->forceFill(['phase' => 'verify', 'progress_total' => 1048576, 'progress_bytes' => 1048576])->save();

        $this->withHeader('X-Cmprovision-Run', $old)->get(self::URL.'&progress=write&sectors=1')->assertStatus(409);
        $this->get(self::URL.'&alldone=1&verify=1')->assertStatus(409);
        $this->assertSame('verify', $cm->fresh()->phase);
        $this->assertSame(1048576, $cm->fresh()->progress_bytes);
        $this->assertNull($cm->fresh()->provisioning_complete_at);
        Event::assertNotDispatched(CmProvisioningComplete::class);
    }

    public function test_missing_run_header_cannot_complete_a_new_run(): void
    {
        $cm = $this->start();
        $this->get(self::URL.'&alldone=1')->assertStatus(409);
        $this->assertNotSame('done', $cm->fresh()->phase);
    }

    public function test_stale_logs_firmware_and_firstboot_cannot_change_current_run(): void
    {
        $cm = $this->start();
        $cm->forceFill(['phase' => 'firstboot', 'os_check' => 'waiting'])->save();
        $this->withHeader('X-Cmprovision-Run', str_repeat('a', 40));
        $this->post(self::URL.'&phase=preinstall&retcode=1', ['log' => UploadedFile::fake()->createWithContent('log.txt', 'old error')])->assertStatus(409);
        $this->post(self::URL, ['eeprom_version' => UploadedFile::fake()->createWithContent('version.txt', 'old firmware')])->assertStatus(409);
        $this->post(self::URL.'&firstboot=1&retcode=0', ['hostname' => 'old-host'])->assertStatus(409);
        $cm->refresh();
        $this->assertSame('firstboot', $cm->phase);
        $this->assertSame('waiting', $cm->os_check);
        $this->assertNull($cm->pre_script_output);
        $this->assertNull($cm->eeprom_before);
        $this->assertNull($cm->hostname);
    }

    public function test_current_completion_is_idempotent_and_token_is_not_in_api_data(): void
    {
        $cm = $this->start();
        $this->assertMatchesRegularExpression('/^[a-f0-9A-Za-z]{40}$/', (string) $cm->report_token);
        $this->assertArrayNotHasKey('report_token', $cm->toArray());
        $this->withHeader('X-Cmprovision-Run', $cm->report_token)->get(self::URL.'&alldone=1')->assertOk();
        $complete = $cm->fresh()->provisioning_complete_at;
        $this->travel(5)->seconds();
        $this->get(self::URL.'&alldone=1')->assertOk();
        $this->assertTrue($complete->equalTo($cm->fresh()->provisioning_complete_at));
        Event::assertDispatchedTimes(CmProvisioningComplete::class, 1);
    }

    public function test_legacy_in_flight_run_can_finish_without_new_header(): void
    {
        $cm = Cm::create(['serial' => self::SERIAL, 'mac' => 'e4:5f:01:00:00:a1', 'phase' => 'postinstall',
            'diagnostics_token' => str_repeat('b', 40), 'provisioning_started_at' => now()]);
        $this->get(self::URL.'&alldone=1')->assertOk();
        $this->assertSame('done', $cm->fresh()->phase);
    }

    public function test_current_progress_firmware_and_firstboot_are_accepted(): void
    {
        $cm = $this->start();
        $this->withHeader('X-Cmprovision-Run', $cm->report_token)->get(self::URL.'&progress=write&sectors=12')->assertOk();
        $this->assertSame(6144, $cm->fresh()->progress_bytes);
        $this->post(self::URL, ['eeprom_version' => UploadedFile::fake()->createWithContent('version.txt', 'new firmware')])->assertOk();
        $this->assertSame('new firmware', $cm->fresh()->eeprom_before);
        $this->post(self::URL.'&phase=postinstall&retcode=0', ['log' => UploadedFile::fake()->createWithContent('log.txt', 'current log')])->assertOk();
        $this->assertSame('current log', $cm->fresh()->post_script_output);
        $cm->refresh()->forceFill(['phase' => 'firstboot', 'os_check' => 'waiting'])->save();
        $this->post(self::URL.'&firstboot=1&retcode=0', ['hostname' => 'new-host'])->assertOk();
        $this->assertSame('done', $cm->fresh()->phase);
        $this->assertSame('new-host', $cm->fresh()->hostname);
    }

    public function test_explicitly_stale_diagnostics_token_is_rejected_even_for_legacy_clients(): void
    {
        $cm = Cm::create(['serial' => self::SERIAL, 'mac' => 'e4:5f:01:00:00:a1', 'phase' => 'verify',
            'diagnostics_token' => str_repeat('b', 40), 'provisioning_started_at' => now()]);
        $this->get(self::URL.'&progress=write&sectors=1&diagnostics_token='.str_repeat('a', 40))->assertStatus(409);
        $this->assertSame('verify', $cm->fresh()->phase);
    }

    public function test_report_exception_is_retryable_and_does_not_fail_the_run(): void
    {
        $controller = \Mockery::mock(ScriptExecuteController::class)->makePartial();
        $controller->shouldReceive('registerFirmware')->once()->andReturnUsing(function () {
            Cm::where('serial', self::SERIAL)->update(['phase' => 'failed']);
            throw new \RuntimeException('temporary report error');
        });
        $this->app->instance(ScriptExecuteController::class, $controller);
        $cm = $this->start();
        $phase = $cm->phase;
        $this->withHeader('X-Cmprovision-Run', $cm->report_token)->post(self::URL,
            ['eeprom_version' => UploadedFile::fake()->createWithContent('version.txt', 'new firmware')])->assertStatus(503);
        $this->assertSame($phase, $cm->fresh()->phase);
    }

    public function test_completion_prints_once_without_holding_the_report_write_transaction(): void
    {
        if (PHP_OS_FAMILY === 'Windows') $this->markTestSkipped('POSIX print command required');
        $label = Label::create(['name' => 'test', 'printer_type' => 'command', 'print_command' => 'printf lock-proof',
            'template' => '$serial', 'file_extension' => 'txt']);
        Project::first()->update(['label_id' => $label->id, 'label_moment' => 'postinstall']);
        $cm = $this->start();
        $levels = [];
        DB::listen(function ($query) use (&$levels) {
            if (in_array('Label command output: lock-proof', $query->bindings, true))
                $levels[] = DB::transactionLevel();
        });
        $outer = DB::transactionLevel(); // RefreshDatabase keeps its own outer test transaction.
        $this->withHeader('X-Cmprovision-Run', $cm->report_token)->get(self::URL.'&alldone=1')->assertOk();
        $this->get(self::URL.'&alldone=1')->assertOk();
        $this->assertSame([$outer], $levels, 'printer I/O must run after the report transaction commits, once');
    }

    public function test_os_check_script_carries_the_same_run_header(): void
    {
        Project::first()->update(['os_check' => true]);
        $script = $this->get(self::URL.'&model=CM4&mac=e4:5f:01:00:00:a1&storagesize=62500000')->assertOk()->getContent();
        $token = Cm::where('serial', self::SERIAL)->firstOrFail()->report_token;
        $this->assertSame(2, substr_count($script, "REPORT_TOKEN='".$token."'"), 'utility OS and installed firstboot service');
        $this->assertStringContainsString('--header "X-Cmprovision-Run: $REPORT_TOKEN"', $script);
    }
}
