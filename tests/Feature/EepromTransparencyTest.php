<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Image;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the module's bootloader was before provisioning and what it is after. The module reports the
 * running bootloader (version and settings) before any pre-install script, the generated flash
 * script says whether flashrom wrote the EEPROM or found it identical, and the server keeps both
 * sides for the module card and the notifications.
 */
class EepromTransparencyTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3';
    const VERSION_2021 = "2021/02/16 13:19:00\nversion d6e4b6b7a7b8a9c0d1e2f3a4b5c6d7e8f9a0b1c2 (release)\ntimestamp 1613481540\nupdate-time 0\ncapabilities 0x0000003f\n";
    const VERSION_2026 = "2026/09/23 12:02:14\nversion 0a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d (release)\ntimestamp 1790157734\nupdate-time 1791544000\ncapabilities 0x0000007f\n";
    const CONFIG_2021 = "[all]\nBOOT_UART=0\nWAKE_ON_GPIO=1\nPOWER_OFF_ON_HALT=0\nBOOT_ORDER=0xf2541\n";

    protected function activeProject($firmware = 'default/pieeprom-2026-09-23.bin')
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_sha256 = str_repeat('b', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();

        $project = Project::create([
            'name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false,
            'eeprom_firmware' => $firmware,
            /* as a browser sends it: CRLF, no newline at the end */
            'eeprom_settings' => $firmware ? "[all]\r\nBOOT_UART=0\r\nBOOT_ORDER=0xf21" : null,
        ]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        Setting::updateOrCreate(['key' => 'active_eeprom_sha256'], ['value' => str_repeat('c', 64)]);
        return $project;
    }

    protected function cm()
    {
        return Cm::where('serial', self::SERIAL)->firstOrFail();
    }

    protected function reportBootloader($version = self::VERSION_2021, $config = self::CONFIG_2021)
    {
        return $this->post('/scriptexecute?serial='.self::SERIAL, [
            'eeprom_version' => UploadedFile::fake()->createWithContent('eeprom_version', $version),
            'eeprom_config' => UploadedFile::fake()->createWithContent('eeprom_config', $config),
        ])->assertOk();
    }

    protected function preinstallLog($log, $retcode = 0)
    {
        $file = UploadedFile::fake()->createWithContent('pre.log', $log);
        return $this->post('/scriptexecute?serial='.self::SERIAL.'&retcode='.$retcode.'&phase=preinstall', ['log' => $file])->assertOk();
    }

    /** Runs the generated flash script the way the module does (sh -v, output into the log) with
        stand-ins for curl, sha256sum and flashrom. Returns the log and the exit code. */
    protected function runFlashScript($moduleScript, $flashrom)
    {
        $found = preg_match('/cat >\/tmp\/pre-0\.sh << "CMPROVISIONINGEOF"\n(.*?)\nCMPROVISIONINGEOF/s', $moduleScript, $m);
        $this->assertSame(1, $found, 'the module script writes the flash script to /tmp/pre-0.sh');

        $dir = sys_get_temp_dir().'/cmprovision-flash-'.uniqid();
        mkdir($dir.'/bin', 0755, true);
        file_put_contents($dir.'/pre-0.sh', $m[1]."\n");
        $fakes = [
            'curl' => "#!/bin/sh\nwhile [ \$# -gt 0 ]; do if [ \"\$1\" = -o ]; then shift; echo image > \"\$1\"; fi; shift; done\n",
            'sha256sum' => "#!/bin/sh\ncat >/dev/null\necho 'pieeprom.bin: OK'\n",
            /* what flashrom 1.1 in the utility OS prints */
            'flashrom' => "#!/bin/sh\n"
                ."echo 'flashrom v1.1 on Linux 5.4.83-scriptexec (armv7l)'\n"
                ."if [ \"\$FAKE_FLASHROM\" = fail ]; then echo 'No EEPROM/flash device found.'; exit 1; fi\n"
                ."echo 'Found Winbond flash chip \"W25X40\" (512 kB, SPI) on linux_spi.'\n"
                ."echo 'Reading old flash chip contents... done.'\n"
                ."echo 'Erasing and writing flash chip... '\n"
                ."if [ \"\$FAKE_FLASHROM\" = identical ]; then echo 'Warning: Chip content is identical to the requested image.'; fi\n"
                ."echo 'Erase/write done.'\n"
                ."if [ \"\$FAKE_FLASHROM\" = written ]; then echo 'Verifying flash... VERIFIED.'; fi\n",
        ];
        foreach ($fakes as $name => $body)
        {
            file_put_contents($dir.'/bin/'.$name, $body);
            chmod($dir.'/bin/'.$name, 0755);
        }

        exec('cd '.escapeshellarg($dir).' && FAKE_FLASHROM='.escapeshellarg($flashrom)
             .' PATH='.escapeshellarg($dir.'/bin').':"$PATH" sh -v pre-0.sh >pre.log 2>&1', $ignored, $code);
        $log = "===\nRunning pre-installation script 'Flash EEPROM firmware (default/pieeprom-2026-09-23.bin)'\n===\n"
              .file_get_contents($dir.'/pre.log');
        File::deleteDirectory($dir);
        return [$log, $code];
    }

    public function test_the_module_reports_its_bootloader_before_the_eeprom_is_flashed()
    {
        $this->activeProject();
        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString('vcgencmd bootloader_version >/tmp/eeprom_version', $script);
        $this->assertStringContainsString('vcgencmd bootloader_config >/tmp/eeprom_config', $script);
        $report = strpos($script, "-F 'eeprom_config=@/tmp/eeprom_config'");
        $this->assertNotFalse($report, 'the settings go to the server together with the version');
        $this->assertLessThan(strpos($script, 'sh -v /tmp/pre-0.sh'), $report, 'reported before the flash script runs');
    }

    public function test_the_reported_bootloader_is_the_state_before_and_the_flashed_image_the_state_after()
    {
        $this->activeProject();
        $this->get(self::START);
        $this->reportBootloader();

        $cm = $this->cm();
        $this->assertSame(self::VERSION_2021, $cm->eeprom_before);
        $this->assertSame(self::CONFIG_2021, $cm->eeprom_config_before);
        $this->assertSame('default/pieeprom-2026-09-23.bin', $cm->firmware, 'firmware stays what the EEPROM holds after provisioning');
        $this->assertSame('2021-02-16', $cm->eepromVersionBefore());
        $this->assertSame('2026-09-23', $cm->eepromVersionAfter());
    }

    public function test_without_firmware_in_the_project_the_reported_bootloader_is_also_the_state_after()
    {
        $this->activeProject(null);
        $this->get(self::START);
        $this->reportBootloader();

        $cm = $this->cm();
        $this->assertStringContainsString('2021/02/16 13:19:00', $cm->firmware);
        $this->assertSame('2021-02-16', $cm->eepromVersionBefore());
        $this->assertSame('2021-02-16', $cm->eepromVersionAfter());
        $this->assertNull($cm->eeprom_config_after);
        $this->assertNull($cm->eeprom_result);
    }

    public function test_a_version_read_from_the_flash_chip_is_understood_too()
    {
        $this->activeProject(null);
        $this->get(self::START.'&bootmode=3');
        $this->reportBootloader("VERSION:d6e4b6b7a7b8a9c0\nBUILD_TIMESTAMP=1613481540\n", '');

        $cm = $this->cm();
        $this->assertSame('2021-02-16', $cm->eepromVersionBefore());
        $this->assertNull($cm->eeprom_config_before);
    }

    public function test_an_error_from_vcgencmd_is_not_taken_for_settings()
    {
        $this->activeProject();
        $this->get(self::START);
        $this->reportBootloader(self::VERSION_2021, "error=1 error_msg=\"Command not registered\"\n");

        $this->assertSame(self::VERSION_2021, $this->cm()->eeprom_before, 'the version is still taken');
        $this->assertNull($this->cm()->eeprom_config_before);
    }

    public function test_start_records_the_settings_to_be_flashed_and_forgets_the_previous_run()
    {
        $this->activeProject();
        Cm::create(['serial' => self::SERIAL, 'mac' => 'e4:5f:01:62:9f:d3', 'eeprom_before' => self::VERSION_2021,
                    'eeprom_config_before' => self::CONFIG_2021, 'eeprom_result' => 'written']);

        $this->get(self::START)->assertOk();

        $cm = $this->cm();
        $this->assertNull($cm->eeprom_before);
        $this->assertNull($cm->eeprom_config_before);
        $this->assertNull($cm->eeprom_result);
        $this->assertSame("[all]\nBOOT_UART=0\nBOOT_ORDER=0xf21\n", $cm->eeprom_config_after, 'stored the way it is flashed');
    }

    public static function flashOutcomes()
    {
        return [
            'flashrom wrote the chip' => ['written', 'written'],
            'chip already held the image' => ['identical', 'identical'],
            'flashrom failed' => ['fail', 'failed'],
        ];
    }

    /** @dataProvider flashOutcomes */
    public function test_the_flash_result_reaches_the_server($flashrom, $expected)
    {
        if (!is_executable('/bin/sh'))
            $this->markTestSkipped('needs a POSIX shell');
        $this->activeProject();
        $script = $this->get(self::START)->getContent();

        list($log, $code) = $this->runFlashScript($script, $flashrom);
        $this->assertSame($expected === 'failed' ? 1 : 0, $code, "flash script output:\n".$log);
        $this->preinstallLog($log, $code);

        $this->assertSame($expected, $this->cm()->eeprom_result);
        if ($expected === 'failed')
            $this->assertNull($this->cm()->eepromVersionAfter(), 'after a failed flash nobody knows what the EEPROM holds');
    }

    public function test_a_failing_user_script_after_the_flash_keeps_the_flash_result()
    {
        $this->activeProject();
        $this->get(self::START);

        $this->preinstallLog("Erase/write done.\nVerifying flash... VERIFIED.\nEEPROM_RESULT=written\n===\nRunning pre-installation script 'Set serial'\n===\nfalse\n", 1);

        $this->assertSame('written', $this->cm()->eeprom_result);
    }

    public function test_the_completion_message_says_how_the_eeprom_changed()
    {
        $this->activeProject();
        NotificationChannel::create(['name' => 'hook', 'type' => 'webhook', 'enabled' => true,
                                     'settings' => ['url' => 'https://hooks.example.org/cm'], 'events' => ['completed']]);
        Http::fake();

        $this->get(self::START);
        $this->reportBootloader();
        $this->preinstallLog("EEPROM_RESULT=written\n");
        $this->get('/scriptexecute?serial='.self::SERIAL.'&alldone=1&temp=50C&verify=0')->assertOk();

        Http::assertSent(function (Request $r) {
            return Str::contains($r['text'], 'EEPROM 2021-02-16 → 2026-09-23')
                && $r['eeprom'] === ['before' => '2021-02-16', 'after' => '2026-09-23', 'result' => 'written'];
        });
    }

    public function test_an_unchanged_eeprom_is_called_so_in_the_completion_message()
    {
        $this->activeProject();
        NotificationChannel::create(['name' => 'hook', 'type' => 'webhook', 'enabled' => true,
                                     'settings' => ['url' => 'https://hooks.example.org/cm'], 'events' => ['completed']]);
        Http::fake();

        $this->get(self::START);
        $this->reportBootloader(self::VERSION_2026, "[all]\nBOOT_UART=0\nBOOT_ORDER=0xf21\n");
        $this->preinstallLog("EEPROM_RESULT=identical\n");
        $this->get('/scriptexecute?serial='.self::SERIAL.'&alldone=1&temp=50C&verify=0')->assertOk();

        Http::assertSent(function (Request $r) {
            return Str::contains($r['text'], 'EEPROM 2026-09-23 (unchanged)');
        });
    }
}
