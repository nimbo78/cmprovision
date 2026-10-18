<?php

namespace Tests\Feature;

use App\Services\Sensor\OpenSshSensorShell;
use App\Services\Sensor\ProvisionerKey;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/* tests/ssh/run.py supplies a throwaway loopback sshd and account. No real sensor is contacted. */
class OpenSshSensorShellTest extends TestCase
{
    private OpenSshSensorShell $shell;
    private string $operation;
    protected function setUp(): void
    {
        parent::setUp();
        if (!getenv('CMPROVISION_SSH_TEST_PORT')) $this->markTestSkipped('Run with tests/ssh/run.py.');
        $this->operation = bin2hex(random_bytes(16));
        $this->shell = new OpenSshSensorShell(app(ProvisionerKey::class), (int) getenv('CMPROVISION_SSH_TEST_PORT'));
    }
    protected function tearDown(): void
    {
        if (isset($this->shell)) $this->shell->forget($this->operation);
        parent::tearDown();
    }
    public function test_password_and_sudo_receive_the_password_without_putting_it_in_output(): void
    {
        $r = $this->shell->account('127.0.0.1', $this->operation, getenv('CMPROVISION_SSH_TEST_USER'), getenv('CMPROVISION_SSH_TEST_PASSWORD'), "sudo -k -S -p '' id -u");
        $this->assertSame('ok', $r->status, $r->output);
        $this->assertSame('0', trim($r->output));
        $this->assertStringNotContainsString(getenv('CMPROVISION_SSH_TEST_PASSWORD'), $r->output);
    }
    public function test_wrong_password_is_denied(): void
    {
        $r = $this->shell->account('127.0.0.1', $this->operation, getenv('CMPROVISION_SSH_TEST_USER'), 'Wrong-secret-123!', 'true');
        $this->assertSame('denied', $r->status, $r->output);
        $this->assertStringNotContainsString('Wrong-secret-123!', $r->output);
    }
    public function test_expired_password_is_changed_and_verified_with_a_new_login(): void
    {
        $user = getenv('CMPROVISION_SSH_TEST_USER');
        (new Process(['chage', '-d', '0', $user]))->mustRun();
        try {
            $r = $this->shell->account('127.0.0.1', $this->operation, $user, getenv('CMPROVISION_SSH_TEST_PASSWORD'), 'true');
            $this->assertSame('password_expired', $r->status, $r->output);
            $r = $this->shell->changePassword('127.0.0.1', $this->operation, $user, getenv('CMPROVISION_SSH_TEST_PASSWORD'), 'New-Cmprov-234!');
            $this->assertSame('ok', $r->status, $r->output);
            $r = $this->shell->account('127.0.0.1', $this->operation, $user, 'New-Cmprov-234!', "sudo -k -S -p '' id -u");
            $this->assertSame('ok', $r->status, $r->output);
        } finally {
            (new Process(['chpasswd'], null, null, $user.':'.getenv('CMPROVISION_SSH_TEST_PASSWORD')."\n"))->mustRun();
            (new Process(['chage', '-d', date('Y-m-d'), $user]))->mustRun();
        }
    }
    public function test_changed_host_key_is_refused(): void
    {
        $dir = storage_path('app/ssh/operations/'.$this->operation);
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/known_hosts', '[127.0.0.1]:'.getenv('CMPROVISION_SSH_TEST_PORT').' '.app(ProvisionerKey::class)->ensure()."\n");
        $r = $this->shell->account('127.0.0.1', $this->operation, getenv('CMPROVISION_SSH_TEST_USER'), getenv('CMPROVISION_SSH_TEST_PASSWORD'), 'true');
        $this->assertSame('host_changed', $r->status, $r->output);
    }
    public function test_key_runs_only_the_forced_command(): void
    {
        $r = $this->shell->key('127.0.0.1', $this->operation, 'identify');
        $this->assertSame('ok', $r->status, $r->output);
        $this->assertSame('forced=identify', trim($r->output));
    }
    public function test_nopasswd_sudo_is_supported(): void
    {
        $file=getenv('CMPROVISION_SSH_TEST_SUDOERS'); $old=file_get_contents($file);
        try {
            file_put_contents($file,getenv('CMPROVISION_SSH_TEST_USER')." ALL=(root) NOPASSWD: ALL\n");
            $r=$this->shell->account('127.0.0.1',$this->operation,getenv('CMPROVISION_SSH_TEST_USER'),getenv('CMPROVISION_SSH_TEST_PASSWORD'),'sudo -k -n id -u');
            $this->assertSame('ok',$r->status,$r->output); $this->assertSame('0',trim($r->output));
        } finally { file_put_contents($file,$old); }
    }
    public function test_an_account_without_sudo_is_refused(): void
    {
        $file=getenv('CMPROVISION_SSH_TEST_SUDOERS'); $old=file_get_contents($file);
        try {
            file_put_contents($file,"# no sudo rights for the fixture account\n");
            $r=$this->shell->account('127.0.0.1',$this->operation,getenv('CMPROVISION_SSH_TEST_USER'),getenv('CMPROVISION_SSH_TEST_PASSWORD'),"sudo -k -S -p '' id -u");
            $this->assertNotSame('ok',$r->status);
        } finally { file_put_contents($file,$old); }
    }
    public function test_default_wlanpi_password_does_not_redact_the_protocol_hostname(): void
    {
        $user=getenv('CMPROVISION_SSH_TEST_USER');
        (new Process(['chpasswd'],null,null,$user.":wlanpi\n"))->mustRun();
        try {
            $command="printf 'agent=1\\nserial=10000000abcdef01\\nhost=wlanpi-059\\nstorage=/dev/mmcblk0\\ncid=0123456789abcdef0123456789abcdef\\nsectors=65536\\n'";
            $r=$this->shell->account('127.0.0.1',$this->operation,$user,'wlanpi',$command);
            $this->assertSame('ok',$r->status,$r->output);
            $this->assertSame('wlanpi-059',$r->identity['host']);
            $this->assertStringNotContainsString('wlanpi',$r->output);
        } finally {
            (new Process(['chpasswd'],null,null,$user.':'.getenv('CMPROVISION_SSH_TEST_PASSWORD')."\n"))->mustRun();
        }
    }
}
