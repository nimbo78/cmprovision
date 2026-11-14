<?php
namespace Tests\Support;

use App\Services\Sensor\ReprovisionStation;

class FakeReprovisionStation extends ReprovisionStation
{
    public array $snapshot = ['device'=>'eth0', 'uuid'=>'11111111-1111-1111-1111-111111111111',
        'address'=>'172.20.0.1', 'binding'=>'eth0', 'dhcp'=>['active'=>true,'enabled'=>'enabled','boot_enabled'=>true]];
    public function __construct() {}
    public function current(): array { return $this->snapshot; }
}
