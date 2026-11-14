<?php
namespace Tests\Feature;

use App\Models\Cm;
use App\Services\Sensor\{ReprovisionAgent,ShellResult,TargetInspection};
use Tests\TestCase;

class TargetInspectionTest extends TestCase
{
    private function identity(): array { return ['agent'=>'2','serial'=>'10000000abcdef01','host'=>'test-sensor','storage'=>'/dev/mmcblk0',
        'cid'=>'0123456789abcdef0123456789abcdef','sectors'=>'65536','model'=>'Raspberry Pi Compute Module 4 Rev 1.1',
        'revision'=>'c03141','mac'=>'02:00:00:00:00:20','boot_order'=>'0xf21','provisioner'=>'0']; }
    public function test_extended_protocol_is_bounded_and_rejects_duplicate_fields(): void {
        $i=$this->identity();$out=implode("\n",array_map(fn($k)=>$k.'='.$i[$k],array_keys($i)));
        $this->assertSame($i,ReprovisionAgent::parse($out));
        foreach ([$out."\nmac=02:00:00:00:00:20",str_repeat('X',65537)."\n".$out,str_replace('revision=c03141','revision='.str_repeat('f',100),$out)] as $bad)
            $this->assertNull(ReprovisionAgent::parse($bad));
    }
    public function test_first_time_extended_identity_is_ready_without_any_cm(): void {
        app(TargetInspection::class)->validate($this->identity(),'02:00:00:00:00:20',null);$this->addToAssertionCount(1);
    }
    public function test_network_boot_order_is_interpreted_from_right_to_left(): void {
        foreach (['0xf21'=>true,'0xf12'=>true,'f21'=>true,'0x2f1'=>false,'0xf41'=>false,'unknown'=>false,'0x0'=>false,'0x521'=>true] as $order=>$ready)
            $this->assertSame($ready,TargetInspection::bootReady($order),$order);
    }
    public function test_first_time_legacy_agent_requires_extended_inspection(): void {
        $i=array_slice($this->identity(),0,6,true);$i['agent']='1';
        $this->expectExceptionMessage('inspection');app(TargetInspection::class)->validate($i,'02:00:00:00:00:20',null);
    }
    public function test_unsupported_model_station_mac_and_network_boot_are_refused(): void {
        foreach (['model'=>'Raspberry Pi 5 Model B Rev 1.0','provisioner'=>'1','mac'=>'02:00:00:00:00:21','boot_order'=>'0xf41'] as $field=>$value) {
            $i=$this->identity();$i[$field]=$value;
            try {app(TargetInspection::class)->validate($i,'02:00:00:00:00:20',null);$this->fail('Unsafe inspection accepted '.$field);}
            catch (\RuntimeException $e) {$this->assertNotEmpty($e->getMessage());}
        }
    }
    public function test_legacy_identity_still_requires_matching_registered_disk(): void {
        $i=array_slice($this->identity(),0,6,true);$i['agent']='1';
        $cm=new Cm(['serial'=>$i['serial'],'provisioning_storage'=>$i['storage'],'cid'=>$i['cid'],'storage'=>33554432]);
        app(TargetInspection::class)->validate($i,'02:00:00:00:00:20',$cm);$this->addToAssertionCount(1);
        $cm->cid=str_repeat('f',32);$this->expectExceptionMessage('disk');app(TargetInspection::class)->validate($i,'02:00:00:00:00:20',$cm);
    }
}
