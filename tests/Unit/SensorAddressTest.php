<?php

namespace Tests\Unit;

use App\Models\Cm;
use App\Services\SensorAddress;
use Tests\TestCase;

class SensorAddressTest extends TestCase
{
    public function test_only_one_current_stand_address_for_the_exact_mac_is_accepted(): void
    {
        $file=tempnam(sys_get_temp_dir(),'cmprov-lease-');
        $cm=new Cm(['serial'=>'10000000abcdef01','mac'=>'02:00:00:00:00:10']);
        $service=new SensorAddress($file,['172.20.0.5']);
        try {
            foreach (['172.20.0.1','172.20.0.0','172.20.255.255','172.20.0.5','127.0.0.1','192.168.1.2','garbage'] as $ip) {
                file_put_contents($file,'0 02:00:00:00:00:10 '.$ip.' host *');
                $this->assertNull($service->find($cm),$ip);
            }
            file_put_contents($file,'0 02:00:00:00:00:10 172.20.0.10 host *');
            $this->assertSame('172.20.0.10',$service->find($cm)['address']);
            file_put_contents($file,"0 02:00:00:00:00:10 172.20.0.10 host *\n0 02:00:00:00:00:11 172.20.0.10 other *");
            $this->assertNull($service->find($cm));
            file_put_contents($file,"1 02:00:00:00:00:10 172.20.0.10 expired *");
            $this->assertNull($service->find($cm));
        } finally { unlink($file); }
    }
}
