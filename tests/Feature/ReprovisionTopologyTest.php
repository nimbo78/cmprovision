<?php
namespace Tests\Feature;

use App\Models\{Cm,EthernetSwitch};
use App\Services\{NetworkStatus,SensorAddress};
use App\Services\Sensor\ReprovisionTarget;
use App\Services\Switch\{MacTable,SwitchSnapshot};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\{FakeReprovisionStation,FakeSnmp,FakeSwitches};
use Tests\TestCase;

class ReprovisionTopologyTest extends TestCase
{
    use RefreshDatabase, FakeSwitches;
    private string $leases;
    protected function setUp(): void {
        parent::setUp();$this->leases=tempnam(sys_get_temp_dir(),'lease-');
        file_put_contents($this->leases,'0 02:00:00:00:00:20 172.20.0.20 target *');
        $this->mock(NetworkStatus::class,fn($m)=>$m->shouldReceive('serverMac')->andReturn(null));
    }
    protected function tearDown(): void {@unlink($this->leases);parent::tearDown();}
    private function topology(bool $duplicate=false,bool $noEdge=false,bool $shared=false): void {
        $a=EthernetSwitch::forceCreate(['host'=>'192.0.2.1','community'=>'public']);
        $b=EthernetSwitch::forceCreate(['host'=>'192.0.2.2','community'=>'public']);
        $make=function(string $name,string $base,array $entries): FakeSnmp {
            $t=[SwitchSnapshot::OID_SYSDESCR.'.0'=>'S5731 test',SwitchSnapshot::OID_SYSNAME.'.0'=>$name,
                SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0'=>hex2bin($base)];
            foreach ([1,2] as $p) $t += [SwitchSnapshot::OID_IFNAME.'.'.$p=>'GE0/0/'.$p,SwitchSnapshot::OID_IFTYPE.'.'.$p=>6,
                SwitchSnapshot::OID_IFCONNECTOR.'.'.$p=>1,SwitchSnapshot::OID_IFOPERSTATUS.'.'.$p=>1,SwitchSnapshot::OID_IFHIGHSPEED.'.'.$p=>1000];
            foreach ($entries as $mac=>$port) $t[MacTable::OID_HUAWEI_FDB_PORT.'.'.$mac.'.10.0']=$port;
            return new FakeSnmp([''=>$t]);
        };
        $one=['2.0.0.0.2.2'=>2,'2.0.0.0.0.32'=>$duplicate?1:2];
        if ($shared) {$one['2.0.0.0.0.32']=1;$one['2.0.0.0.0.33']=1;}
        $two=['2.0.0.0.1.1'=>2];if(!$noEdge)$two['2.0.0.0.0.32']=1;
        $this->bindSwitches([$a->host=>$make('core','020000000101',$one),$b->host=>$make('edge','020000000202',$two)]);
    }
    private function resolve(): array {
        return (new ReprovisionTarget(new SensorAddress($this->leases,[]),new FakeReprovisionStation,null,[]))
            ->resolve('02:00:00:00:00:20',null);
    }
    public function test_a_unique_access_port_wins_over_an_upstream_fdb_carrier(): void {
        $this->topology();$this->assertSame('02:00:00:00:00:20',$this->resolve()['mac']);$this->assertSame(0,Cm::count());
    }
    public function test_two_unknown_access_edges_are_ambiguous(): void {
        $this->topology(true);$this->expectException(\RuntimeException::class);$this->resolve();
    }
    public function test_known_modules_cannot_hide_ambiguous_raw_forwarding_entries(): void {
        $this->topology(true);Cm::create(['serial'=>'10000000abcdef01','mac'=>'02:00:00:00:00:20','phase'=>'done']);
        $this->expectException(\RuntimeException::class);$this->resolve();
    }
    public function test_a_target_seen_only_on_a_transit_link_remains_protected(): void {
        $this->topology(false,true);$this->expectException(\RuntimeException::class);$this->resolve();
    }
    public function test_a_shared_non_transit_sighting_cannot_be_hidden_by_another_single_edge(): void {
        $this->topology(false,false,true);$this->expectException(\RuntimeException::class);$this->resolve();
    }

}
