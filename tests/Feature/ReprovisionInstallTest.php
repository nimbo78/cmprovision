<?php

namespace Tests\Feature;

use App\Services\Sensor\ReprovisionAgent;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReprovisionInstallTest extends TestCase
{
    private string $dir;
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/bin/sh')) $this->markTestSkipped('Needs a POSIX shell.');
        $this->dir=sys_get_temp_dir().'/cmprov-install-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/bin',0700,true); mkdir($this->dir.'/root/etc/ssh',0700,true);
        $this->bin('mktemp',"#!/bin/sh\necho \"\$CM_TEST_DIR/root\"");
        $this->bin('mount',"#!/bin/sh\n[ \"\$CM_MOUNT_FAIL\" != 1 ]");
        $this->bin('umount',"#!/bin/sh\necho unmounted >>\"\$CM_TEST_DIR/events\"");
        $this->bin('rmdir',"#!/bin/sh\nexit 0");
        $this->bin('sync',"#!/bin/sh\nexit 0");
    }
    private function bin(string $name,string $content): void { file_put_contents($this->dir.'/bin/'.$name,$content."\n"); chmod($this->dir.'/bin/'.$name,0755); }
    protected function tearDown(): void { if (isset($this->dir)) File::deleteDirectory($this->dir); parent::tearDown(); }
    private function install(array $env=[], ?array $mark=null): Process
    {
        file_put_contents($this->dir.'/install.sh',app(ReprovisionAgent::class)->install('/dev/mmcblk0','ssh-ed25519 AAAATEST cmprovision',$mark));
        $p=new Process(['sh',$this->dir.'/install.sh'],null,array_merge(['PATH'=>$this->dir.'/bin:'.getenv('PATH'),'PART2'=>'/dev/testp2','CM_TEST_DIR'=>$this->dir],$env));
        $p->setTimeout(5)->run(); return $p;
    }
    public function test_installs_restricted_key_once_preserves_other_keys_and_unmounts(): void
    {
        mkdir($this->dir.'/root/root/.ssh',0700,true);
        $keys=$this->dir.'/root/root/.ssh/authorized_keys'; file_put_contents($keys,"ssh-ed25519 AAAAOTHER operator\n");
        foreach ([1,2] as $run) {
            $p=$this->install(); $this->assertTrue($p->isSuccessful(),$p->getErrorOutput());
            $this->assertStringContainsString('agent installed',$p->getOutput());
        }
        $content=file_get_contents($keys);
        $this->assertSame(1,substr_count($content,'AAAATEST')); $this->assertStringContainsString('AAAAOTHER',$content);
        $this->assertStringContainsString('restrict,command="/usr/local/sbin/cmprovision-agent"',$content);
        $this->assertSame(0600,fileperms($keys)&0777);
        $this->assertSame(0700,fileperms(dirname($keys))&0777);
        $this->assertSame(0755,fileperms($this->dir.'/root/usr/local/sbin/cmprovision-agent')&0777);
        $this->assertSame(2,substr_count(file_get_contents($this->dir.'/events'),'unmounted'));
    }
    public function test_missing_ssh_and_unmount_after_an_error_are_nonfatal(): void
    {
        rmdir($this->dir.'/root/etc/ssh');
        $p=$this->install(); $this->assertTrue($p->isSuccessful()); $this->assertStringContainsString('agent skipped',$p->getOutput());
        $this->assertFileExists($this->dir.'/events');
        $this->assertFileDoesNotExist($this->dir.'/root/usr/local/sbin/cmprovision-agent');
    }
    public function test_a_symlink_never_writes_outside_the_image(): void
    {
        mkdir($this->dir.'/outside'); symlink($this->dir.'/outside',$this->dir.'/root/root');
        $p=$this->install(); $this->assertTrue($p->isSuccessful());
        $this->assertStringContainsString('symlink',$p->getOutput());
        $this->assertSame([],array_diff(scandir($this->dir.'/outside'),['.','..']));
    }
    /* the provisioner's mark over LLDP (2.23.0) rides with the agent: its service, settings and the module's key, which the
       module asks the server for (sh -v would copy a key written in the script into the log) */
    private const MARK=['station'=>'wlanpi-da2','boots'=>20,'url'=>'http://172.20.0.1/scriptexecute?serial=10000000abcdef01&markkey=1'];
    private function curl(string $body,int $code=0): void
    {
        $this->bin('curl',"#!/bin/sh\necho \"\$*\" >>\"\$CM_TEST_DIR/curl\"\nprintf '%s\\n' '$body'\nexit $code");
    }
    public function test_the_mark_is_installed_with_the_agent_and_counts_from_the_first_boot(): void
    {
        $key=str_repeat('ab',32); $this->curl($key);
        $lib=$this->dir.'/root/usr/local/lib/cmprovision-mark';
        foreach ([1,2] as $run) {
            if ($run===2) file_put_contents($lib.'/boot',"7\n");
            $p=$this->install([],self::MARK); $this->assertTrue($p->isSuccessful(),$p->getErrorOutput());
            $this->assertStringContainsString('agent installed',$p->getOutput());
            $this->assertStringContainsString('mark installed: the switch hears it for the first 20 boots',$p->getOutput());
        }
        $this->assertFileDoesNotExist($lib.'/boot','a new image counts again');
        $this->assertSame("$key\n",file_get_contents($lib.'/key'));
        $this->assertSame("STATION=wlanpi-da2\nBOOTS=20\n",file_get_contents($lib.'/settings'));
        $this->assertSame(0700,fileperms($lib)&0777);
        $this->assertSame(0600,fileperms($lib.'/key')&0777);
        $this->assertSame(0755,fileperms($lib.'/mark')&0777);
        $this->assertStringContainsString('cmprovision mark',file_get_contents($lib.'/mark'));
        $unit=file_get_contents($this->dir.'/root/etc/systemd/system/cmprovision-mark.service');
        $this->assertStringContainsString("ExecStart=/usr/local/lib/cmprovision-mark/mark\n",$unit);
        $this->assertStringContainsString("After=lldpd.service\n",$unit);
        $this->assertSame('/etc/systemd/system/cmprovision-mark.service',readlink($this->dir.'/root/etc/systemd/system/multi-user.target.wants/cmprovision-mark.service'));
        $this->assertStringContainsString(self::MARK['url'],file_get_contents($this->dir.'/curl'));
        $this->assertStringNotContainsString($key,$p->getOutput());
    }
    public function test_no_key_from_the_server_leaves_the_agent_without_a_mark(): void
    {
        foreach ([['',22],['not-a-key',0]] as [$body,$code]) {
            $this->curl($body,$code);
            $p=$this->install([],self::MARK); $this->assertTrue($p->isSuccessful());
            $this->assertStringContainsString('agent installed',$p->getOutput());
            $this->assertStringContainsString('mark skipped: the server gave no key',$p->getOutput());
            $this->assertDirectoryDoesNotExist($this->dir.'/root/usr/local/lib/cmprovision-mark');
            $this->assertFileDoesNotExist($this->dir.'/root/etc/systemd/system/cmprovision-mark.service');
        }
    }
    public function test_the_mark_never_follows_a_symlink_out_of_the_image(): void
    {
        $this->curl(str_repeat('ab',32));
        mkdir($this->dir.'/root/etc/systemd',0755,true); mkdir($this->dir.'/outside');
        symlink($this->dir.'/outside',$this->dir.'/root/etc/systemd/system');
        $p=$this->install([],self::MARK); $this->assertTrue($p->isSuccessful());
        $this->assertStringContainsString('agent installed',$p->getOutput());
        $this->assertStringContainsString('mark skipped',$p->getOutput());
        $this->assertSame([],array_diff(scandir($this->dir.'/outside'),['.','..']));
    }
    public function test_a_mark_the_provisioner_cannot_make_says_why(): void
    {
        $p=$this->install([],['skip'=>'no mark key on the provisioner']); $this->assertTrue($p->isSuccessful());
        $this->assertStringContainsString('mark skipped: no mark key on the provisioner',$p->getOutput());
        $this->assertStringContainsString('agent installed',$p->getOutput());
    }
    public function test_an_unmountable_root_is_skipped(): void
    {
        $p=$this->install(['CM_MOUNT_FAIL'=>'1']); $this->assertTrue($p->isSuccessful());
        $this->assertStringContainsString('not mountable ext4',$p->getOutput());
        $this->assertFileDoesNotExist($this->dir.'/root/usr/local/sbin/cmprovision-agent');
    }
}
