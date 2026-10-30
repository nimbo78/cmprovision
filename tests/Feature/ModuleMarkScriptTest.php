<?php

namespace Tests\Feature;

use App\Services\Sensor\ModuleMark;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** The service the re-provision agent leaves for the provisioner's mark (scriptexecute/module-mark.blade.php): on every
    boot of the written system, up to the project's last, lldpd gets the port description with a new code; the boot after
    the last takes the mark, its key and the service away. Here it runs under sh in a root of its own (MARK_ROOT) with the
    real openssl and lldpcli and systemctl that write down what they were asked */
class ModuleMarkScriptTest extends TestCase
{
    const MODULE_KEY = 'aa112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/bin/sh') || trim((string) shell_exec('command -v openssl 2>/dev/null')) === '')
            $this->markTestSkipped('needs sh and openssl');
        $this->dir = sys_get_temp_dir().'/cmprov-mark-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/bin', 0700, true);
        mkdir($this->dir.'/root/usr/local/lib/cmprovision-mark', 0700, true);
        mkdir($this->dir.'/root/etc/systemd/system/multi-user.target.wants', 0755, true);
        foreach (['lldpcli', 'systemctl'] as $tool)
        {
            file_put_contents($this->dir."/bin/$tool", "#!/bin/sh\necho \"$tool \$*\" >> \"{$this->dir}/calls\"\n");
            chmod($this->dir."/bin/$tool", 0755);
        }
        $lib = $this->dir.'/root/usr/local/lib/cmprovision-mark';
        file_put_contents($lib.'/mark', view('scriptexecute.module-mark')->render());
        file_put_contents($lib.'/settings', "STATION=wlanpi-da2\nBOOTS=3\n");
        file_put_contents($lib.'/key', self::MODULE_KEY."\n");
        file_put_contents($this->dir.'/root/etc/systemd/system/cmprovision-mark.service', "[Unit]\n");
        symlink('/etc/systemd/system/cmprovision-mark.service', $this->dir.'/root/etc/systemd/system/multi-user.target.wants/cmprovision-mark.service');
    }

    protected function tearDown(): void
    {
        if (isset($this->dir))
            File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function boot(): Process
    {
        $p = new Process(['sh', $this->dir.'/root/usr/local/lib/cmprovision-mark/mark'], null,
            ['PATH' => $this->dir.'/bin:'.getenv('PATH'), 'MARK_ROOT' => $this->dir.'/root']);
        $p->setTimeout(10)->run();
        return $p;
    }

    protected function conf(): string
    {
        return $this->dir.'/root/etc/lldpd.d/cmprovision-mark.conf';
    }

    protected function calls(): array
    {
        return is_file($this->dir.'/calls') ? file($this->dir.'/calls', FILE_IGNORE_NEW_LINES) : [];
    }

    public function test_every_boot_up_to_the_last_names_the_station_with_a_new_code()
    {
        $codes = [];
        foreach ([1, 2, 3] as $boot)
        {
            $p = $this->boot();
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
            $code = ModuleMark::code(self::MODULE_KEY, 'wlanpi-da2', $boot, 3);
            $this->assertStringEndsWith("\nconfigure lldp portdescription \"cmprovision mark wlanpi-da2 $boot/3 $code\"\n", file_get_contents($this->conf()));
            $this->assertSame(['station' => 'wlanpi-da2', 'boot' => $boot, 'boots' => 3, 'code' => $code],
                ModuleMark::parse('cmprovision mark wlanpi-da2 '.$boot.'/3 '.$code), 'the station reads what the module wrote');
            $codes[] = $code;
        }
        $this->assertCount(3, array_unique($codes), 'a new code on every boot');
        $this->assertSame(array_fill(0, 3, 'lldpcli -c '.$this->conf()), $this->calls(), 'the running lldpd takes it at once');
    }

    public function test_the_boot_after_the_last_takes_everything_away()
    {
        foreach ([1, 2, 3, 4] as $boot)
            $last = $this->boot();
        $this->assertTrue($last->isSuccessful());

        $this->assertFileDoesNotExist($this->conf());
        $this->assertDirectoryDoesNotExist($this->dir.'/root/usr/local/lib/cmprovision-mark', 'the module key goes too');
        $this->assertFileDoesNotExist($this->dir.'/root/etc/systemd/system/cmprovision-mark.service');
        $this->assertFalse(is_link($this->dir.'/root/etc/systemd/system/multi-user.target.wants/cmprovision-mark.service'));
        $this->assertContains('systemctl try-restart lldpd.service', $this->calls(), 'lldpcli cannot take a port description back');
    }

    public function test_a_broken_counter_starts_again_and_a_broken_setting_takes_the_mark_away()
    {
        file_put_contents($this->dir.'/root/usr/local/lib/cmprovision-mark/boot', "x\n");
        $this->boot();
        $this->assertStringContainsString(' 1/3 ', file_get_contents($this->conf()));

        file_put_contents($this->dir.'/root/usr/local/lib/cmprovision-mark/settings', "STATION=wlan pi\nBOOTS=3\n");
        $this->assertTrue($this->boot()->isSuccessful());
        $this->assertFileDoesNotExist($this->conf());
        $this->assertDirectoryDoesNotExist($this->dir.'/root/usr/local/lib/cmprovision-mark');
    }

    public function test_without_a_key_nothing_is_said_but_the_boots_still_count()
    {
        unlink($this->dir.'/root/usr/local/lib/cmprovision-mark/key');
        $this->assertTrue($this->boot()->isSuccessful());
        $this->assertFileDoesNotExist($this->conf());
        $this->assertSame("1\n", file_get_contents($this->dir.'/root/usr/local/lib/cmprovision-mark/boot'));
    }

    public function test_the_key_never_reaches_the_lldpd_file_or_the_output()
    {
        $p = $this->boot();
        $this->assertStringNotContainsString(self::MODULE_KEY, file_get_contents($this->conf()));
        $this->assertStringNotContainsString(self::MODULE_KEY, $p->getOutput().$p->getErrorOutput());
    }
}
