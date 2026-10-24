<?php

namespace Tests\Feature;

use App\Support\StandMap;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The module names itself to its switch over LLDP (utility OS 2026.10.2): S61lldpname under BusyBox sh with a stub of
 * lldpcli, and what the server makes of the description it sends.
 */
class UtilityOsLldpTest extends TestCase
{
    protected $dir, $busybox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->busybox = trim((string) shell_exec('command -v busybox 2>/dev/null'));
        $applets = $this->busybox ? explode("\n", (string) shell_exec(escapeshellarg($this->busybox).' --list')) : [];
        foreach (['sh', 'cat', 'tr', 'uname'] as $applet)
            if (!in_array($applet, $applets, true))
                $this->markTestSkipped('needs busybox with '.$applet);
        $this->dir = sys_get_temp_dir().'/cmprovision-uos-lldp-'.uniqid();
        mkdir($this->dir.'/bin', 0755, true);
        mkdir($this->dir.'/sys/class/net/eth0', 0755, true);
        mkdir($this->dir.'/etc', 0755, true);
        foreach (['sh', 'cat', 'tr', 'uname'] as $applet)
            symlink($this->busybox, $this->dir.'/bin/'.$applet);
        file_put_contents($this->dir.'/sys/class/net/eth0/address', "e4:5f:01:62:9f:d3\n");
        file_put_contents($this->dir.'/etc/cmprovision-uos', "2026.10.2\n");
        $this->stub('sleep', 'echo "sleep $*" >> "$T/calls"');
        // lldpcli: every call noted, one by one with its arguments; the first FAILS calls (lldpd not up yet) fail
        $this->stub('lldpcli', implode("\n", [
            'n=$(( $(cat "$T/lldpcli.count" 2>/dev/null || echo 0) + 1 )); echo $n > "$T/lldpcli.count"',
            '[ "$n" -le "$(cat "$T/fail" 2>/dev/null || echo 0)" ] && exit 1',
            'for a in "$@"; do printf "[%s]" "$a"; done >> "$T/lldpcli.calls"; echo >> "$T/lldpcli.calls"',
        ]));
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

    /** the init as rcS runs it ("start"), waiting for its background work; its console */
    protected function start(array $extra = []): string
    {
        $env = array_merge(['PATH' => $this->dir.'/bin:'.getenv('PATH'), 'UOS_SYS' => $this->dir.'/sys', 'UOS_ETC' => $this->dir.'/etc', 'UOS_FOREGROUND' => '1'], $extra);
        $prefix = '';
        foreach ($env as $name => $value)
            $prefix .= $name.'='.escapeshellarg($value).' ';
        exec($prefix.'timeout 20 '.escapeshellarg($this->busybox).' sh '.escapeshellarg(dirname(__DIR__, 2).'/utility-os/board/cmprovision/rootfs-overlay/etc/init.d/S61lldpname').' start 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        return implode("\n", $out);
    }

    protected function calls(): array
    {
        return array_values(array_filter(explode("\n", (string) @file_get_contents($this->dir.'/lldpcli.calls'))));
    }

    public function test_the_module_is_named_cm_and_its_mac_and_says_which_os_it_is_and_only_sends()
    {
        $console = $this->start();

        $uname = trim((string) shell_exec(escapeshellarg($this->busybox).' uname -r')).' '.trim((string) shell_exec(escapeshellarg($this->busybox).' uname -m'));
        $this->assertSame([
            '[configure][system][hostname][cm-e45f01629fd3]',
            '[configure][system][description][cmprovision utility OS 2026.10.2 (Linux '.$uname.')]',
            '[configure][ports][eth0][lldp][status][tx-only]',
        ], $this->calls());
        $this->assertStringContainsString('lldp: announcing the module as cm-e45f01629fd3', $console);
    }

    public function test_lldpd_that_is_a_moment_late_is_asked_again()
    {
        file_put_contents($this->dir.'/fail', '3');

        $this->start();

        $this->assertSame(3, substr_count((string) file_get_contents($this->dir.'/calls'), 'sleep 1'), 'a second between the tries');
        $this->assertSame('[configure][system][hostname][cm-e45f01629fd3]', $this->calls()[0]);
        $this->assertCount(3, $this->calls());
    }

    public function test_lldpd_that_never_answers_leaves_its_own_name_and_does_not_stop_the_boot()
    {
        file_put_contents($this->dir.'/fail', '999');

        $console = $this->start();

        $this->assertSame([], $this->calls());
        $this->assertStringContainsString('lldpd does not answer', $console);
        $this->assertSame(14, substr_count((string) file_get_contents($this->dir.'/calls'), 'sleep 1'), 'fifteen tries, a second between');
    }

    public function test_a_module_whose_eth0_never_appears_is_not_named_and_not_hung_on()
    {
        unlink($this->dir.'/sys/class/net/eth0/address');

        $console = $this->start();

        $this->assertSame([], $this->calls());
        $this->assertStringContainsString('eth0 has no address', $console);
        $this->assertSame(30, substr_count((string) file_get_contents($this->dir.'/calls'), 'sleep 1'), 'it waits half a minute for the port');
    }

    public function test_a_stop_or_any_other_word_does_nothing()
    {
        exec('PATH='.escapeshellarg($this->dir.'/bin:'.getenv('PATH')).' UOS_FOREGROUND=1 UOS_SYS='.escapeshellarg($this->dir.'/sys').' '.escapeshellarg($this->busybox).' sh '
            .escapeshellarg(dirname(__DIR__, 2).'/utility-os/board/cmprovision/rootfs-overlay/etc/init.d/S61lldpname').' stop 2>&1', $out, $code);
        $this->assertSame(0, $code);
        $this->assertSame([], $this->calls());
    }

    public function test_lldpd_runs_on_eth0_only_and_the_scripts_are_executable_in_the_image()
    {
        $this->assertSame("LLDPD_ARGS=\"-I eth0\"\n", preg_replace('/^#.*\n/m', '', file_get_contents(dirname(__DIR__, 2).'/utility-os/board/cmprovision/rootfs-overlay/etc/default/lldpd')));
        $post = file_get_contents(dirname(__DIR__, 2).'/utility-os/board/cmprovision/post-build.sh');
        $this->assertStringContainsString('chmod 755 "$TARGET/etc/init.d/S61lldpname"', $post);
        // it runs after lldpd (S60lldpd of buildroot) and before the script that waits for the network (S99scriptexec)
        $this->assertFileExists(dirname(__DIR__, 2).'/utility-os/board/cmprovision/rootfs-overlay/etc/init.d/S61lldpname');
    }

    public function test_the_server_knows_a_device_that_says_it_is_the_utility_os_and_which_version()
    {
        $tile = fn ($description) => ['neighbors' => [['description' => $description]]];

        $this->assertSame('2026.10.2', StandMap::utilityOs($tile('cmprovision utility OS 2026.10.2 (Linux 6.18.50-cmprovision aarch64)')));
        $this->assertSame('2026.10', StandMap::utilityOs($tile('cmprovision utility OS 2026.10')));
        $this->assertNull(StandMap::utilityOs($tile('Debian GNU/Linux 13 (trixie) Linux 7.3.0')));
        $this->assertNull(StandMap::utilityOs($tile('my cmprovision utility OS 2026.10.2')), 'it is the start of the description');
        $this->assertNull(StandMap::utilityOs($tile('cmprovision utility OS ')), 'a version is needed');
        $this->assertNull(StandMap::utilityOs(['neighbors' => []]));
        $this->assertNull(StandMap::utilityOs([]));
    }
}
