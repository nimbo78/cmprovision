<?php

namespace Tests\Feature;

use App\Http\Livewire\Cms;
use App\Http\Livewire\ProvisioningLog;
use App\Http\Livewire\ProvisioningStatus;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use App\Models\User;
use App\Support\FailureAdvice;
use App\Support\RunTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The module card (/cms/<serial>): the steps of the last run with their times, durations and
 * speeds, the bootloader before and after, the logs, the module's history, and for a failed or
 * silent module the step where it stopped and what the operator can do about it.
 */
class ModuleCardTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3';
    const IMAGE_SIZE = 4785700864;
    const FLASH = 'Flash EEPROM firmware (default/pieeprom-2026-09-23.bin)';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'Europe/Moscow']);
        $this->clock('13:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function clock($time)
    {
        Carbon::setTestNow('2026-10-09 '.$time);
    }

    protected function activeProject()
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_sha256 = str_repeat('b', 64);
        $image->uncompressed_size = self::IMAGE_SIZE;
        $image->save();

        $project = Project::create([
            'name' => 'stage1-test', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => true,
            'eeprom_firmware' => 'default/pieeprom-2026-09-23.bin',
            'eeprom_settings' => "[all]\nBOOT_UART=0\nPOWER_OFF_ON_HALT=1\nBOOT_ORDER=0xf21\n",
        ]);
        $pre = Script::create(['name' => 'Bench: debug shell', 'script_type' => 'preinstall', 'priority' => 50,
                               'bg' => false, 'script' => "#!/bin/sh\necho hi\n"]);
        $post = Script::create(['name' => 'Bench: SSH key', 'script_type' => 'postinstall', 'priority' => 50,
                                'bg' => false, 'script' => "#!/bin/sh\necho hi\n"]);
        $project->scripts()->sync([$pre->id, $post->id]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        Setting::updateOrCreate(['key' => 'active_eeprom_sha256'], ['value' => str_repeat('c', 64)]);
        return $project;
    }

    protected function cm()
    {
        return Cm::where('serial', self::SERIAL)->firstOrFail();
    }

    protected function progress($phase, $detail = '', $sectors = '')
    {
        $this->get('/scriptexecute?serial='.self::SERIAL.'&progress='.$phase.'&detail='.urlencode($detail).'&sectors='.$sectors)->assertOk();
    }

    protected function postLog($phase, $log, $retcode = 0)
    {
        $file = UploadedFile::fake()->createWithContent('x.log', $log);
        $this->post('/scriptexecute?serial='.self::SERIAL.'&retcode='.$retcode.'&phase='.$phase, ['log' => $file])->assertOk();
    }

    /* The run sensor0 made on 2026-10-09 (times in UTC), the way the module script reports it */
    protected function sensor0Run()
    {
        $this->activeProject();
        $this->clock('13:01:19');
        $this->get(self::START)->assertOk();
        $this->post('/scriptexecute?serial='.self::SERIAL, [
            'eeprom_version' => UploadedFile::fake()->createWithContent('v', "2021/02/16 13:19:00\nversion d6e4b6b7 (release)\n"),
            'eeprom_config' => UploadedFile::fake()->createWithContent('c', "[all]\nBOOT_UART=0\nPOWER_OFF_ON_HALT=0\nBOOT_ORDER=0xf2541\n"),
        ])->assertOk();
        $this->progress('preinstall', self::FLASH);
        $this->clock('13:01:20');
        $this->progress('preinstall', 'Bench: debug shell');
        $this->postLog('preinstall', "Erase/write done.\nVerifying flash... VERIFIED.\nEEPROM_RESULT=written\ndebug shell listening on port 2323\n");
        $this->progress('write', 'Discarding old data');
        $this->clock('13:01:21');
        $this->progress('write', '', 0);
        $this->clock('13:02:00');
        $this->progress('write', '', 3000000);
        $this->clock('13:03:13');
        $this->progress('verify', '', 0);
        $this->clock('13:05:16');
        $this->progress('postinstall', 'Bench: SSH key');
        $this->postLog('postinstall', "Computed SHA256: bbbb\nkey installed\n");
        $this->get('/scriptexecute?serial='.self::SERIAL.'&alldone=1&temp=47.7C&verify=1')->assertOk();
    }

    protected function failedModule(array $attributes, array $timeline)
    {
        $at = Carbon::parse('2026-10-09 13:01:19')->getTimestamp();
        $entries = [];
        foreach ($timeline as $i => $step)
            $entries[] = ['phase' => $step[0], 'detail' => $step[1] ?? null, 'at' => $at + 10 * $i];
        return new Cm(array_merge([
            'serial' => self::SERIAL, 'phase' => 'failed', 'timeline' => $entries, 'progress_total' => self::IMAGE_SIZE,
            'provisioning_started_at' => Carbon::createFromTimestamp($at),
        ], $attributes));
    }

    public function test_the_run_is_kept_as_a_timeline()
    {
        $this->sensor0Run();

        $timeline = $this->cm()->timeline;
        $this->assertSame([
            ['preinstall', null], ['preinstall', self::FLASH], ['preinstall', 'Bench: debug shell'],
            ['write', null], ['write', 'Discarding old data'], ['write', null],
            ['verify', null], ['postinstall', 'Bench: SSH key'], ['done', null],
        ], array_map(function ($e) { return [$e['phase'], $e['detail']]; }, $timeline));
        $this->assertSame(Carbon::parse('2026-10-09 13:01:19')->getTimestamp(), $timeline[0]['at']);
        $this->assertSame(Carbon::parse('2026-10-09 13:05:16')->getTimestamp(), $timeline[8]['at']);
    }

    public function test_reports_within_a_step_add_nothing_and_a_new_run_starts_over()
    {
        $this->sensor0Run();
        $this->assertCount(9, $this->cm()->timeline);

        $this->clock('14:00:00');
        $this->get(self::START)->assertOk();

        $this->assertSame([['phase' => 'preinstall', 'detail' => null, 'at' => Carbon::parse('2026-10-09 14:00:00')->getTimestamp()]],
                          $this->cm()->timeline);
    }

    public function test_steps_carry_durations_and_speeds_without_zero_length_hand_overs()
    {
        $this->sensor0Run();

        $steps = array_map(function ($s) {
            return [$s['label'], $s['detail'], $s['at']->format('H:i:s'), $s['seconds'], $s['speed'] ? round($s['speed']) : null];
        }, RunTimeline::steps($this->cm()));

        $this->assertSame([
            ['Pre-install', self::FLASH, '13:01:19', 1, null],
            ['Pre-install', 'Bench: debug shell', '13:01:20', 0, null],
            ['Writing image', 'Discarding old data', '13:01:20', 1, null],
            ['Writing image', null, '13:01:21', 112, round(self::IMAGE_SIZE / 112)],
            ['Verifying', null, '13:03:13', 123, round(self::IMAGE_SIZE / 123)],
            ['Post-install', 'Bench: SSH key', '13:05:16', 0, null],
            ['Done', null, '13:05:16', null, null],
        ], $steps);
    }

    public function test_a_write_that_failed_has_the_speed_of_what_it_wrote()
    {
        $cm = $this->failedModule(['progress_bytes' => 100 * 1048576, 'phase_detail' => 'Error during dd. Return code 1.'],
                                  [['preinstall', 'x'], ['write', null], ['failed', 'Error during dd. Return code 1.']]);

        $write = RunTimeline::steps($cm)[1];
        $this->assertSame('Writing image', $write['label']);
        $this->assertEquals(100 * 1048576 / 10, $write['speed']);
    }

    public function test_the_card_needs_a_login()
    {
        $this->get('/cms/'.self::SERIAL)->assertRedirect('/login');
    }

    public function test_an_unknown_module_has_no_card()
    {
        $this->actingAs(User::factory()->create())->get('/cms/1000000000000bad')->assertNotFound();
    }

    public function test_the_card_shows_the_run_the_bootloader_the_logs_and_the_history()
    {
        $this->sensor0Run();
        Cmlog::create(['cm' => '10000000aaaaaaaa', 'loglevel' => 'info', 'msg' => 'Another module started.']);

        $this->actingAs(User::factory()->create())->get('/cms/'.self::SERIAL)
            ->assertOk()
            ->assertSee('e4:5f:01:62:9f:d3')
            ->assertSeeInOrder([self::FLASH, 'Writing image', '1:52', 'Verifying', '2:03', 'Done'])
            ->assertSee('16:01:19')                                    // local time
            ->assertSeeInOrder(['2021-02-16', '2026-09-23', 'written'])
            ->assertSee('data-diff="removed">BOOT_ORDER=0xf2541', false)
            ->assertSee('data-diff="added">BOOT_ORDER=0xf21', false)
            ->assertDontSee('data-diff="added">BOOT_UART=0', false)    // unchanged lines are not marked
            ->assertSee('debug shell listening on port 2323')         // pre-install log
            ->assertSee('key installed')                              // post-install log
            ->assertSee('Provisioning completed. Verification successful.')
            ->assertDontSee('Another module started.');
    }

    public function test_the_card_refreshes_itself_while_the_module_works()
    {
        $this->activeProject();
        $this->get(self::START);

        $html = Livewire::test('cm-card', ['serial' => self::SERIAL])->payload['effects']['html'];
        $this->assertStringContainsString('wire:poll', $html);
    }

    public function test_a_failed_module_card_shows_where_it_stopped_and_what_to_do()
    {
        $this->activeProject();
        $this->clock('13:01:19');
        $this->get(self::START);
        $this->postLog('preinstall', "EEPROM_RESULT=identical\n");
        $this->progress('write', '', 0);
        $this->clock('13:01:40');
        $this->postLog('dd', "curl exit code 18\n", 1);

        $this->actingAs(User::factory()->create())->get('/cms/'.self::SERIAL)
            ->assertOk()
            ->assertSee('Failed during')
            ->assertSee('Writing image')
            ->assertSee('connection to the provisioning server was closed')
            ->assertSee('Restart the module');
    }

    public static function failures()
    {
        return [
            'refused: hash not ready' => [
                ['phase_detail' => 'Verification enabled, but uncompressed SHA256 not computed yet, try again later...'],
                [['failed', 'Verification enabled, but uncompressed SHA256 not computed yet, try again later...']],
                null, 'Images page'],
            'refused: no storage' => [
                ['phase_detail' => 'Missing eMMC/SD card.'], [['failed', 'Missing eMMC/SD card.']], null, 'no eMMC'],
            'server error' => [
                ['phase_detail' => 'Provisioning server error: boom (X.php:1)'], [['failed', 'Provisioning server error: boom (X.php:1)']],
                null, 'laravel.log'],
            'eeprom: no chip' => [
                ['phase_detail' => 'Error during preinstall. Return code 1.', 'eeprom_result' => 'failed',
                 'pre_script_output' => "flashrom v1.1\nNo EEPROM/flash device found.\n"],
                [['preinstall', self::FLASH], ['failed', 'Error during preinstall. Return code 1.']],
                'Pre-install: '.self::FLASH, 'SPI flash'],
            'eeprom: image differs from the project' => [
                ['phase_detail' => 'Error during preinstall. Return code 1.', 'eeprom_result' => 'failed',
                 'pre_script_output' => "pieeprom.bin: FAILED\nsha256sum: WARNING: 1 computed checksum did NOT match\n"],
                [['preinstall', self::FLASH], ['failed', 'Error during preinstall. Return code 1.']],
                'Pre-install: '.self::FLASH, 'Activate the project again'],
            'eeprom: download' => [
                ['phase_detail' => 'Error during preinstall. Return code 7.', 'eeprom_result' => 'failed',
                 'pre_script_output' => "curl: (7) Failed to connect to 172.20.0.1 port 80: Connection refused\n"],
                [['preinstall', self::FLASH], ['failed', 'Error during preinstall. Return code 7.']],
                'Pre-install: '.self::FLASH, 'could not download'],
            'user pre-install script' => [
                ['phase_detail' => 'Error during preinstall. Return code 3.', 'eeprom_result' => 'written'],
                [['preinstall', self::FLASH], ['preinstall', 'Set serial'], ['failed', 'Error during preinstall. Return code 3.']],
                'Pre-install: Set serial', "'Set serial' exited with code 3"],
            'image write' => [
                ['phase_detail' => 'Error during dd. Return code 1. Diagnosis: the storage device reported an I/O error (failing eMMC/SD card or power problem).'],
                [['write', null], ['failed', 'Error during dd. Return code 1.']],
                'Writing image', 'I/O error'],
            'verification' => [
                ['phase_detail' => 'Error during postinstall. Return code 1.'],
                [['write', null], ['verify', null], ['failed', 'Error during postinstall. Return code 1.']],
                'Verifying', 'does not match'],
            'user post-install script' => [
                ['phase_detail' => 'Error during postinstall. Return code 2.'],
                [['verify', null], ['postinstall', 'Bench: SSH key'], ['failed', 'Error during postinstall. Return code 2.']],
                'Post-install: Bench: SSH key', "'Bench: SSH key' exited with code 2"],
        ];
    }

    /** @dataProvider failures */
    public function test_every_kind_of_failure_gets_its_step_and_advice($attributes, $timeline, $step, $advice)
    {
        $result = FailureAdvice::for($this->failedModule($attributes, $timeline));

        $this->assertSame($step, $result['step']);
        $this->assertTrue(Str::contains($result['advice'], $advice), 'advice: '.$result['advice']);
    }

    public function test_a_silent_module_gets_advice_and_a_working_one_none()
    {
        $this->clock('13:20:00');
        $silent = new Cm(['serial' => self::SERIAL, 'phase' => 'write', 'phase_started_at' => Carbon::parse('2026-10-09 13:01:21'),
                          'progress_updated_at' => Carbon::parse('2026-10-09 13:02:00'), 'timeline' => []]);
        $this->assertTrue(Str::contains(FailureAdvice::for($silent)['advice'], 'power'));

        $silent->progress_updated_at = Carbon::parse('2026-10-09 13:19:58');
        $this->assertNull(FailureAdvice::for($silent));
    }

    public function test_dashboard_log_and_module_list_link_to_the_card()
    {
        $this->sensor0Run();
        $link = route('cm', self::SERIAL);

        Livewire::test(ProvisioningStatus::class)->assertSee($link, false);
        Livewire::test(ProvisioningLog::class)->assertSee($link, false);
        Livewire::test(Cms::class)->set('projectId', 0)->assertSee($link, false);
    }
}
