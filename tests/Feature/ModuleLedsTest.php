<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The module shows where it is on its two LEDs (ACT by the Ethernet jack, PWR next to it):
 * in progress ACT blinks fast with PWR on, done the two take turns fading in and out, failed ACT
 * is dim while PWR double-flashes, and on the operator's request both alternate fast for 30 s.
 * The shell functions run here under a real sh, against a fake LED class, LED driver and curl.
 */
class ModuleLedsTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3';

    protected $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/bin/sh'))
            $this->markTestSkipped('needs a POSIX shell');
        $this->dir = sys_get_temp_dir().'/cmprovision-leds-'.uniqid();
        foreach (['led0' => 'mmc0', 'led1' => 'default-on'] as $led => $trigger)
        {
            mkdir($this->dir.'/leds/'.$led, 0755, true);
            foreach (['trigger' => $trigger, 'brightness' => '0', 'delay_on' => '', 'delay_off' => ''] as $file => $value)
                file_put_contents($this->dir.'/leds/'.$led.'/'.$file, $value);
        }
        mkdir($this->dir.'/driver/leds', 0755, true);
        mkdir($this->dir.'/bin');
        mkdir($this->dir.'/tmp');
        /* a stand-in for the helper: notes the pattern it was started with, then runs until killed */
        file_put_contents($this->dir.'/helper', "#!/bin/sh\n[ \"\$1\" = check ] && exit 0\necho \"\$1\" >> {$this->dir}/patterns\nexec sleep 30\n");
        chmod($this->dir.'/helper', 0755);
    }

    protected function tearDown(): void
    {
        exec('pkill -f '.escapeshellarg($this->dir.'/tmp/ledpattern').' 2>/dev/null');
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** curl answers the identify poll with $poll, and serves the helper only when $serveHelper */
    protected function fakeCurl($poll = '', $serveHelper = false)
    {
        $serve = $serveHelper ? "cp {$this->dir}/helper \"\$out\"" : 'exit 7';
        file_put_contents($this->dir.'/bin/curl', "#!/bin/sh\n"
            ."echo \"\$*\" >> {$this->dir}/curl.log\n"
            ."out=''; prev=''; for a in \"\$@\"; do [ \"\$prev\" = -o ] && out=\$a; prev=\$a; done\n"
            ."case \"\$*\" in *identify=poll*) printf '%s' ".escapeshellarg($poll)." ;; *) $serve ;; esac\n");
        chmod($this->dir.'/bin/curl', 0755);
    }

    protected function installHelper()
    {
        copy($this->dir.'/helper', $this->dir.'/tmp/ledpattern');
        chmod($this->dir.'/tmp/ledpattern', 0755);
    }

    /** Runs the LED functions with $commands after them; returns the output */
    protected function sh($commands)
    {
        if (!file_exists($this->dir.'/bin/curl'))
            $this->fakeCurl();
        $script = view('scriptexecute.leds', ['server' => '172.20.0.1', 'serial' => self::SERIAL])->render();
        file_put_contents($this->dir.'/leds.sh', $script."\n".$commands."\n".'[ -n "$LED_PID" ] && kill "$LED_PID"'."\n");
        exec('cd '.escapeshellarg($this->dir).' && PATH='.escapeshellarg($this->dir.'/bin').':"$PATH"'
             .' LEDS='.escapeshellarg($this->dir.'/leds').' LEDS_DRIVER='.escapeshellarg($this->dir.'/driver')
             .' LED_TMP='.escapeshellarg($this->dir.'/tmp').' LED_IDENTIFY_SECONDS=0 LED_POLL_SECONDS=0 sh leds.sh 2>&1', $out);
        return implode("\n", $out);
    }

    protected function led($led, $file)
    {
        return trim(file_get_contents($this->dir.'/leds/'.$led.'/'.$file));
    }

    protected function patterns()
    {
        $file = $this->dir.'/patterns';
        return file_exists($file) ? explode("\n", trim(file_get_contents($file))) : [];
    }

    public function test_in_progress_act_blinks_fast_and_pwr_is_on()
    {
        $this->sh('led_mode progress');

        $this->assertSame(['timer', '50', '50'], [$this->led('led0', 'trigger'), $this->led('led0', 'delay_on'), $this->led('led0', 'delay_off')]);
        $this->assertSame(['none', '1'], [$this->led('led1', 'trigger'), $this->led('led1', 'brightness')]);
    }

    public function test_act_is_taken_back_from_the_eeprom_spi_bus_once()
    {
        $out = $this->sh('led_take_act; rm "$LEDS_DRIVER/unbind" "$LEDS_DRIVER/bind"; led_take_act; [ -e "$LEDS_DRIVER/unbind" ] && echo rebound twice');

        $this->assertStringNotContainsString('rebound twice', $out);
        $this->assertFileExists($this->dir.'/tmp/led-act-taken');
    }

    public function test_the_led_driver_is_rebound_by_its_device_name()
    {
        $this->sh('led_take_act');

        $this->assertSame('leds', trim(file_get_contents($this->dir.'/driver/unbind')));
        $this->assertSame('leds', trim(file_get_contents($this->dir.'/driver/bind')));
    }

    public function test_without_the_helper_the_final_states_use_kernel_triggers()
    {
        $this->sh('led_mode done');
        $this->assertSame(['timer', '1500', 'timer', '1500'],
            [$this->led('led0', 'trigger'), $this->led('led0', 'delay_on'), $this->led('led1', 'trigger'), $this->led('led1', 'delay_off')]);

        $this->sh('led_mode fail');
        $this->assertSame(['none', '0', 'heartbeat'], [$this->led('led0', 'trigger'), $this->led('led0', 'brightness'), $this->led('led1', 'trigger')]);

        $this->sh('led_mode identify');
        $this->assertSame(['timer', '150', 'timer', '150'],
            [$this->led('led0', 'trigger'), $this->led('led0', 'delay_on'), $this->led('led1', 'trigger'), $this->led('led1', 'delay_on')]);
        $this->assertFileExists($this->dir.'/tmp/led-act-taken', 'the final states need ACT back from the SPI bus');
    }

    public function test_the_helper_shows_the_final_states_and_only_one_runs_at_a_time()
    {
        $this->installHelper();

        $out = $this->sh('led_mode done; first=$LED_PID; sleep 0.3; led_mode fail; sleep 0.3; '
                        .'kill -0 "$first" 2>/dev/null && echo "first helper still running"; '
                        .'kill -0 "$LED_PID" 2>/dev/null && echo "second helper running"');

        $this->assertSame(['done', 'fail'], $this->patterns());
        $this->assertStringNotContainsString('first helper still running', $out);
        $this->assertStringContainsString('second helper running', $out);
    }

    public function test_init_fetches_the_helper_from_the_server_and_shows_progress()
    {
        $this->fakeCurl('', true);

        $this->sh('led_init');

        $this->assertFileExists($this->dir.'/tmp/ledpattern');
        $this->assertStringContainsString('http://172.20.0.1/tools/ledpattern', file_get_contents($this->dir.'/curl.log'));
        $this->assertSame('timer', $this->led('led0', 'trigger'));
    }

    public function test_init_without_the_helper_still_shows_progress()
    {
        $this->sh('led_init');

        $this->assertFileDoesNotExist($this->dir.'/tmp/ledpattern');
        $this->assertSame(['timer', 'none', '1'], [$this->led('led0', 'trigger'), $this->led('led1', 'trigger'), $this->led('led1', 'brightness')]);
    }

    public function test_the_module_blinks_when_the_operator_asks_and_returns_to_its_state()
    {
        $this->installHelper();
        $this->fakeCurl('identify');

        $this->sh('led_mode done; sleep 0.2; led_poll_once done; sleep 0.2');

        $this->assertSame(['done', 'identify', 'done'], $this->patterns());
        $this->assertStringContainsString('serial='.self::SERIAL.'&identify=poll', file_get_contents($this->dir.'/curl.log'));
    }

    public function test_without_a_request_the_module_keeps_its_state()
    {
        $this->installHelper();
        $this->fakeCurl('');

        $this->sh('led_mode fail; sleep 0.2; led_poll_once fail; sleep 0.2');

        $this->assertSame(['fail'], $this->patterns());
    }

    protected function activeProject($withScripts = true)
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_sha256 = str_repeat('b', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => 'p', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
                                    'label_moment' => 'never', 'verify' => true]);
        if ($withScripts)
        {
            $pre = Script::create(['name' => 'pre', 'script_type' => 'preinstall', 'priority' => 50, 'bg' => false, 'script' => "#!/bin/sh\necho pre\n"]);
            $post = Script::create(['name' => 'post', 'script_type' => 'postinstall', 'priority' => 50, 'bg' => false, 'script' => "#!/bin/sh\necho post\n"]);
            $project->scripts()->sync([$pre->id, $post->id]);
        }
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    public function test_the_module_script_shows_progress_at_once_and_takes_act_after_the_pre_install_scripts()
    {
        $this->activeProject();
        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString('led_mode()', $script, 'the LED functions are part of the script');
        $init = strpos($script, "\nled_init\n");
        $take = strpos($script, "\nled_take_act\n");
        $this->assertNotFalse($init);
        $this->assertNotFalse($take);
        $this->assertLessThan(strpos($script, 'sh -v /tmp/pre-'), $init, 'progress is shown before the first script runs');
        $this->assertGreaterThan(strrpos($script, 'phase=preinstall"'), $take, 'flashrom may need the SPI bus until the pre-install scripts are done');
        $this->assertLessThan(strpos($script, 'Writing image from'), $take);
    }

    public function test_every_failure_ends_in_the_fail_state_and_success_in_the_done_state()
    {
        $this->activeProject();
        $script = $this->get(self::START)->getContent();

        $this->assertDoesNotMatchRegularExpression('/^\s*exit 1\s*$/m', $script, 'a failed module keeps showing its state instead of exiting');
        $failures = substr_count($script, 'retcode=$RETCODE&phase=');
        $this->assertSame(4, $failures, 'the pre-install script, the image write, the verification and the post-install script');
        $this->assertSame($failures, preg_match_all('/retcode=\$RETCODE&phase=\w+"\n    finish fail\n/', $script), 'each failure report is followed by the fail state');
        $this->assertGreaterThan(strpos($script, '&alldone=1'), strpos($script, "\nfinish done\n"));
    }

    public function test_a_refused_module_shows_the_fail_state()
    {
        $this->activeProject();
        $script = $this->get('/scriptexecute?serial='.self::SERIAL.'&model=CM4&mac=e4:5f:01:62:9f:d3')->assertOk()->getContent();

        $this->assertStringContainsString("echo 'Missing eMMC/SD card.'", $script);
        $this->assertStringContainsString('led_mode()', $script);
        $this->assertStringContainsString("\nled_init\n", $script);
        $this->assertStringContainsString("\nfinish fail\n", $script);
    }

    public function test_the_module_script_with_the_led_functions_parses_under_sh()
    {
        $this->activeProject();
        $script = $this->get(self::START)->getContent();

        exec('printf %s '.escapeshellarg($script).' | sh -n 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString("}\nled_init\n", $script, 'the include keeps its own lines');
    }

    public function test_the_refusal_script_runs_under_sh()
    {
        $this->activeProject();
        $script = $this->get('/scriptexecute?serial='.self::SERIAL.'&model=CM4&mac=e4:5f:01:62:9f:d3')->getContent();

        exec('printf %s '.escapeshellarg($script).' | sh -n 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
