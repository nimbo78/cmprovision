<?php

namespace Tests\Feature;

use App\Events\CmProvisioningComplete;
use App\Events\CmProvisioningFailed;
use App\Events\CmProvisioningStarted;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Project;
use App\Models\Setting;
use App\Services\EepromImage;
use App\Services\UsbPrep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** 2.12: a board connected over USB gets only the bootloader, with network boot first; the image is for the stand */
class UsbPrepTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const MAC = 'e4:5f:01:62:9f:d3';
    const USB = '/scriptexecute?serial=' . self::SERIAL . '&model=CM4&storagesize=62500000&mac=' . self::MAC . '&bootmode=3';
    const NET = '/scriptexecute?serial=' . self::SERIAL . '&model=CM4&storagesize=62500000&mac=' . self::MAC . '&bootmode=2';

    protected $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cmprovision-prep-' . uniqid();
        mkdir($this->dir . '/default', 0755, true);
        config(['cmprovision.firmware_dir' => $this->dir]);
        file_put_contents($this->dir . '/default/pieeprom-2026-09-23.bin', ProjectActivationTest::eepromImage("[all]\nBOOT_UART=0\n"));
        file_put_contents($this->dir . '/default/pieeprom-2025-05-08.bin', ProjectActivationTest::eepromImage("[all]\nBOOT_UART=0\n"));
        @unlink(public_path('uploads/pieeprom-usbprep.bin'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        @unlink(public_path('uploads/pieeprom-usbprep.bin'));
        parent::tearDown();
    }

    protected function prep(): UsbPrep
    {
        return $this->app->make(UsbPrep::class);
    }

    protected function activeProject(): Project
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_sha256 = str_repeat('b', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false, 'eeprom_firmware' => null]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);

        return $project;
    }

    public function test_it_is_off_until_somebody_starts_it_and_goes_off_by_itself_after_an_idle_hour()
    {
        $this->assertFalse($this->prep()->active());

        $this->prep()->start();
        $this->assertTrue($this->prep()->active());
        $this->travel(59)->minutes();
        $this->assertTrue($this->prep()->active());
        $this->travel(2)->minutes();
        $this->assertFalse($this->prep()->active(), 'an hour without a board and it is off');
    }

    public function test_a_board_that_is_prepared_gives_the_hour_a_new_start_and_stop_ends_it_at_once()
    {
        $this->prep()->start();
        $this->travel(50)->minutes();
        $this->prep()->touch();
        $this->travel(50)->minutes();
        $this->assertTrue($this->prep()->active());

        $this->prep()->stop();
        $this->assertFalse($this->prep()->active());
        $this->prep()->touch();
        $this->assertFalse($this->prep()->active(), 'touching a stopped one does not start it');
    }

    public function test_it_offers_the_newest_default_firmware_and_boots_from_the_network_first()
    {
        $this->assertSame('default/pieeprom-2026-09-23.bin', $this->prep()->firmware());
        $this->assertStringContainsString("BOOT_ORDER=0xf12\n", $this->prep()->settings());
        $this->assertStringContainsString('[all]', $this->prep()->settings());
    }

    public function test_saving_builds_the_image_with_its_settings_and_remembers_what_it_is()
    {
        $this->prep()->save('default/pieeprom-2025-05-08.bin', "[all]\r\nBOOT_ORDER=0xf2");

        $image = file_get_contents(public_path('uploads/pieeprom-usbprep.bin'));
        $this->assertSame("[all]\nBOOT_ORDER=0xf2\n", EepromImage::getConfig($image));
        $this->assertSame(hash('sha256', $image), $this->prep()->imageSha());
        $this->assertSame('default/pieeprom-2025-05-08.bin', $this->prep()->firmware());
        $this->assertSame("[all]\nBOOT_ORDER=0xf2\n", $this->prep()->settings());
    }

    public function test_a_firmware_that_is_not_in_the_store_is_refused()
    {
        $this->expectException(\RuntimeException::class);
        $this->prep()->save('../../etc/passwd', "[all]\n");
    }

    public function test_it_cannot_start_without_a_firmware_and_says_why()
    {
        File::deleteDirectory($this->dir . '/default');

        $this->assertStringContainsString('firmware', strtolower((string) $this->prep()->problem()));
        try {
            $this->prep()->start();
            $this->fail('it started');
        } catch (\RuntimeException $e) {
            $this->assertFalse($this->prep()->active());
        }
    }

    public function test_starting_makes_the_image_again_when_it_is_gone()
    {
        $this->prep()->save('default/pieeprom-2026-09-23.bin', "[all]\nBOOT_ORDER=0xf12\n");
        unlink(public_path('uploads/pieeprom-usbprep.bin'));

        $this->prep()->start();

        $this->assertFileExists(public_path('uploads/pieeprom-usbprep.bin'));
    }

    public function test_a_board_that_boots_over_usb_gets_the_bootloader_and_nothing_else()
    {
        Event::fake([CmProvisioningStarted::class, CmProvisioningComplete::class, CmProvisioningFailed::class]);
        $this->activeProject();
        $this->prep()->start();

        $script = $this->get(self::USB)->assertOk()->getContent();

        $this->assertStringContainsString('Flash EEPROM firmware', $script);
        $this->assertStringContainsString('uploads/pieeprom-usbprep.bin', $script);
        $this->assertStringContainsString($this->prep()->imageSha(), $script);
        $this->assertStringContainsString('alldone=1', $script);
        $this->assertStringContainsString('finish done', $script);
        foreach (['blkdiscard', 'fetch_image', 'dd ', 'oscheck', 'reboot'] as $imageStep) {
            $this->assertStringNotContainsString($imageStep, $script, $imageStep);
        }

        $cm = Cm::sole();
        $this->assertNotNull($cm->usb_prep_at);
        $this->assertSame(['preinstall', null, null, null], [$cm->phase, $cm->project_id, $cm->image_filename, $cm->notification_batch_id]);
        $this->assertSame($this->prep()->settings(), $cm->eeprom_config_after);
        Event::assertNothingDispatched();
        $this->assertSame('Flash EEPROM firmware (default/pieeprom-2026-09-23.bin)', $cm->phase_detail);
    }

    public function test_the_script_of_a_board_is_valid_shell_and_flashes_before_it_says_ready()
    {
        if (trim((string) shell_exec('command -v sh 2>/dev/null')) === '') {
            $this->markTestSkipped('needs sh');
        }
        $this->prep()->start();
        $script = $this->get(self::USB)->assertOk()->getContent();

        $process = new \Symfony\Component\Process\Process(['sh', '-n'], null, null, $script);
        $process->run();
        $this->assertSame('', $process->getErrorOutput() . $process->getOutput());
        $this->assertSame(0, $process->getExitCode());

        $order = array_map(fn ($needle) => strrpos($script, $needle), [
            'eeprom_version=@', 'sh -v /tmp/pre-0.sh', 'phase=preinstall', 'led_take_act', 'alldone=1', 'finish done']);
        $this->assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'the bootloader is read, flashed and reported before the board is done');
    }

    public function test_the_card_of_a_prepared_board_knows_the_firmware_it_got()
    {
        $this->prep()->start();
        $this->get(self::USB)->assertOk();

        $this->assertSame('default/pieeprom-2026-09-23.bin', Cm::sole()->firmware);
    }

    public function test_a_firmware_that_arrives_while_the_mode_is_on_is_what_the_next_board_gets()
    {
        $this->prep()->start();
        $before = $this->prep()->imageSha();
        file_put_contents($this->dir . '/default/pieeprom-2026-10-01.bin', ProjectActivationTest::eepromImage("[all]\nBOOT_UART=0\n") . str_repeat("\xff", 16));

        $script = $this->get(self::USB)->assertOk()->getContent();

        $this->assertNotSame($before, $this->prep()->imageSha(), 'the image is made again');
        $this->assertStringContainsString($this->prep()->imageSha(), $script);
        $this->assertStringContainsString('pieeprom-2026-10-01.bin', $script);
        $this->assertSame('default/pieeprom-2026-10-01.bin', Cm::sole()->firmware);
    }

    public function test_settings_that_do_not_fit_the_bootloader_are_refused_and_what_was_saved_stays()
    {
        $this->prep()->save('default/pieeprom-2026-09-23.bin', "[all]\nBOOT_ORDER=0xf2\n");
        $sha = $this->prep()->imageSha();

        try {
            $this->prep()->save('default/pieeprom-2025-05-08.bin', "[all]\n" . str_repeat("# a comment line\n", 200));
            $this->fail('it was saved');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('2024', $e->getMessage());
        }

        $this->assertSame([$sha, 'default/pieeprom-2026-09-23.bin', "[all]\nBOOT_ORDER=0xf2\n"],
            [$this->prep()->imageSha(), $this->prep()->firmware(), $this->prep()->settings()]);
    }

    public function test_a_board_that_was_prepared_is_not_a_done_board_of_the_stand()
    {
        $cm = Cm::create(['serial' => self::SERIAL, 'mac' => self::MAC, 'phase' => 'done', 'usb_prep_at' => now(), 'polled_at' => now()]);
        $this->assertSame('unknown', \App\Support\StandMap::moduleState($cm));

        $module = collect(app(\App\Support\WallboardSnapshot::class)->current()['modules'])->firstWhere('serial', self::SERIAL);
        $this->assertSame(['ready', 'unknown'], [$module['phase'], $module['state']]);

        $cm->update(['usb_prep_at' => null]);
        $this->assertSame('ok', \App\Support\StandMap::moduleState($cm->fresh()));
    }

    public function test_a_board_on_the_network_is_provisioned_as_always_while_the_mode_is_on()
    {
        $this->activeProject();
        $this->prep()->start();

        $this->get(self::NET)->assertOk()->assertSee('blkdiscard', false);

        $this->assertNull(Cm::sole()->usb_prep_at);
        $this->assertNotNull(Cm::sole()->project_id);
    }

    public function test_with_the_mode_off_a_usb_board_is_provisioned_as_always()
    {
        $this->activeProject();

        $this->get(self::USB)->assertOk()->assertSee('blkdiscard', false);

        $this->assertNull(Cm::sole()->usb_prep_at);
    }

    public function test_when_it_is_done_the_board_is_done_and_the_stand_is_not_told()
    {
        Event::fake([CmProvisioningStarted::class, CmProvisioningComplete::class, CmProvisioningFailed::class]);
        $this->prep()->start();
        $this->get(self::USB)->assertOk();

        $this->get('/scriptexecute?serial=' . self::SERIAL . '&alldone=1&temp=45.0')->assertOk();

        $cm = Cm::sole()->fresh();
        $this->assertSame('done', $cm->phase);
        $this->assertStringContainsString('plug the board into the PoE switch', $cm->phase_detail);
        $this->assertNotNull($cm->provisioning_complete_at);
        $this->assertTrue(Cmlog::where('cm', self::MAC)->where('msg', 'like', 'USB prep completed%')->exists());
        Event::assertNothingDispatched();
    }

    public function test_a_flash_that_fails_fails_the_board_and_tells_nobody_but_the_log_and_the_leds()
    {
        Event::fake([CmProvisioningStarted::class, CmProvisioningComplete::class, CmProvisioningFailed::class]);
        $this->prep()->start();
        $this->get(self::USB)->assertOk();

        $log = UploadedFile::fake()->createWithContent('pre.log', "flashrom: Error: Could not open the device\n");
        $this->post('/scriptexecute?serial=' . self::SERIAL . '&retcode=1&phase=preinstall', ['log' => $log])->assertOk();

        $cm = Cm::sole()->fresh();
        $this->assertSame(['failed', 'failed'], [$cm->phase, $cm->eeprom_result]);
        Event::assertNothingDispatched();
    }

    public function test_a_board_without_a_bootloader_to_flash_is_refused_with_the_reason()
    {
        Event::fake([CmProvisioningStarted::class, CmProvisioningComplete::class, CmProvisioningFailed::class]);
        $this->prep()->start();
        File::deleteDirectory($this->dir . '/default');
        unlink(public_path('uploads/pieeprom-usbprep.bin'));

        $this->get(self::USB)->assertOk()->assertSee('USB prep cannot flash the bootloader', false);

        $this->assertSame('failed', Cm::sole()->phase);
        Event::assertNothingDispatched();
    }

    public function test_each_prepared_board_gives_the_hour_a_new_start()
    {
        $this->prep()->start();
        $this->travel(55)->minutes();

        $this->get(self::USB)->assertOk();
        $this->travel(55)->minutes();

        $this->assertTrue($this->prep()->active());
    }

    public function test_the_board_that_was_prepared_is_provisioned_normally_later_and_is_not_a_prepared_one_any_more()
    {
        $this->activeProject();
        $this->prep()->start();
        $this->get(self::USB)->assertOk();
        $this->assertNotNull(Cm::sole()->usb_prep_at);

        $this->get(self::NET)->assertOk();

        $this->assertNull(Cm::sole()->usb_prep_at);
        $this->assertSame(1, Cm::count());
    }
}
