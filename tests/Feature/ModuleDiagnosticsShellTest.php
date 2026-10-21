<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Support\ModuleDiagnostics;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ModuleDiagnosticsShellTest extends TestCase
{
    protected $dir;
    const SERIAL = '100000002041816d';

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/bin/bash'))
            $this->markTestSkipped('Needs a POSIX host with bash for the pipefail regression.');
        $this->dir = sys_get_temp_dir().'/cmprovision-storage-'.uniqid();
        foreach (['bin', 'sys/class/block/mmcblk0/device', 'sys/class/mmc_host/mmc0', 'sys/kernel/debug/mmc0', 'proc/device-tree/chosen/bootloader', 'sys/class/thermal/thermal_zone0', 'sys/class/net/eth0/statistics', 'etc', 'tmp'] as $name)
            mkdir($this->dir.'/'.$name, 0755, true);
        file_put_contents($this->dir.'/mmcblk0', 'keep this data');
        file_put_contents($this->dir.'/proc/device-tree/model', "Raspberry Pi Compute Module 4 Rev 1.0\0");
        file_put_contents($this->dir.'/proc/partitions', "major minor #blocks name\n1 0 4096 ram0\n");
        file_put_contents($this->dir.'/sys/class/block/mmcblk0/device/name', 'BJTD4R');
        file_put_contents($this->dir.'/sys/class/block/mmcblk0/size', '61071360');
        file_put_contents($this->dir.'/sys/kernel/debug/mmc0/ios', "clock: 400000 Hz\nbus width: 1\n");
        file_put_contents($this->dir.'/kernel.log', "[0.0] Kernel command line: root=/dev/mmcblk0 password=do-not-collect\n[3.7] mmc0: Card stuck being busy! __mmc_poll_for_busy\n");
        $this->binary('uname', 'case "$1" in -m) echo aarch64;; *) echo 6.18.32-v8;; esac');
        $this->binary('ip', 'echo "2: eth0 inet 172.20.108.22/16"');
        $this->binary('vcgencmd', "echo throttled=0x0");
        $this->binary('dmesg', 'cat '.escapeshellarg($this->dir.'/kernel.log'));
        $this->binary('curl', 'case "$*" in *identify=poll*) echo diagnostics;; *) cp '.escapeshellarg($this->dir.'/tmp/cmprovision-module-diagnostics.txt')
            .' '.escapeshellarg($this->dir.'/uploaded.txt').';; esac');
    }

    protected function tearDown(): void
    {
        if ($this->dir)
            File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function binary($name, $body): void
    {
        file_put_contents($this->dir.'/bin/'.$name, "#!/bin/sh\n".$body."\n");
        chmod($this->dir.'/bin/'.$name, 0755);
    }

    protected function execute($commands, $leds = false): string
    {
        $vars = ['serial' => self::SERIAL, 'server' => 'test.invalid', 'storage' => $this->dir.'/mmcblk0', 'diagnosticsToken' => str_repeat('d', 40)];
        $script = "#!/bin/bash\nset -o pipefail\n".view('scriptexecute.diagnostics', $vars)->render()."\n";
        if ($leds)
            $script .= view('scriptexecute.leds', $vars)->render()."\n";
        file_put_contents($this->dir.'/collect.sh', $script.$commands."\n");
        $process = new Process(['/bin/bash', $this->dir.'/collect.sh'], $this->dir, [
            'PATH' => $this->dir.'/bin:'.getenv('PATH'), 'CMP_DIAG_SYS' => $this->dir.'/sys',
            'CMP_DIAG_ETC' => $this->dir.'/etc', 'CMP_DIAG_PROC' => $this->dir.'/proc', 'CMP_DIAG_TMP' => $this->dir.'/tmp',
        ]);
        $process->setTimeout(10)->mustRun();
        return $process->getOutput();
    }

    public function test_real_shell_collects_busy_evidence_without_touching_storage_or_collecting_kernel_command_line()
    {
        $this->execute('cmp_diag_report failure');
        $text = file_get_contents($this->dir.'/uploaded.txt');
        $this->assertSame('keep this data', file_get_contents($this->dir.'/mmcblk0'));
        $this->assertStringNotContainsString('do-not-collect', $text);
        $report = ModuleDiagnostics::parse($text, new Cm(['serial' => self::SERIAL, 'provisioning_storage' => $this->dir.'/mmcblk0']));
        $this->assertSame('present', $report['facts']['controller']);
        $this->assertSame('missing', $report['facts']['block']);
        $this->assertSame('mmc_busy', ModuleDiagnostics::summary($report)['issues'][0]['code']);
    }

    public function test_an_empty_or_unreadable_dmesg_does_not_suppress_the_snapshot_under_pipefail()
    {
        $this->binary('dmesg', 'exit 1');
        $this->execute('cmp_diag_report startup');
        $this->assertFileExists($this->dir.'/uploaded.txt');
        $this->binary('curl', 'exit 7');
        $out = $this->execute('cmp_diag_report startup; echo PROVISIONING_CONTINUES');
        $this->assertStringContainsString('PROVISIONING_CONTINUES', $out);
        $this->assertStringContainsString('could not be delivered', $out);
    }

    public function test_a_hung_firmware_query_is_bounded_and_does_not_block_the_report()
    {
        $this->binary('vcgencmd', 'exec sleep 30');
        $started = microtime(true);
        $this->execute('cmp_diag_report startup');
        $this->assertLessThan(4, microtime(true) - $started);
        $this->assertStringContainsString('throttled=unknown', file_get_contents($this->dir.'/uploaded.txt'));
    }

    public function test_repeated_or_long_kernel_errors_produce_a_bounded_report()
    {
        file_put_contents($this->dir.'/kernel.log', str_repeat('[3.7] mmc0: '.str_repeat('x', 1000)."\n", 500));
        $this->execute('cmp_diag_report failure');
        $text = file_get_contents($this->dir.'/uploaded.txt');
        $this->assertLessThan(ModuleDiagnostics::MAX_BYTES, strlen($text));
        $this->assertSame(80, substr_count($text, '[3.7] mmc0:'));
    }

    public function test_manual_command_in_the_real_led_poll_uploads_a_snapshot_and_does_not_enter_identify()
    {
        $out = $this->execute('led_asked; echo "IDENTIFY_RC=$?"', true);
        $this->assertStringContainsString('IDENTIFY_RC=1', $out);
        $this->assertStringContainsString('context=manual', file_get_contents($this->dir.'/uploaded.txt'));
    }

    public function test_real_collector_reads_module_telemetry_and_selects_the_lowest_non_fan_thermal_trip()
    {
        file_put_contents($this->dir.'/proc/meminfo', "MemTotal: 4000000 kB\nMemAvailable: 2000000 kB\n");
        file_put_contents($this->dir.'/proc/uptime', '156.72 113.2');
        file_put_contents($this->dir.'/etc/cmprovision-uos', '2026.10');
        file_put_contents($this->dir.'/proc/device-tree/chosen/bootloader/boot-mode', "\x00\x00\x00\x03");
        file_put_contents($this->dir.'/proc/device-tree/chosen/bootloader/version', "01234567\0");
        foreach (['type' => 'cpu-thermal', 'temp' => '43000', 'trip_point_0_type' => 'critical', 'trip_point_0_temp' => '110000',
            'trip_point_1_type' => 'passive', 'trip_point_1_temp' => '80000', 'trip_point_2_type' => 'active', 'trip_point_2_temp' => '50000'] as $file => $value)
            file_put_contents($this->dir.'/sys/class/thermal/thermal_zone0/'.$file, $value);
        foreach (['carrier' => '1', 'operstate' => 'up', 'speed' => '1000', 'duplex' => 'full', 'statistics/rx_errors' => '3'] as $file => $value)
            file_put_contents($this->dir.'/sys/class/net/eth0/'.$file, $value);
        file_put_contents($this->dir.'/kernel.log', "[2.0] eth0: Link is Up\n[9.0] Out of memory: Killed process 102 (xz)\n");
        $this->execute('cmp_diag_report manual');
        $report = ModuleDiagnostics::parse(file_get_contents($this->dir.'/uploaded.txt'), new Cm(['serial' => self::SERIAL, 'provisioning_storage' => $this->dir.'/mmcblk0']));
        foreach (['architecture' => 'aarch64', 'utility_os' => '2026.10', 'boot_mode' => '00000003', 'temperature_mc' => '43000',
            'thermal_trip_mc' => '80000', 'mem_available_kb' => '2000000', 'ethernet' => 'present', 'eth_speed_mbps' => '1000', 'eth_rx_errors' => '3'] as $key => $value)
            $this->assertSame($value, $report['facts'][$key], $key);
        $this->assertStringContainsString('inet 172.20.108.22', $report['sections']['network']);
        $this->assertContains('oom', array_column(ModuleDiagnostics::summary($report)['issues'], 'code'));
        $this->assertSame('keep this data', file_get_contents($this->dir.'/mmcblk0'));
    }

}
