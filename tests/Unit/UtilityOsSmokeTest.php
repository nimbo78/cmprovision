<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Failed QEMU acceptance must not overwrite the fixture used to accept the shipped binaries. */
class UtilityOsSmokeTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/usr/bin/python3'))
            $this->markTestSkipped('needs python3');
        $this->dir = sys_get_temp_dir().'/uos-smoke-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir.'/start', 'serial=&model=linux,dummy-virt&storagesize=&mac=52:54:00:12:34:56'
            .'&inversejumper=&memorysize=1000000&temp=&cid=&csd=&bootmode=&uos=2026.10');
        file_put_contents($this->dir.'/requests', "/scriptexecute?\n/scriptexecute?\n");
        file_put_contents($this->dir.'/image.sha256', str_repeat('a', 64));
        $nonce = str_repeat('a', 64);
        $boot = '11111111-2222-3333-4444-555555555555';
        $snapshot = "context=discovery\nutility_os=2026.10\n";
        $sections = [
            'uos' => '2026.10', 'kernel' => '6.18.50-cmprovision',
            'usb-agent' => "CMPDIAG1 STATUS $nonce 1000000000000001 $boot $boot discovery 123 0 -\n"
                ."CMPDIAG1 BEGIN $nonce 1000000000000001 $boot ".strlen($snapshot).' '.hash('sha256', $snapshot)." -\n"
                .base64_encode($snapshot)."\nCMPDIAG1 END $nonce",
            'versions' => implode("\n", array_map(fn ($n) => 'tool'.$n.' rc=0 version', range(1, 22))),
            // what lldpcli says of the chassis (plain format, indented): the name S61lldpname gave it, the OS, the MAC of eth0
            'lldp' => "  ChassisID:    mac 52:54:00:12:34:56\n  SysName:      cm-525400123456\n"
                .'  SysDescr:     cmprovision utility OS 2026.10 (Linux 6.18.50-cmprovision aarch64)',
            'gz-whole' => str_repeat('a', 64), 'xz-whole' => str_repeat('a', 64), 'bz2-whole' => str_repeat('a', 64),
            'od' => '3', 'base64' => 'hello', 'zeros' => '0', 'awk' => '1099511627777',
            'cmp' => 'rc=0', 'dd-flags' => 'rc=0', 'curl' => '404 rc=22',
            'ledpattern' => 'rc=1 usage: ledpattern', 'dd-records' => '3+0 records in',
            'flashrom-identical' => "Chip content is identical to the requested image.\nrc=0",
            'flashrom-written' => "VERIFIED.\nrc=0",
            'flashrom-missing' => "No EEPROM/flash device found.\nrc=1",
        ];
        foreach (['gz-cut', 'gz-garbage', 'xz-cut', 'xz-garbage', 'bz2-cut', 'bz2-garbage', 'dd-full', 'sha256-mismatch'] as $name)
            $sections[$name] = 'sample message';
        $result = '';
        foreach ($sections as $name => $text)
            $result .= '['.$name."]\n".$text."\n";
        file_put_contents($this->dir.'/result.txt', $result);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir))
            exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    protected function verify(): array
    {
        exec('python3 '.escapeshellarg(dirname(__DIR__, 2).'/tools/utility-os/smoke/verify.py').' '
            .escapeshellarg($this->dir).' 2026.10 '.escapeshellarg($this->dir.'/messages.txt').' 6.18.50 2>&1', $output, $code);
        return [$code, implode("\n", $output)];
    }

    public function test_a_successful_guest_report_writes_the_fixture()
    {
        [$code, $output] = $this->verify();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('utility OS 2026.10, recorded', file_get_contents($this->dir.'/messages.txt'));
    }

    public function test_a_module_that_does_not_announce_itself_over_lldp_fails_the_run()
    {
        $result = file_get_contents($this->dir.'/result.txt');
        foreach (['SysName:      cm-525400123456' => 'SysName:      scriptexecute',
                  'cmprovision utility OS 2026.10 (Linux' => 'Buildroot 2025.02 (Linux',
                  'ChassisID:    mac 52:54:00:12:34:56' => 'ChassisID:    mac 02:00:00:00:00:01'] as $good => $bad)
        {
            file_put_contents($this->dir.'/result.txt', str_replace($good, $bad, $result));
            [$code, $out] = $this->verify();
            $this->assertSame(1, $code, $bad);
            $this->assertStringContainsString('FAIL lldp:', $out, $bad);
        }
    }

    public function test_a_failed_guest_report_preserves_the_previous_fixture()
    {
        file_put_contents($this->dir.'/messages.txt', 'previous accepted fixture');
        file_put_contents($this->dir.'/ran-502', 'the error page ran');

        [$code, $output] = $this->verify();

        $this->assertSame(1, $code, $output);
        $this->assertSame('previous accepted fixture', file_get_contents($this->dir.'/messages.txt'));
    }

    public function test_a_kernel_from_another_version_is_rejected()
    {
        $result = file_get_contents($this->dir.'/result.txt');
        file_put_contents($this->dir.'/result.txt', str_replace('6.18.50-cmprovision', '5.4.0-cmprovision', $result));

        [$code, $output] = $this->verify();

        $this->assertSame(1, $code, $output);
        $this->assertFileDoesNotExist($this->dir.'/messages.txt');
    }

    public function test_a_successful_flashrom_noop_must_identify_unchanged_contents()
    {
        $result = file_get_contents($this->dir.'/result.txt');
        file_put_contents($this->dir.'/result.txt', str_replace('Chip content is identical to the requested image.',
            'Updating flash chip contents... Erase/write done', $result));

        [$code, $output] = $this->verify();

        $this->assertSame(1, $code, $output);
        $this->assertFileDoesNotExist($this->dir.'/messages.txt');
    }

    public function test_a_real_write_must_not_claim_identical()
    {
        $result = file_get_contents($this->dir.'/result.txt');
        file_put_contents($this->dir.'/result.txt', str_replace("[flashrom-written]\nVERIFIED.",
            "[flashrom-written]\nidentical\nVERIFIED.", $result));

        [$code, $output] = $this->verify();

        $this->assertSame(1, $code, $output);
    }
}
