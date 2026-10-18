<?php

namespace Tests\Feature;

use App\Services\Sensor\ProvisionerKey;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Symfony\Component\Process\Process;

class ProvisionerKeyTest extends TestCase
{
    private string $dir;
    private ProvisionerKey $key;
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/usr/bin/ssh-keygen')) $this->markTestSkipped('Needs openssh-client.');
        $this->dir=sys_get_temp_dir().'/cmprov-key-'.bin2hex(random_bytes(6));
        $path=$this->dir.'/id_ed25519';
        $this->key=new class($path) extends ProvisionerKey {
            public function __construct(private string $file) {}
            public function path(): string { return $this->file; }
        };
    }
    protected function tearDown(): void { if (isset($this->dir)) File::deleteDirectory($this->dir); parent::tearDown(); }
    public function test_keeps_private_key_and_recovers_a_missing_public_part(): void
    {
        $public=$this->key->ensure(); $hash=hash_file('sha256',$this->key->path());
        unlink($this->key->path().'.pub');
        $this->assertSame($public,$this->key->ensure());
        $this->assertSame($hash,hash_file('sha256',$this->key->path()));
        $this->assertSame(0600,fileperms($this->key->path())&0777);
        $this->assertSame(0700,fileperms($this->dir)&0777);
        $p=new Process(['ssh-keygen','-lf',$this->key->path().'.pub']); $p->mustRun();
        $this->assertStringContainsString($this->key->fingerprint($public),$p->getOutput());
    }
    public function test_a_corrupt_key_is_never_silently_replaced(): void
    {
        mkdir($this->dir,0700); file_put_contents($this->key->path(),'broken');
        try { $this->key->ensure(); $this->fail('A corrupt key was replaced'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('not replaced',$e->getMessage()); }
        $this->assertSame('broken',file_get_contents($this->key->path()));
    }
    public function test_two_processes_publish_the_same_key(): void
    {
        $code='require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); config(["cache.default"=>"file"]); '
            .'$k=new class($argv[1]) extends App\\Services\\Sensor\\ProvisionerKey { public function __construct(private string $p) {} public function path(): string { return $this->p; } }; echo $k->ensure();';
        $a=new Process(['php','-r',$code,$this->key->path()],base_path());
        $b=new Process(['php','-r',$code,$this->key->path()],base_path());
        $a->start(); $b->start(); $a->wait(); $b->wait();
        $this->assertTrue($a->isSuccessful(),$a->getErrorOutput());
        $this->assertTrue($b->isSuccessful(),$b->getErrorOutput());
        $this->assertSame($a->getOutput(),$b->getOutput());
        $this->assertSame($a->getOutput(),$this->key->ensure());
    }
}
