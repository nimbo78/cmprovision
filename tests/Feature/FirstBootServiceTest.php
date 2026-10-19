<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Script;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
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
    protected $process;

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
        file_put_contents($this->dir.'/answers', '');
        // End finite polling scenarios after the last answer; real sleep still runs for scripts/timeouts.
        $this->stub('sleep', 'case "$1" in 0.03|0.02) [ -s "$FAKE/answers" ] || [ "${FAKE_KEEP_POLLING:-0}" = 1 ] || exit 1 ;; esac' . "\n" . 'exec /bin/sleep "$@"');
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
            '    *identify=poll*)',
            '        [ ! -e "$FIRSTBOOT_DIR" ] && [ ! -e "$FIRSTBOOT_UNIT" ] && [ ! -L "$FIRSTBOOT_WANTS" ] || touch "$FAKE/inputs-remain"',
            '        /bin/sleep 0.02',
            '        a=$(head -n 1 "$FAKE/answers"); sed -i 1d "$FAKE/answers"',
            '        [ "$a" != error ] || exit 7',
            '        [ "$a" != partial ] || { printf identify; exit 18; }',
            '        printf "%s" "$a" ;;',
            '    *firstboot=1*)',
            '        left=$(cat "$FAKE/refusals" 2>/dev/null || echo 0)',
            '        if [ "$left" -gt 0 ]; then echo $((left - 1)) > "$FAKE/refusals"; exit 22; fi',
            '        printf "%s\n" "$@" > "$FAKE/report"',
            '        cp "$log" "$FAKE/report.log" ;;',
            '    *) if [ "${url##*/}" = "${FAKE_PARTIAL:-}" ]; then printf part > "$out"; exit 18; fi',
            '       f="$FAKE/www/${url##*/}"; [ -f "$f" ] || exit 22; cp "$f" "$out" ;;',
            'esac',
        ]));
        $this->stub('dpkg', 'echo "dpkg $*" >> "$FAKE/calls"');
        // refuses what apt 3.0 refuses: --no-download with local .deb files (seen on WLAN Pi OS), and a parts
        // directory that is missing or lists sources
        $this->stub('apt-get', implode("\n", [
            'echo "apt-get $*" >> "$FAKE/calls"',
            'echo "$*" >> "$FAKE/apt.log"',
            'for a in "$@"; do',
            '    case "$a" in',
            '        --no-download) echo "E: Internal Error, Pathname to install is not absolute"; exit 100 ;;',
            '        Dir::Etc::SourceParts=*) d=${a#*=}; [ -d "$d" ] && [ -z "$(ls -A "$d")" ] || { echo "E: sources in $d"; exit 100; } ;;',
            '    esac',
            'done',
            'for f in "$@"; do case "$f" in *.deb) cat "$f" >> "$FAKE/apt-content" ;; esac; done',
            'exit ${FAKE_APT:-0}',
        ]));
        // Like the real helper, retain the same PID until killed, so transitions must reap it.
        file_put_contents($this->dir.'/helper', "#!/bin/sh\n[ \"\$1\" = check ] && exit \${FAKE_HELPER_CHECK:-0}\necho \"\$1\" >> \"\$FAKE/patterns\"\necho \"\$\$\" >> \"\$FAKE/helper-pids\"\nexec /bin/sleep 30\n");
        chmod($this->dir.'/helper', 0755);
    }

    protected function tearDown(): void
    {
        if ($this->process && $this->process->isRunning())
            $this->process->stop(2);
        if (file_exists($this->dir.'/helper-pids'))
            foreach (file($this->dir.'/helper-pids', FILE_IGNORE_NEW_LINES) as $pid)
                exec('kill '.(int) $pid.' 2>/dev/null');
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
        $this->process = $this->firstBootProcess($scripts, $packages, $env, $helper);
        return $this->process->run();
    }

    protected function firstBootProcess(array $scripts = [], array $packages = [], array $env = [], bool $helper = true): Process
    {
        @mkdir($this->dir.'/lib/scripts', 0755, true);   // a run before removed it
        file_put_contents($this->dir.'/lib/run', view('scriptexecute.firstboot', ['server' => '172.20.0.1', 'serial' => self::SERIAL,
            'scripts' => collect($scripts), 'packages' => collect($packages), 'identify_run' => '1791327300'])->render());
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
            'FIRSTBOOT_DOWNLOAD_TRIES' => '3', 'FIRSTBOOT_KILL_AFTER' => '1',
            'FIRSTBOOT_PAUSE' => '0', 'FIRSTBOOT_RUNTIME_BASE' => $this->dir,
            'LED_POLL_SECONDS' => '0.03', 'LED_IDENTIFY_POLL_SECONDS' => '0.02',
        ], $env);
        return new Process(['sh', $this->dir.'/lib/run'], $this->dir, $vars, null, 30);
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
        // apt sees no sources (nothing to download on a stand without internet) and leaves recommended packages out
        $lib = $this->dir.'/lib';
        $this->assertSame('install -y --no-install-recommends -o DPkg::Lock::Timeout=120 -o Dpkg::Use-Pty=0 '
            .'-o Dir::Etc::SourceList=/dev/null -o Dir::Etc::SourceParts='.$lib.'/no-sources -o Dir::Cache::pkgcache= '
            .'-o Dir::Cache::srcpkgcache= '.$lib.'/packages/1.deb '.$lib.'/packages/2.deb', $apt[0]);
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

    public function test_the_log_is_for_root_alone()
    {
        // the scripts run under sh -v: their text, a password too, goes into the log that stays on the device
        $this->firstBoot([$this->script(1, 'keys', "#!/bin/sh\ntrue\n")]);

        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir.'/firstboot.log')), -4));
    }

    public function test_packages_get_their_own_tries_however_long_the_report_retries()
    {
        $this->firstBoot([], [$this->package('gone_1.0_arm64.deb', 'ggg', false)],
                         ['FIRSTBOOT_TRIES' => '5', 'FIRSTBOOT_DOWNLOAD_TRIES' => '2']);

        $this->assertSame(2, substr_count(file_get_contents($this->dir.'/curl.log'), 'gone_1.0_arm64.deb-on-server.deb'));
    }

    public function test_a_download_cut_short_says_so()
    {
        // curl leaves what it got: a partial file is no package with a wrong checksum
        $this->firstBoot([], [$this->package('cut_1.0_arm64.deb', 'ccc', false)], ['FAKE_PARTIAL' => 'cut_1.0_arm64.deb-on-server.deb']);

        $this->assertSame('package cut_1.0_arm64.deb: download failed', $this->reportFields()['reason']);
    }

    public function test_a_script_that_ignores_the_end_of_its_time_is_killed()
    {
        $started = microtime(true);
        $this->firstBoot([$this->script(1, 'stubborn', "#!/bin/sh\ntrap '' TERM\nsleep 20\n")], [], ['FIRSTBOOT_SCRIPT_SECONDS' => '1']);

        $this->assertSame("first boot script 'stubborn' timed out after 1 s", $this->reportFields()['reason']);
        $this->assertLessThan(10, microtime(true) - $started);
    }

    public function test_an_install_cut_short_by_a_reboot_is_finished_first()
    {
        $this->firstBoot([], [$this->package('a_1.0_arm64.deb', 'aaa')]);

        $calls = file($this->dir.'/calls', FILE_IGNORE_NEW_LINES);
        $this->assertSame('dpkg --configure -a', $calls[0]);
        $this->assertStringStartsWith('apt-get install -y --no-install-recommends ', $calls[1]);
    }

    public function test_a_helper_that_cannot_drive_the_leds_leaves_them_to_the_kernel()
    {
        $this->firstBoot([], [], ['FAKE_HELPER_CHECK' => '1']);

        $this->assertSame('', $this->ledPatterns());
        $this->assertSame('none', trim(file_get_contents($this->dir.'/leds/ACT/trigger')));
    }

    public function test_identify_restores_ready_without_repeating_scripts_or_reports()
    {
        file_put_contents($this->dir.'/answers', "\nidentify\nidentify\n\n");
        $marker = $this->dir.'/script-calls';
        $this->firstBoot([$this->script(1, 'once', "echo once >> '$marker'\n")]);

        $this->assertSame("ready\nidentify\nready", $this->ledPatterns());
        $this->assertSame("once\n", file_get_contents($marker));
        $curl = file_get_contents($this->dir.'/curl.log');
        $this->assertSame(1, substr_count($curl, 'firstboot=1'));
        $this->assertSame(4, substr_count($curl, 'identify=poll&identify_run=1791327300'));
        $this->assertFileDoesNotExist($this->dir.'/inputs-remain');
        $this->assertSame([], glob($this->dir.'/cmprovision-leds.*'));
        foreach (file($this->dir.'/helper-pids', FILE_IGNORE_NEW_LINES) as $pid)
        {
            exec('kill -0 '.(int) $pid.' 2>/dev/null', $out, $code);
            $this->assertNotSame(0, $code, 'every previous helper is stopped and reaped');
        }
    }

    public function test_a_failed_check_can_identify_and_returns_to_fail_when_the_network_breaks()
    {
        file_put_contents($this->dir.'/answers', "identify\nerror\nidentify\npartial\n\n");
        $this->firstBoot([$this->script(1, 'bad', "exit 3\n")]);

        $this->assertSame("fail\nidentify\nfail\nidentify\nfail", $this->ledPatterns());
        $this->assertSame("first boot script 'bad' exited 3", $this->reportFields()['reason']);
    }

    public function test_identify_without_the_helper_uses_kernel_triggers_and_restores_the_result()
    {
        file_put_contents($this->dir.'/answers', "identify\n\n");
        $this->firstBoot([], [], [], false);
        $this->assertSame('150', trim(file_get_contents($this->dir.'/leds/ACT/delay_on')));
        $this->assertSame('none', trim(file_get_contents($this->dir.'/leds/ACT/trigger')));
        $this->assertSame('1', trim(file_get_contents($this->dir.'/leds/PWR/brightness')));
    }

    public function test_termination_cleans_the_private_runtime_helper()
    {
        $this->process = $this->firstBootProcess([], [], ['FAKE_KEEP_POLLING' => '1']);
        $this->process->start();
        $deadline = microtime(true) + 5;
        do {
            usleep(20000);
        } while (!file_exists($this->dir.'/helper-pids') && microtime(true) < $deadline && $this->process->isRunning());
        $this->assertTrue($this->process->isRunning());
        $runtime = glob($this->dir.'/cmprovision-leds.*');
        $this->assertCount(1, $runtime);
        $this->assertSame('0700', substr(sprintf('%o', fileperms($runtime[0])), -4));
        $this->assertSame(['ledpattern'], array_values(array_diff(scandir($runtime[0]), ['.', '..'])));
        $this->assertDirectoryDoesNotExist($this->dir.'/lib');

        $this->process->signal(15);
        $this->assertSame(0, $this->process->wait());
        $this->assertSame([], glob($this->dir.'/cmprovision-leds.*'));
        $this->assertSame('ready', $this->ledPatterns());
    }
}
