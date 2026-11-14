<?php
namespace Tests\Feature;

use App\Models\{Cm,EthernetSwitch,Setting};
use App\Services\{SensorAddress,NetworkChanges,NetworkProfiles};
use App\Services\Sensor\{ReprovisionTarget,ReprovisionStation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeReprovisionStation;
use Tests\TestCase;

class ReprovisionTargetTest extends TestCase
{
    use RefreshDatabase;
    private string $leases;
    private FakeReprovisionStation $station;
    private array $neighbor = [['dst'=>'172.20.0.20','dev'=>'eth0','lladdr'=>'02:00:00:00:00:20','state'=>['REACHABLE']]];
    private array $commands=[];
    protected function setUp(): void {
        parent::setUp(); $this->leases=tempnam(sys_get_temp_dir(),'cm-lease-');
        $this->station=new FakeReprovisionStation;
    }
    protected function tearDown(): void { @unlink($this->leases); parent::tearDown(); }
    private function resolver(): ReprovisionTarget {
        return new ReprovisionTarget(new SensorAddress($this->leases,[]),$this->station,function(array $argv): ?string {
            $this->commands[]=$argv;
            return in_array('neigh',$argv,true) ? json_encode($this->neighbor) : '';
        },['172.20.0.1','172.20.0.2','172.20.0.50']);
    }
    public function test_unique_lease_is_enough_without_local_history(): void {
        file_put_contents($this->leases,'0 02:00:00:00:00:20 172.20.0.20 new-module *');
        $target=$this->resolver()->resolve('02:00:00:00:00:20',null);
        $this->assertSame('172.20.0.20',$target['address']); $this->assertSame('lease',$target['proof']);
        $this->assertSame('eth0',$target['stand']['device']); $this->assertSame(0,Cm::count());
    }
    public function test_manual_ip_requires_matching_fresh_l2_evidence_on_stand(): void {
        $target=$this->resolver()->resolve(null,'172.20.0.20');
        $this->assertSame('02:00:00:00:00:20',$target['mac']); $this->assertSame('neighbor',$target['proof']);
    }
    public function test_stale_or_wrong_interface_evidence_is_refused(): void {
        foreach ([['STALE'],'eth1'] as $bad) {
            $this->neighbor[0]['state']=is_array($bad)?$bad:['REACHABLE']; $this->neighbor[0]['dev']=is_array($bad)?'eth0':$bad;
            try {$this->resolver()->resolve(null,'172.20.0.20');$this->fail('Unproven neighbor accepted');}
            catch (\RuntimeException $e) {$this->assertStringContainsString('binding',$e->getMessage());}
        }
    }
    public function test_mac_mismatch_and_duplicate_leases_do_not_fall_back(): void {
        foreach (["0 02:00:00:00:00:20 172.20.0.20 x *\n0 02:00:00:00:00:21 172.20.0.20 y *",
            "0 02:00:00:00:00:20 172.20.0.20 x *\n0 02:00:00:00:00:20 172.20.0.21 x *"] as $leases) {
            file_put_contents($this->leases,$leases);
            try {$this->resolver()->resolve('02:00:00:00:00:20','172.20.0.20');$this->fail('Ambiguous lease accepted');}
            catch (\RuntimeException $e) {$this->assertStringContainsString('ambiguous',$e->getMessage());}
        }
        file_put_contents($this->leases,'');
        $this->expectExceptionMessage('binding');$this->resolver()->resolve('02:00:00:00:00:21','172.20.0.20');
    }
    public function test_invalid_local_reserved_and_switch_destinations_are_excluded_before_probing(): void {
        EthernetSwitch::forceCreate(['host'=>'172.20.0.77']);
        foreach (['8.8.8.8','example.test','127.0.0.1','172.20.0.1','172.20.0.2','172.20.0.0','172.20.255.255','172.20.0.50','172.20.0.77'] as $ip) {
            try {$this->resolver()->resolve(null,$ip);$this->fail('Protected destination accepted: '.$ip);}
            catch (\RuntimeException $e) {$this->assertNotEmpty($e->getMessage());}
        }
        $this->assertSame([],$this->commands);
    }
    public function test_hint_conflicting_with_current_lease_is_refused(): void {
        file_put_contents($this->leases,'0 02:00:00:00:00:20 172.20.0.20 x *');
        $this->expectExceptionMessage('changed'); $this->resolver()->resolve('02:00:00:00:00:20','172.20.0.21');
    }
    public function test_secondary_or_stopped_dhcp_is_not_a_ready_primary(): void {
        $this->station->snapshot['dhcp']['active']=false;
        $this->expectExceptionMessage('DHCP');$this->station->requireReady();
    }
    public function test_current_services_and_binding_are_checked_in_addition_to_address(): void {
        $profiles=new NetworkProfiles(fn(array $argv)=>match(true) {
            in_array('DEVICE,TYPE,STATE',$argv,true)=>'eth0:ethernet:connected',
            in_array('GENERAL.CON-UUID',$argv,true)=>'11111111-1111-1111-1111-111111111111',
            in_array('address',$argv,true)=>json_encode([['ifname'=>'eth0','addr_info'=>[['local'=>'172.20.0.1','prefixlen'=>16]]]]),
            default=>null,
        });
        $state=['dhcp'=>['active'=>true,'enabled'=>'enabled','boot_enabled'=>true], 'usb'=>['active'=>false,'enabled'=>'masked','boot_enabled'=>false]];
        $changes=new NetworkChanges(fn()=>[0,json_encode(['ok'=>true,'result'=>$state])]);
        file_put_contents($this->leases,"eth0\n");
        $station=new ReprovisionStation($profiles,$changes,$this->leases);
        $this->assertSame('eth0',$station->requireReady()['device']);
        file_put_contents($this->leases,"eth1\n");
        $this->expectExceptionMessage('binding');$station->requireReady();
    }
    public function test_handoff_accepts_only_configured_origins_and_carries_no_authority(): void {
        foreach (['javascript:alert(1)','https://u:p@station','https://station/path','https://station/?x=1','https://station/#fragment','//station'] as $url)
            $this->assertNull(ReprovisionStation::validateOrigin($url));
        $this->assertSame('http://192.168.68.237:8080',ReprovisionStation::validateOrigin('http://192.168.68.237:8080/'));
        Setting::updateOrCreate(['key'=>'reprovision_primary_url'],['value'=>'http://192.168.68.237']);
        $this->station->snapshot['address']='172.20.0.2';
        $this->assertSame('http://192.168.68.237/reprovision?mac=02%3A00%3A00%3A00%3A00%3A20&address=172.20.0.20',
            $this->station->handoff('02:00:00:00:00:20','172.20.0.20'));
    }
}
