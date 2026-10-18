<?php

namespace Tests\Feature;

use App\Services\Sensor\ReprovisionAgent;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReprovisionAgentTest extends TestCase
{
    private string $dir;
    private string $script;
    private const SERIAL='10000000abcdef01';
    private const CID='0123456789abcdef0123456789abcdef';
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/bin/sh')) $this->markTestSkipped('Needs a POSIX shell.');
        $this->dir=sys_get_temp_dir().'/cmprov-agent-'.bin2hex(random_bytes(6));
        foreach (['bin','dev','sys/mmcblk0/device'] as $d) mkdir($this->dir.'/'.$d,0700,true);
        // These nodes are only tested with -b. The fake dd writes an ordinary 2 MiB fixture, never the node.
        $p=new Process(['mknod',$this->dir.'/dev/mmcblk0','b','7','240']); $p->run();
        if (!$p->isSuccessful()) $this->markTestSkipped('Needs permission to create a disposable block-device node.');
        (new Process(['mknod',$this->dir.'/dev/mmcblk0p2','b','7','241']))->mustRun();
        file_put_contents($this->dir.'/cpuinfo','Serial : '.self::SERIAL."\n");
        file_put_contents($this->dir.'/sys/mmcblk0/device/cid',self::CID);
        file_put_contents($this->dir.'/sys/mmcblk0/size','4096');
        file_put_contents($this->dir.'/disk.img',str_repeat('U',2*1048576));
        $this->executable('findmnt', '#!/bin/sh'."\n".'case "$*" in *FSTYPE*) echo ext4;; *) echo "$CM_TEST_DIR/dev/mmcblk0p2";; esac');
        $this->executable('hostname',"#!/bin/sh\necho test-sensor");
        $this->executable('lsblk',"#!/bin/sh\necho dos");
        $this->executable('id',"#!/bin/sh\necho 0");
        $this->executable('dd', '#!/bin/sh'."\n".'[ "$CM_FAIL_DD" != 1 ] || exit 1'."\n".'/usr/bin/dd if=/dev/zero of="$CM_TEST_DIR/disk.img" bs=1M count=1 conv=fsync,notrunc');
        $this->executable('sync',"#!/bin/sh\n[ \"\$CM_FAIL_SYNC\" != 1 ]");
        $this->executable('sleep',"#!/bin/sh\nexit 0");
        $this->executable('reboot',"#!/bin/sh\ntouch \"\$CM_TEST_DIR/rebooted\"");
        $script=app(ReprovisionAgent::class)->script('/dev/mmcblk0');
        $script=str_replace(['/proc/cpuinfo','/sys/class/block','/dev/mmcblk'],[$this->dir.'/cpuinfo',$this->dir.'/sys',$this->dir.'/dev/mmcblk'],$script);
        file_put_contents($this->script=$this->dir.'/agent.sh',$script);
    }
    protected function tearDown(): void { if (isset($this->dir)) File::deleteDirectory($this->dir); parent::tearDown(); }
    private function executable(string $name,string $content): void { file_put_contents($this->dir.'/bin/'.$name,$content."\n"); chmod($this->dir.'/bin/'.$name,0755); }
    private function runAgent(string $request,array $env=[]): Process
    {
        $p=new Process(['sh',$this->script],null,array_merge(['PATH'=>$this->dir.'/bin:'.getenv('PATH'),'CM_TEST_DIR'=>$this->dir,'SSH_ORIGINAL_COMMAND'=>$request],$env));
        $p->setTimeout(5)->run(); return $p;
    }
    public function test_identify_is_read_only(): void
    {
        $p=$this->runAgent('identify'); $this->assertTrue($p->isSuccessful(),$p->getOutput());
        $this->assertStringContainsString('serial='.self::SERIAL,$p->getOutput());
        $this->assertSame(str_repeat('U',2*1048576),file_get_contents($this->dir.'/disk.img'));
    }
    public function test_wipe_changes_exactly_the_first_mib_and_requests_reboot(): void
    {
        $p=$this->runAgent('wipe '.self::SERIAL.' '.self::CID.' 4096');
        $this->assertTrue($p->isSuccessful(),$p->getOutput());
        $this->assertSame('CMPROVISION_WIPED='.self::SERIAL,trim($p->getOutput()));
        $this->assertSame(str_repeat("\0",1048576).str_repeat('U',1048576),file_get_contents($this->dir.'/disk.img'));
        for ($i=0;$i<20 && !file_exists($this->dir.'/rebooted');$i++) usleep(10000);
        $this->assertFileExists($this->dir.'/rebooted');
    }
    public function test_wrong_serial_cid_size_and_extra_commands_never_write(): void
    {
        foreach (['wipe 1000000000000000 '.self::CID.' 4096','wipe '.self::SERIAL.' '.str_repeat('f',32).' 4096',
            'wipe '.self::SERIAL.' '.self::CID.' 8192','identify; reboot','wipe '.self::SERIAL.' '.self::CID.' 4096 extra'] as $request)
            $this->assertFalse($this->runAgent($request)->isSuccessful(),$request);
        $this->assertSame(str_repeat('U',2*1048576),file_get_contents($this->dir.'/disk.img'));
        $this->assertFileDoesNotExist($this->dir.'/rebooted');
    }
    public function test_write_or_sync_failure_never_acknowledges_or_reboots(): void
    {
        foreach (['CM_FAIL_DD','CM_FAIL_SYNC'] as $variable) {
            $p=$this->runAgent('wipe '.self::SERIAL.' '.self::CID.' 4096',[$variable=>'1']);
            $this->assertFalse($p->isSuccessful());
            $this->assertStringNotContainsString('CMPROVISION_WIPED=',$p->getOutput());
            $this->assertFileDoesNotExist($this->dir.'/rebooted');
        }
    }
    public function test_configured_disk_must_be_the_running_root_disk(): void
    {
        file_put_contents($this->script,str_replace("STORAGE='".$this->dir."/dev/mmcblk0'","STORAGE='/dev/mmcblk1'",file_get_contents($this->script)));
        $p=$this->runAgent('wipe '.self::SERIAL.' '.self::CID.' 4096');
        $this->assertFalse($p->isSuccessful()); $this->assertStringContainsString('running root disk',$p->getOutput());
        $this->assertSame(str_repeat('U',2*1048576),file_get_contents($this->dir.'/disk.img'));
    }
}
