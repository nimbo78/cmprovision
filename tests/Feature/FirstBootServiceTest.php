<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Script;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The OS check's service in the written system (resources/views/scriptexecute/firstboot.blade.php), run here under a
 * real sh with stand-ins for systemctl, curl and apt-get, a fake LED class and directories of its own: it waits for
 * the boot, installs the packages, runs the first-boot scripts, reports once, removes itself and shows the result.
 */
class FirstBootServiceTest extends TestCase
{
    const SERIAL = '10000000e611110c';

    protected $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/bin/sh') || !is_executable('/usr/bin/timeout'))
            $this->markTestSkipped('needs a POSIX shell and timeout');
        $this->dir = sys_get_temp_dir().'/cmprovision-firstboot-'.uniqid();
        foreach (['bin', 'lib/scripts', 'systemd/multi-user.target.wants', 'www'] as $sub)
            mkdir($this->dir.'/'.$sub, 0755, true);
        foreach (['ACT' => 'mmc0', 'PWR' => 'default-on'] as $led => $trigger)
        {
            mkdir($this->dir.'/leds/'.$led, 0755, true);
            file_put_contents($this->dir.'/leds/'.$led.'/trigger', $trigger);
            file_put_contents($this->dir.'/leds/'.$led.'/brightness', '0');
        }
        file_put_contents($this->dir.'/systemd/cmprovision-firstboot.service', "[Unit]\n");
        symlink($this->dir.'/systemd/cmprovision-firstboot.service', $this->dir.'/systemd/multi-user.target.wants/cmprovision-firstboot.service');
        file_put_contents($this->dir.'/hostname', "wlanpi-a1b\n");
        $this->stub('systemctl', implode("\n", [
            'case "$*" in',
            '    *--wait*) exit 0 ;;',
            '    is-system-running) echo "${FAKE_STATE:-degraded}"; exit 1 ;;',
            '    *--failed*) echo "firefox-setup.service loaded failed failed Firefox setup" ;;',
            'esac',
        ]));
        // downloads come from $FAKE/www; the report is noted, after as many refusals as $FAKE/refusals says
        $this->stub('curl', implode("\n", [
            'echo "$*" >> "$FAKE/curl.log"',
            'out=; url=; prev=; log=',
            'for a in "$@"; do',
            '    [ "$prev" = -o ] && out=$a',
            '    case "$a" in log=@*) log=${a#log=@} ;; esac',
            '    prev=$a; url=$a',
            'done',
            'case "$url" in',
            '    *firstboot=1*)',
            '        left=$(cat "$FAKE/refusals" 2>/dev/null || echo 0)',
            '        if [ "$left" -gt 0 ]; then echo $((left - 1)) > "$FAKE/refusals"; exit 22; fi',
            '        printf "%s\n" "$@" > "$FAKE/report"',
            '        cp "$log" "$FAKE/report.log" ;;',
            '    *) f="$FAKE/www/${url##*/}"; [ -f "$f" ] || exit 22; cp "$f" "$out" ;;',
            'esac',
        ]));
        $this->stub('apt-get', implode("\n", [
            'echo "$*" >> "$FAKE/apt.log"',
            'for f in "$@"; do case "$f" in *.deb) cat "$f" >> "$FAKE/apt-content" ;; esac; done',
            'exit ${FAKE_APT:-0}',
        ]));
        // the LED helper's stand-in: notes the pattern and returns at once
        file_put_contents($this->dir.'/helper', "#!/bin/sh\n[ \"\$1\" = check ] && exit \${FAKE_HELPER_CHECK:-0}\necho \"\$1\" >> \"\$FAKE/patterns\"\n");
        chmod($this->dir.'/helper', 0755);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function stub($name, $body)
    {
        file_put_contents($this->dir.'/bin/'.$name, "#!/bin/sh\n".$body."\n");
        chmod($this->dir.'/bin/'.$name, 0755);
    }

    protected function script($id, $name, $body)
    {
        $script = new Script(['name' => $name, 'script_type' => 'firstboot', 'script' => $body]);
        $script->id = $id;
        return $script;
    }

    /** A package the server has (its file in www) unless $served is false; $sha256 null: the right one */
    protected function package($file, $content, $served = true, $sha256 = null)
    {
        $onServer = $file.'-on-server.deb';
        if ($served)
            file_put_contents($this->dir.'/www/'.$onServer, $content);
        return new Package(['filename' => $file, 'filename_on_server' => $onServer, 'size' => strlen($content),
            'sha256' => $sha256 ?? hash('sha256', $content), 'deb_package' => 'x', 'deb_version' => '1', 'deb_architecture' => 'arm64']);
    }

    /** Renders the service, puts it where the install step would and runs it as systemd would; its exit code */
    protected function firstBoot(array $scripts = [], array $packages = [], array $env = [], bool $helper = true)
    {
        @mkdir($this->dir.'/lib/scripts', 0755, true);   // a run before removed it
        file_put_contents($this->dir.'/lib/run', view('scriptexecute.firstboot', ['server' => '172.20.0.1', 'serial' => self::SERIAL,
            'scripts' => collect($scripts), 'packages' => collect($packages)])->render());
        foreach ($scripts as $script)
            file_put_contents($this->dir.'/lib/scripts/'.$script->id.'.sh', $script->script);
        if ($helper)
        {
            copy($this->dir.'/helper', $this->dir.'/lib/ledpattern');
            chmod($this->dir.'/lib/ledpattern', 0755);
        }
        $vars = array_merge([
            'FAKE' => $this->dir, 'PATH' => $this->dir.'/bin:'.getenv('PATH'), 'LEDS' => $this->dir.'/leds',
            'FIRSTBOOT_DIR' => $this->dir.'/lib', 'FIRSTBOOT_UNIT' => $this->dir.'/systemd/cmprovision-firstboot.service',
            'FIRSTBOOT_WANTS' => $this->dir.'/systemd/multi-user.target.wants/cmprovision-firstboot.service',
            'FIRSTBOOT_LOG' => $this->dir.'/firstboot.log', 'FIRSTBOOT_HOSTNAME' => $this->dir.'/hostname',
            'FIRSTBOOT_SETTLE' => '5', 'FIRSTBOOT_SCRIPT_SECONDS' => '5', 'FIRSTBOOT_TRIES' => '3',
            'FIRSTBOOT_PAUSE' => '0', 'FIRSTBOOT_GRACE' => '0',
        ], $env);
        $prefix = '';
        foreach ($vars as $name => $value)
            $prefix .= $name.'='.escapeshellarg($value).' ';
        exec($prefix.'sh '.escapeshellarg($this->dir.'/lib/run').' 2>&1', $out, $code);
        return $code;
    }

    /** The report's text fields and its URL; [] when no report arrived */
    protected function reportFields(): array
    {
        if (!file_exists($this->dir.'/report'))
            return [];
        $args = file($this->dir.'/report', FILE_IGNORE_NEW_LINES);
        $fields = ['url' => end($args)];
        foreach ($args as $i => $arg)
            if ($arg === '--form-string' && preg_match('/^(\w+)=(.*)$/s', $args[$i + 1], $m))
                $fields[$m[1]] = $m[2];
        return $fields;
    }

    protected function serviceLog(): string
    {
        return (string) @file_get_contents($this->dir.'/firstboot.log');
    }

    protected function ledPatterns(): string
    {
        return trim((string) @file_get_contents($this->dir.'/patterns'));
    }

    public function test_a_clean_first_boot_reports_and_shows_ready()
    {
        $this->assertSame(0, $this->firstBoot());

        $report = $this->reportFields();
        $this->assertSame('http://172.20.0.1/scriptexecute?serial='.self::SERIAL.'&firstboot=1&retcode=0', $report['url']);
        $this->assertSame(['wlanpi-a1b', 'degraded', 'firefox-setup.service', ''],
            [$report['hostname'], $report['state'], trim($report['failed_units']), $report['reason']]);
        $this->assertStringContainsString('systemd: degraded', file_get_contents($this->dir.'/report.log'));
        $this->assertSame('ready', $this->ledPatterns());
        // once only: the unit, its link and the directory are gone, the log stays
        $this->assertFileDoesNotExist($this->dir.'/systemd/cmprovision-firstboot.service');
        $this->assertFalse(is_link($this->dir.'/systemd/multi-user.target.wants/cmprovision-firstboot.service'));
        $this->assertDirectoryDoesNotExist($this->dir.'/lib');
        $this->assertStringContainsString('host name: wlanpi-a1b', $this->serviceLog());
    }

    public function test_packages_are_checked_and_installed_with_one_apt_get_before_the_scripts()
    {
        $marker = $this->dir.'/services-ran';
        $this->firstBoot([$this->script(7, 'services', "#!/bin/sh\ntouch '$marker'\n")],
                         [$this->package('a_1.0_arm64.deb', 'aaa'), $this->package('b_2.0_arm64.deb', 'bbb')]);

        $apt = file($this->dir.'/apt.log', FILE_IGNORE_NEW_LINES);
        $this->assertCount(1, $apt);
        $this->assertStringStartsWith('install -y --no-download -o DPkg::Lock::Timeout=120 ', $apt[0]);
        $this->assertSame('aaabbb', file_get_contents($this->dir.'/apt-content'));
        $this->assertFileExists($marker);
        $log = $this->serviceLog();
        $this->assertLessThan(strpos($log, "=== first boot script 'services'"), strpos($log, '=== packages'));
        $this->assertStringEndsWith('&retcode=0', $this->reportFields()['url']);
    }

    public function test_a_package_that_does_not_match_its_sha256_stops_the_check_before_any_script()
    {
        $marker = $this->dir.'/services-ran';
        $this->firstBoot([$this->script(7, 'services', "#!/bin/sh\ntouch '$marker'\n")],
                         [$this->package('a_1.0_arm64.deb', 'aaa', true, hash('sha256', 'something else'))]);

        $report = $this->reportFields();
        $this->assertSame('package a_1.0_arm64.deb: SHA256 mismatch', $report['reason']);
        $this->assertStringEndsWith('&retcode=1', $report['url']);
        $this->assertFileDoesNotExist($this->dir.'/apt.log');
        $this->assertFileDoesNotExist($marker);
        $this->assertSame('fail', $this->ledPatterns());
    }

    public function test_a_package_the_server_does_not_have_fails_the_check()
    {
        $this->firstBoot([], [$this->package('gone_1.0_arm64.deb', 'ggg', false)]);

        $this->assertSame('package gone_1.0_arm64.deb: download failed', $this->reportFields()['reason']);
        $this->assertSame(3, substr_count(file_get_contents($this->dir.'/curl.log'), 'gone_1.0_arm64.deb-on-server.deb'), 'three tries');
    }

    public function test_a_failing_apt_get_fails_the_check()
    {
        $this->firstBoot([], [$this->package('a_1.0_arm64.deb', 'aaa')], ['FAKE_APT' => '100']);

        $this->assertSame('package install failed: apt-get exited 100', $this->reportFields()['reason']);
    }

    public function test_the_first_failing_script_stops_the_rest()
    {
        $first = $this->dir.'/first-ran';
        $third = $this->dir.'/third-ran';
        $this->firstBoot([
            $this->script(1, 'keys', "#!/bin/sh\ntouch '$first'\n"),
            $this->script(2, 'services', "#!/bin/sh\necho checking\nexit 3\n"),
            $this->script(3, 'later', "#!/bin/sh\ntouch '$third'\n"),
        ]);

        $this->assertSame("first boot script 'services' exited 3", $this->reportFields()['reason']);
        $this->assertFileExists($first);
        $this->assertFileDoesNotExist($third);
        $this->assertStringContainsString("checking\n", $this->serviceLog(), 'the output goes to the log');
        $this->assertSame('fail', $this->ledPatterns());
    }

    public function test_a_script_over_its_time_fails_the_check()
    {
        $started = microtime(true);
        $this->firstBoot([$this->script(1, 'slow', "#!/bin/sh\nsleep 10\n")], [], ['FIRSTBOOT_SCRIPT_SECONDS' => '1']);

        $this->assertSame("first boot script 'slow' timed out after 1 s", $this->reportFields()['reason']);
        $this->assertLessThan(8, microtime(true) - $started);
    }

    public function test_scripts_know_the_server_address()
    {
        $this->firstBoot([$this->script(1, 'where', "#!/bin/sh\necho \"server is \$SERVER\"\n")]);

        $this->assertStringContainsString('server is 172.20.0.1', $this->serviceLog());
    }

    public function test_a_script_name_with_quotes_stays_one_word()
    {
        $this->firstBoot([$this->script(1, 'it\'s "odd"', "#!/bin/sh\nexit 1\n")]);

        $this->assertSame('first boot script \'it\'s "odd"\' exited 1', $this->reportFields()['reason']);
    }

    public function test_the_report_is_retried()
    {
        file_put_contents($this->dir.'/refusals', '2');

        $this->firstBoot();

        $this->assertSame(3, substr_count(file_get_contents($this->dir.'/curl.log'), 'firstboot=1'));
        $this->assertSame('wlanpi-a1b', $this->reportFields()['hostname']);
        $this->assertSame('ready', $this->ledPatterns());
    }

    public function test_a_report_that_never_arrives_shows_fail()
    {
        file_put_contents($this->dir.'/refusals', '9');

        $this->firstBoot();

        $this->assertSame([], $this->reportFields());
        $this->assertStringContainsString('the report did not reach the server', $this->serviceLog());
        $this->assertSame('fail', $this->ledPatterns());
        $this->assertFileDoesNotExist($this->dir.'/systemd/cmprovision-firstboot.service', 'it does not run again either');
    }

    public function test_without_the_helper_kernel_triggers_show_the_result()
    {
        $this->firstBoot([], [], [], false);
        $this->assertSame(['none', '1', 'none', '1'], [
            trim(file_get_contents($this->dir.'/leds/ACT/trigger')), trim(file_get_contents($this->dir.'/leds/ACT/brightness')),
            trim(file_get_contents($this->dir.'/leds/PWR/trigger')), trim(file_get_contents($this->dir.'/leds/PWR/brightness'))]);

        $this->firstBoot([$this->script(1, 'services', "#!/bin/sh\nexit 1\n")], [], [], false);
        $this->assertSame(['none', '0', 'heartbeat'], [
            trim(file_get_contents($this->dir.'/leds/ACT/trigger')), trim(file_get_contents($this->dir.'/leds/ACT/brightness')),
            trim(file_get_contents($this->dir.'/leds/PWR/trigger'))]);
    }

    public function test_a_helper_that_cannot_drive_the_leds_leaves_them_to_the_kernel()
    {
        $this->firstBoot([], [], ['FAKE_HELPER_CHECK' => '1']);

        $this->assertSame('', $this->ledPatterns());
        $this->assertSame('none', trim(file_get_contents($this->dir.'/leds/ACT/trigger')));
    }
}
