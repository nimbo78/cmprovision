<?php

namespace Tests\Unit;

use App\Services\Sensor\ShellResult;
use PHPUnit\Framework\TestCase;

class SensorShellResultTest extends TestCase
{
    public function test_redaction_does_not_hide_the_expired_password_state(): void
    {
        $r=ShellResult::from(1,'Your password has expired.',['password']);
        $this->assertSame('password_expired',$r->status);
        $this->assertStringNotContainsString('password',$r->output);
    }
    public function test_a_default_password_matching_the_hostname_does_not_corrupt_identity(): void
    {
        $output="agent=1\nserial=10000000abcdef01\nhost=wlanpi-059\nstorage=/dev/mmcblk0\ncid=0123456789abcdef0123456789abcdef\nsectors=65536\n";
        $r=ShellResult::from(0,$output,['wlanpi']);
        $this->assertStringNotContainsString('wlanpi',$r->output);
        $this->assertSame('wlanpi-059',$r->identity['host']);
        $r=ShellResult::from(0,'CMPROVISION_WIPED=10000000abcdef01',['10000000abcdef01']);
        $this->assertTrue($r->acknowledges('10000000abcdef01'));
        $this->assertFalse($r->acknowledges('1000000000000000'));
    }
}
