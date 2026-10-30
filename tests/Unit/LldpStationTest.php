<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** tools/lldp-station (2.23.0): the station names itself to the stand's switch over LLDP, and another station knows it
    for a cmprovision station by that (StandMap::station()). postinst and prerm run it as root; here it runs under sh
    with /etc/lldpd.d, /etc/os-release and lldpcli of its own */
class LldpStationTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (trim((string) shell_exec('command -v sh 2>/dev/null')) === '')
            $this->markTestSkipped('needs sh');
        $this->root = sys_get_temp_dir().'/lldp-station-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/bin', 0755, true);
        file_put_contents($this->root.'/os-release', "NAME=\"Debian GNU/Linux\"\nPRETTY_NAME=\"Debian GNU/Linux 13 (trixie)\"\nVERSION_ID=\"13\"\n");
        $this->lldpcli(0);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    /** an lldpcli that writes down what it was asked and answers with the code given (1: no lldpd running) */
    protected function lldpcli(int $code): void
    {
        file_put_contents($this->root.'/bin/lldpcli', "#!/bin/sh\necho \"\$*\" >> \"{$this->root}/lldpcli.log\"\nexit $code\n");
        chmod($this->root.'/bin/lldpcli', 0755);
    }

    protected function conf(): string
    {
        return $this->root.'/lldpd.d/cmprovision.conf';
    }

    /** runs the helper; [exit code, output] */
    protected function helper(string ...$args): array
    {
        $env = 'LLDP_STATION_DIR='.escapeshellarg($this->root.'/lldpd.d').' LLDP_OS_RELEASE='.escapeshellarg($this->root.'/os-release')
            .' PATH='.escapeshellarg($this->root.'/bin:'.getenv('PATH'));
        exec("$env sh ".escapeshellarg(dirname(__DIR__, 2).'/tools/lldp-station').' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    protected function calls(): array
    {
        return is_file($this->root.'/lldpcli.log') ? file($this->root.'/lldpcli.log', FILE_IGNORE_NEW_LINES) : [];
    }

    public function test_announce_writes_the_description_and_gives_it_to_the_running_lldpd()
    {
        $this->assertSame(0, $this->helper('announce', '2.23.0~dev3')[0]);

        $this->assertStringEndsWith("\nconfigure system description \"cmprovision 2.23.0~dev3 station; Debian GNU/Linux 13 (trixie)\"\n",
            file_get_contents($this->conf()), 'the system stays named after it: whoever reads the switch sees what it runs');
        $this->assertSame('644', substr(sprintf('%o', fileperms($this->conf())), -3), 'lldpd reads it');
        $this->assertSame(['-c '.$this->conf()], $this->calls(), 'the running lldpd takes it now, a restarted one from its directory');
        $this->assertSame([], glob($this->root.'/lldpd.d/.cmprovision.*'), 'no temporary file is left');
    }

    public function test_announce_again_replaces_the_version()
    {
        $this->helper('announce', '2.22.2');
        $this->helper('announce', '2.23.0');

        $this->assertStringContainsString('"cmprovision 2.23.0 station; Debian', file_get_contents($this->conf()));
        $this->assertStringNotContainsString('2.22.2', file_get_contents($this->conf()));
    }

    public function test_the_system_name_cannot_break_out_of_the_quoted_description()
    {
        file_put_contents($this->root.'/os-release', "PRETTY_NAME=\"Odd \\\"OS\\\" \$(reboot) `id`; x's\"\n");
        $this->helper('announce', '2.23.0');

        $this->assertStringEndsWith("\nconfigure system description \"cmprovision 2.23.0 station; Odd OS (reboot) id xs\"\n", file_get_contents($this->conf()));
    }

    public function test_without_a_system_name_the_station_still_names_itself()
    {
        unlink($this->root.'/os-release');
        $this->helper('announce', '2.23.0');

        $this->assertStringEndsWith("\nconfigure system description \"cmprovision 2.23.0 station\"\n", file_get_contents($this->conf()));
    }

    public function test_a_version_that_is_not_a_package_version_writes_nothing()
    {
        foreach (['', '2.23" ; reboot', '2.23.0 extra'] as $version)
        {
            [$code, $out] = $this->helper('announce', $version);
            $this->assertSame(2, $code, $version);
            $this->assertFileDoesNotExist($this->conf());
        }
        $this->assertSame([], $this->calls());
    }

    public function test_without_a_running_lldpd_the_file_waits_for_it()
    {
        // lldpd installed later, or stopped: it reads the directory when it starts
        $this->lldpcli(1);
        $this->assertSame(0, $this->helper('announce', '2.23.0')[0]);
        $this->assertFileExists($this->conf());
        $this->assertSame(0, $this->helper('withdraw')[0]);
    }

    public function test_withdraw_removes_the_file_and_gives_lldpd_its_own_description_back()
    {
        $this->helper('announce', '2.23.0');
        $this->assertSame(0, $this->helper('withdraw')[0]);

        $this->assertFileDoesNotExist($this->conf());
        $this->assertSame('unconfigure system description', $this->calls()[1]);
        $this->assertSame(0, $this->helper('withdraw')[0], 'twice is fine');
    }

    public function test_anything_else_is_a_usage_error()
    {
        foreach ([[], ['announce'], ['withdraw', 'x'], ['remove']] as $args)
            $this->assertSame(2, $this->helper(...$args)[0], implode(' ', $args));
    }
}
