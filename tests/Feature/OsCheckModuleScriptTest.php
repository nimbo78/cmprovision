<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Package;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The module's script with the OS check (2.6.0): it carries the service, its first-boot scripts and the install step;
 * the last post-install step puts them into the written system; after "written" the module reboots into it. The
 * install step runs here under a real sh, with mount and umount standing in and a directory for the written root.
 */
class OsCheckModuleScriptTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3';

    protected $dir;

    protected function tearDown(): void
    {
        if ($this->dir)
            File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function project($osCheck = true, array $scriptIds = [], array $packageIds = [])
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false, 'os_check' => $osCheck]);
        $project->scripts()->sync($scriptIds);
        $project->packages()->sync($packageIds);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    protected function cm()
    {
        return Cm::where('serial', self::SERIAL)->firstOrFail();
    }

    protected function firstBootScript($name, $priority, $body)
    {
        return Script::create(['name' => $name, 'script_type' => 'firstboot', 'bg' => false, 'priority' => $priority, 'script' => $body]);
    }

    public function test_with_the_check_the_written_system_gets_it_and_the_module_reboots()
    {
        $this->project(true);

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertSame('pending', $this->cm()->os_check);
        $this->assertStringContainsString('cat >/tmp/oscheck/run << "CMPROVISIONINGEOF"', $script);
        $this->assertStringContainsString("IDENTIFY_RUN='".$this->cm()->provisioning_started_at->getTimestamp()."'", $script);
        $this->assertStringContainsString('cat >/tmp/oscheck-install.sh << "CMPROVISIONINGEOF"', $script);
        $this->assertStringContainsString("progress_mark postinstall 'Installing the OS check'", $script);
        $this->assertStringContainsString('sh -v /tmp/oscheck-install.sh >>/tmp/post.log 2>&1', $script);
        $this->assertStringContainsString("reboot -f\n", $script);
        // the command, not the comment of finish() in the LED functions ("# finish done|fail")
        $this->assertStringNotContainsString("\nfinish done\n", $script);
        // the post-install log goes to the server, although the project has no post-install scripts
        $this->assertStringContainsString('retcode=0&phase=postinstall', $script);
    }

    public function test_without_the_check_the_run_ends_as_before()
    {
        $this->project(false);

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertNull($this->cm()->os_check);
        $this->assertStringNotContainsString('/tmp/oscheck', $script);
        $this->assertStringNotContainsString('reboot -f', $script);
        $this->assertStringContainsString("\nfinish done\n", $script);
    }

    public function test_a_module_booted_over_usb_is_not_checked()
    {
        $this->project(true);

        $script = $this->get(self::START.'&bootmode=3')->assertOk()->getContent();

        $this->assertSame('skipped', $this->cm()->os_check);
        $this->assertStringNotContainsString('/tmp/oscheck', $script);
        $this->assertStringContainsString("\nfinish done\n", $script);
        $this->assertSame(1, Cmlog::where('cm', self::SERIAL)->where('msg', 'like', '%OS check skipped: the module booted over USB.%')->count());
    }

    public function test_the_service_takes_the_projects_first_boot_scripts_and_packages()
    {
        $later = $this->firstBootScript('services', 20, "#!/bin/sh\nsystemctl is-active ssh\n");
        $first = $this->firstBootScript('keys', 10, "#!/bin/sh\ntest -s /root/.ssh/authorized_keys\n");
        $post = Script::create(['name' => 'post', 'script_type' => 'postinstall', 'bg' => false, 'priority' => 10, 'script' => "#!/bin/sh\ntrue\n"]);
        $package = Package::create(['filename' => 'extra_1.0_arm64.deb', 'filename_on_server' => 'abc.deb', 'size' => 3,
            'sha256' => str_repeat('c', 64), 'deb_package' => 'extra', 'deb_version' => '1.0', 'deb_architecture' => 'arm64']);
        $this->project(true, [$later->id, $first->id, $post->id], [$package->id]);

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString("cat >/tmp/oscheck/scripts/{$later->id}.sh << \"CMPROVISIONINGEOF\"\n#!/bin/sh\nsystemctl is-active ssh\n", $script);
        $this->assertLessThan(strpos($script, "run_script \"\$DIR/scripts/{$later->id}.sh\" 'services'"),
                              strpos($script, "run_script \"\$DIR/scripts/{$first->id}.sh\" 'keys'"), 'by priority');
        $this->assertStringContainsString("'http://localhost/uploads/packages/abc.deb' '".str_repeat('c', 64)."'", $script);
        $this->assertStringNotContainsString("/tmp/oscheck/scripts/{$post->id}.sh", $script, 'a post-install script stays in the utility OS');
    }

    public function test_the_module_script_and_its_service_are_valid_shell()
    {
        $this->project(true, [$this->firstBootScript('services', 10, "#!/bin/sh\ntrue\n")->id]);
        $script = $this->get(self::START)->assertOk()->getContent();
        $this->dir = sys_get_temp_dir().'/cmprovision-oscheck-'.uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir.'/module.sh', $script);
        $this->assertSame(1, preg_match('/cat >\/tmp\/oscheck\/run << "CMPROVISIONINGEOF"\n(.*?)\nCMPROVISIONINGEOF/s', $script, $m));
        file_put_contents($this->dir.'/run', $m[1]);

        exec('sh -n '.escapeshellarg($this->dir.'/module.sh').' 2>&1 && sh -n '.escapeshellarg($this->dir.'/run').' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
    }

    /** The start's script split the way the module writes its files: /tmp/oscheck into $dir/src, the install step into $dir */
    protected function moduleFiles()
    {
        $script = $this->get(self::START)->assertOk()->getContent();
        $this->dir = sys_get_temp_dir().'/cmprovision-oscheck-'.uniqid();
        mkdir($this->dir.'/src/scripts', 0755, true);
        mkdir($this->dir.'/root');
        mkdir($this->dir.'/bin');
        preg_match_all('/cat >\/tmp\/oscheck\/(\S+) << "CMPROVISIONINGEOF"\n(.*?)\nCMPROVISIONINGEOF/s', $script, $files, PREG_SET_ORDER);
        foreach ($files as [, $path, $body])
            file_put_contents($this->dir.'/src/'.$path, $body."\n");
        $this->assertSame(1, preg_match('/cat >\/tmp\/oscheck-install\.sh << "CMPROVISIONINGEOF"\n(.*?)\nCMPROVISIONINGEOF/s', $script, $m));
        file_put_contents($this->dir.'/install.sh', $m[1]."\n");
        foreach (['mount' => 'echo "$*" >> "$FAKE/mount.log"'."\n".'[ "$FAKE_MOUNT" != fail ] || { echo "mount: mounting $3 on $4 failed: Invalid argument" >&2; exit 255; }',
                  'umount' => 'echo "$*" >> "$FAKE/umount.log"'] as $name => $body)
        {
            file_put_contents($this->dir.'/bin/'.$name, "#!/bin/sh\n".$body."\n");
            chmod($this->dir.'/bin/'.$name, 0755);
        }
        file_put_contents($this->dir.'/ledpattern', 'helper');
        chmod($this->dir.'/ledpattern', 0755);
    }

    protected function install(array $env = [])
    {
        $vars = array_merge(['FAKE' => $this->dir, 'PATH' => $this->dir.'/bin:'.getenv('PATH'), 'PART2' => '/dev/mmcblk0p2',
            'OSCHECK_SRC' => $this->dir.'/src', 'OSCHECK_ROOT' => $this->dir.'/root', 'OSCHECK_LEDPATTERN' => $this->dir.'/ledpattern'], $env);
        $prefix = '';
        foreach ($vars as $name => $value)
            $prefix .= $name.'='.escapeshellarg($value).' ';
        exec($prefix.'sh '.escapeshellarg($this->dir.'/install.sh').' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function test_the_install_step_puts_the_service_into_the_written_system()
    {
        if (!is_executable('/bin/sh'))
            $this->markTestSkipped('needs a POSIX shell');
        $services = $this->firstBootScript('services', 10, "#!/bin/sh\nsystemctl is-active ssh\n");
        $this->project(true, [$services->id]);
        $this->moduleFiles();

        [$code, $out] = $this->install();

        $this->assertSame(0, $code, $out);
        $root = $this->dir.'/root';
        $lib = $root.'/usr/local/lib/cmprovision-firstboot';
        $this->assertFileEquals($this->dir.'/src/run', $lib.'/run');
        $this->assertSame('0700', substr(sprintf('%o', fileperms($lib)), -4), 'the first-boot scripts may hold secrets');
        $this->assertSame("#!/bin/sh\nsystemctl is-active ssh\n\n", file_get_contents($lib.'/scripts/'.$services->id.'.sh'));
        $this->assertSame('helper', file_get_contents($lib.'/ledpattern'));
        $this->assertStringContainsString('ExecStart=/bin/sh /usr/local/lib/cmprovision-firstboot/run',
            file_get_contents($root.'/etc/systemd/system/cmprovision-firstboot.service'));
        $this->assertSame('/etc/systemd/system/cmprovision-firstboot.service',
            readlink($root.'/etc/systemd/system/multi-user.target.wants/cmprovision-firstboot.service'));
        $this->assertSame('-t ext4 /dev/mmcblk0p2 '.$root, trim(file_get_contents($this->dir.'/mount.log')));
        $this->assertSame($root, trim(file_get_contents($this->dir.'/umount.log')));
        $this->assertStringContainsString('The OS check is in /dev/mmcblk0p2', $out);
    }

    public function test_an_install_step_that_cannot_mount_fails()
    {
        if (!is_executable('/bin/sh'))
            $this->markTestSkipped('needs a POSIX shell');
        $this->project(true);
        $this->moduleFiles();

        [$code, $out] = $this->install(['FAKE_MOUNT' => 'fail']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('mount: mounting /dev/mmcblk0p2', $out);
        $this->assertFileDoesNotExist($this->dir.'/root/etc/systemd/system/cmprovision-firstboot.service');
    }
}
