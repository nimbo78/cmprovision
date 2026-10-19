<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The init of our utility OS (utility-os/board/cmprovision/rootfs-overlay/etc/init.d/S99scriptexec) under BusyBox sh:
 * the same start parameters in the same formats as the legacy OS's init, plus uos=. The kernel's files come from a tree in
 * the test's directory (UOS_PROC, UOS_SYS, UOS_TMP), the hardware and the network from stubs. And its two templates.
 */
class UtilityOsInitTest extends TestCase
{
    const APPLETS = ['sh', 'cat', 'tr', 'sed', 'awk', 'od', 'head', 'tail'];
    const VERSION = '2026.10';

    protected $dir, $busybox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->busybox = trim((string) shell_exec('command -v busybox 2>/dev/null'));
        $applets = $this->busybox ? explode("\n", (string) shell_exec(escapeshellarg($this->busybox).' --list')) : [];
        foreach (self::APPLETS as $applet)
            if (!in_array($applet, $applets, true))
                $this->markTestSkipped('needs busybox with '.$applet);
        $this->dir = sys_get_temp_dir().'/cmprovision-uos-init-'.uniqid();
        foreach (['bin', 'tmp', 'responses'] as $sub)
            mkdir($this->dir.'/'.$sub, 0755, true);
        foreach (self::APPLETS as $applet)
            symlink($this->busybox, $this->dir.'/bin/'.$applet);
        // the module's world: every call noted; curl answers from responses/<n>.code and <n>.body
        foreach (['udhcpc', 'ip', 'getty', 'mount'] as $name)
            $this->stub($name, 'echo "'.$name.' $*" >> "$T/calls"');
        $this->stub('sleep', 'echo "sleep $*" >> "$T/calls"');
        $this->stub('vcgencmd', '[ -f "$T/vcgencmd" ] && cat "$T/vcgencmd" || exit 1');
        // pinctrl lev 5 prints the level and a newline (pinctrl.c, raspberrypi/utils)
        $this->stub('pinctrl', '[ "$1" = lev ] && [ -f "$T/gpio/$2" ] || exit 1'."\n".'cat "$T/gpio/$2"; echo');
        $this->stub('curl', implode("\n", [
            'n=$(( $(cat "$T/curl.count" 2>/dev/null || echo 0) + 1 )); echo $n > "$T/curl.count"',
            'for a in "$@"; do printf "%s\n" "$a"; done >> "$T/curl.args"; echo --- >> "$T/curl.args"',
            'out=; while [ $# -gt 0 ]; do [ "$1" = -o ] && out=$2; shift; done',
            'code=$(cat "$T/responses/$n.code" 2>/dev/null || echo 000)',
            '[ "$code" = 000 ] && { printf 000; exit 7; }',
            'cat "$T/responses/$n.body" > "$out"; printf "%s" "$code"',
            'exit $(cat "$T/responses/$n.exit" 2>/dev/null || echo 0)',
        ]));
        $this->world();
    }

    protected function tearDown(): void
    {
        if ($this->dir)
            File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function stub(string $name, string $body): void
    {
        file_put_contents($this->dir.'/bin/'.$name, "#!/bin/sh\nT=".escapeshellarg($this->dir)."\n".$body."\n");
        chmod($this->dir.'/bin/'.$name, 0755);
    }

    protected static function path(string $file): string
    {
        return dirname(__DIR__, 2).'/utility-os/board/cmprovision/'.$file;
    }

    /** the kernel command line the firmware hands over: its own words, then cmdline.txt of the new OS */
    protected function cmdline(bool $readjumper = true): string
    {
        $line = str_replace('@UOS_VERSION@', self::VERSION, trim(file_get_contents(self::path('cmdline.txt'))));
        return 'coherent_pool=1M 8250.nr_uarts=0 '.($readjumper ? $line : str_replace('readjumper ', '', $line));
    }

    /** A CM4 4 GB with eMMC, booted from the network, jumpers 1 0 1; $over replaces (or with null removes) files */
    protected function world(array $over = []): void
    {
        $files = array_merge([
            'proc/cmdline' => $this->cmdline()."\n",
            'proc/cpuinfo' => "processor\t: 0\nBogoMIPS\t: 108.00\n\nRevision\t: d03140\nSerial\t\t: 10000000e611110c\nModel\t\t: Raspberry Pi Compute Module 4 Rev 1.1\n",
            'proc/meminfo' => "MemTotal:        3884356 kB\nMemFree:         3700000 kB\n",
            'proc/device-tree/model' => "Raspberry Pi Compute Module 4 Rev 1.1\0",
            'proc/device-tree/serial-number' => "10000000e611110c\0",
            'proc/device-tree/chosen/bootloader/boot-mode' => "\0\0\0\2",
            'sys/class/net/eth0/address' => "e4:5f:01:62:9f:d3\n",
            'sys/class/net/eth0/carrier' => "1\n",
            'sys/block/mmcblk0/size' => "61071360\n",
            'sys/block/mmcblk0/device/cid' => "1501004a3a3333475012345678abcd00\n",
            'sys/block/mmcblk0/device/csd' => "d02700328f5903ffffffffef8a404000\n",
            'sys/class/thermal/thermal_zone0/temp' => "47900\n",
            'sys/class/leds/ACT/trigger' => "none [mmc0] timer\n",
            'sys/class/leds/ACT/brightness' => "255\n",
            'gpio/5' => '1', 'gpio/13' => '0', 'gpio/21' => '1',
            'vcgencmd' => "temp=48.3'C\n",
            'responses/1.code' => '200',
            'responses/1.body' => 'echo RAN > '.escapeshellarg($this->dir.'/ran')."\nexit 3\n",
        ], $over);
        foreach ($files as $path => $content)
        {
            $file = $this->dir.'/'.$path;
            if ($content === null)
            {
                @unlink($file);
                continue;
            }
            @mkdir(dirname($file), 0755, true);
            file_put_contents($file, $content);
        }
    }

    /** runs the init as rcS does ("start"); its console */
    protected function boot(): string
    {
        $env = ['PATH' => $this->dir.'/bin:'.getenv('PATH'), 'UOS_PROC' => $this->dir.'/proc', 'UOS_SYS' => $this->dir.'/sys',
                'UOS_TMP' => $this->dir.'/tmp'];
        $prefix = '';
        foreach ($env as $name => $value)
            $prefix .= $name.'='.escapeshellarg($value).' ';
        exec($prefix.'timeout 20 '.escapeshellarg($this->busybox).' sh '
            .escapeshellarg(self::path('rootfs-overlay/etc/init.d/S99scriptexec')).' start 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        return implode("\n", $out);
    }

    /** the curl calls: each a list of its arguments */
    protected function curlCalls(): array
    {
        $calls = [];
        foreach (explode("---\n", (string) @file_get_contents($this->dir.'/curl.args')) as $call)
            if ($call !== '')
                $calls[] = explode("\n", rtrim($call, "\n"));
        return $calls;
    }

    protected function startUrl(int $call = 0): string
    {
        return end($this->curlCalls()[$call]);
    }

    public function test_the_start_has_the_legacy_parameters_in_the_legacy_formats()
    {
        $this->boot();

        $this->assertSame('http://172.20.0.1/scriptexecute?serial=10000000e611110c&model=Raspberry+Pi+Compute+Module+4+Rev+1.1'
            .'&storagesize=61071360&mac=e4:5f:01:62:9f:d3&inversejumper=101&memorysize=3884356&temp=48.3\'C'
            .'&cid=1501004a3a3333475012345678abcd00&csd=d02700328f5903ffffffffef8a404000&bootmode=2&uos=2026.10', $this->startUrl());
        $this->assertSame(['-g', '--retry', '10', '--retry-connrefused', '-o', $this->dir.'/tmp/script', '-w', '%{http_code}'],
            array_slice($this->curlCalls()[0], 0, 8));
    }

    public function test_without_readjumper_the_jumpers_stay_unread()
    {
        $this->world(['proc/cmdline' => $this->cmdline(false)."\n"]);

        $this->boot();

        $this->assertStringContainsString('&inversejumper=&memorysize=', $this->startUrl());
    }

    public function test_serial_and_temperature_have_fallbacks()
    {
        // a kernel without the Serial line, a firmware vcgencmd cannot reach
        $this->world(['proc/cpuinfo' => "processor\t: 0\n", 'vcgencmd' => null]);

        $this->boot();

        $this->assertStringContainsString('?serial=10000000e611110c&', $this->startUrl());
        $this->assertStringContainsString("&temp=47.9'C&", $this->startUrl());
    }

    public function test_a_missing_source_leaves_its_value_empty()
    {
        $this->world(['sys/block/mmcblk0/size' => null, 'sys/block/mmcblk0/device/cid' => null, 'sys/block/mmcblk0/device/csd' => null,
                      'proc/device-tree/chosen/bootloader/boot-mode' => null, 'vcgencmd' => null,
                      'sys/class/thermal/thermal_zone0/temp' => null]);

        $this->boot();

        $url = $this->startUrl();
        foreach (['storagesize', 'temp', 'cid', 'csd', 'bootmode'] as $name)
            $this->assertStringContainsString($name.'=', $url);
        $this->assertStringContainsString('&storagesize=&mac=', $url);
        $this->assertStringContainsString('&temp=&cid=&csd=&bootmode=&uos=2026.10', $url);
        $this->assertStringNotContainsString('{', $url, 'every placeholder filled in');
    }

    public function test_a_server_error_is_tried_again_and_its_page_never_runs()
    {
        // 502: php-fpm restarting under a package upgrade; the legacy init ran nginx's error page as the script
        $this->world(['responses/1.code' => '502', 'responses/1.body' => 'echo RAN-502 > '.escapeshellarg($this->dir.'/ran-502')."\n",
                      'responses/2.code' => '200', 'responses/2.body' => 'echo RAN > '.escapeshellarg($this->dir.'/ran')."\n"]);

        $console = $this->boot();

        $this->assertFileDoesNotExist($this->dir.'/ran-502');
        $this->assertFileExists($this->dir.'/ran');
        $this->assertCount(2, $this->curlCalls());
        $this->assertStringContainsString('no answer from the server (HTTP 502): trying again in 10 seconds', $console);
        $this->assertStringContainsString('sleep 10', file_get_contents($this->dir.'/calls'));
    }

    public function test_no_connection_is_tried_again()
    {
        $this->world(['responses/1.code' => null, 'responses/1.body' => null,
                      'responses/2.code' => '200', 'responses/2.body' => 'echo RAN > '.escapeshellarg($this->dir.'/ran')."\n"]);

        $console = $this->boot();

        $this->assertFileExists($this->dir.'/ran');
        $this->assertStringContainsString('(HTTP 000)', $console);
    }

    public function test_an_incomplete_200_download_is_never_executed()
    {
        $this->world(['responses/1.code' => '200', 'responses/1.exit' => '18',
                      'responses/1.body' => 'echo PARTIAL > '.escapeshellarg($this->dir.'/partial')."\n",
                      'responses/2.code' => '200',
                      'responses/2.body' => 'echo COMPLETE > '.escapeshellarg($this->dir.'/complete')."\n"]);

        $console = $this->boot();

        $this->assertFileDoesNotExist($this->dir.'/partial');
        $this->assertFileExists($this->dir.'/complete');
        $this->assertCount(2, $this->curlCalls());
        $this->assertStringContainsString('curl exit 18', $console);
    }

    public function test_another_answer_stops_the_module_without_running_it()
    {
        $this->world(['responses/1.code' => '404', 'responses/1.body' => 'echo RAN > '.escapeshellarg($this->dir.'/ran')."\n"]);

        $console = $this->boot();

        $this->assertFileDoesNotExist($this->dir.'/ran');
        $this->assertCount(1, $this->curlCalls());
        $this->assertStringContainsString('the server answered HTTP 404: stopping', $console);
    }

    public function test_a_script_that_returns_is_not_run_again()
    {
        $console = $this->boot();

        $this->assertFileExists($this->dir.'/ran');
        $this->assertCount(1, $this->curlCalls());
        $this->assertStringContainsString('the script ended with exit code 3: nothing more to do', $console);
    }

    public function test_act_goes_dark_and_a_shell_waits_on_tty2()
    {
        $console = $this->boot();

        $this->assertSame("none\n", file_get_contents($this->dir.'/sys/class/leds/ACT/trigger'));
        $this->assertSame("0\n", file_get_contents($this->dir.'/sys/class/leds/ACT/brightness'));
        $calls = file_get_contents($this->dir.'/calls');
        $this->assertStringContainsString('getty -L tty2 0 vt100', $calls);
        $this->assertStringContainsString('udhcpc -i eth0', $calls);
        $this->assertStringContainsString('Note: shell is available on tty2 for debugging purposes', $console);
    }

    public function test_cmdline_txt_is_the_legacy_one_with_uos_at_the_end()
    {
        $legacy = trim(file_get_contents(dirname(__DIR__, 2).'/scriptexecute/cmdline.txt'));

        $this->assertSame($legacy.'&uos=@UOS_VERSION@', trim(file_get_contents(self::path('cmdline.txt'))));
    }

    public function test_config_txt_is_the_legacy_one_with_the_64_bit_kernel_said_outright()
    {
        $lines = fn (string $file) => array_values(array_filter(array_map('trim', file($file)), fn ($l) => $l !== '' && $l[0] !== '#'));
        $legacy = $lines(dirname(__DIR__, 2).'/scriptexecute/config.txt');
        $ours = $lines(self::path('config.txt'));

        $expected = array_merge(['arm_64bit=1'], str_replace('initramfs scriptexecute.img', 'initramfs scriptexecute.img followkernel', $legacy));
        $this->assertSame($expected, $ours);
    }
}
