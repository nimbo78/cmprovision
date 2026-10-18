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
    private function install(array $env=[]): Process
    {
        file_put_contents($this->dir.'/install.sh',app(ReprovisionAgent::class)->install('/dev/mmcblk0','ssh-ed25519 AAAATEST cmprovision'));
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
    public function test_an_unmountable_root_is_skipped(): void
    {
        $p=$this->install(['CM_MOUNT_FAIL'=>'1']); $this->assertTrue($p->isSuccessful());
        $this->assertStringContainsString('not mountable ext4',$p->getOutput());
        $this->assertFileDoesNotExist($this->dir.'/root/usr/local/sbin/cmprovision-agent');
    }
}
