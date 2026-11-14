<?php
namespace Tests\Feature;

use App\Models\{Cm,Cmlog,Image,Project,Setting,User,ReprovisionOperation};
use App\Services\{SensorAddress,ModuleOperationLock,NetworkChangeGate};
use App\Services\Sensor\{Reprovision,ReprovisionStation,SensorShell,Credentials,ShellResult};
use Tests\Support\{FakeSensorShell,FakeReprovisionStation};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniversalReprovisionTest extends TestCase
{
    use RefreshDatabase;
    private string $leases;
    private Project $project;
    private User $user;
    private FakeSensorShell $shell;
    private FakeReprovisionStation $station;
    protected function setUp(): void {
        parent::setUp();$this->user=User::factory()->create();$this->actingAs($this->user);
        $image=Image::forceCreate(['filename'=>'test.img.gz','filename_extension'=>'gz','filename_on_server'=>'test.img.gz',
            'sha256'=>str_repeat('a',64),'uncompressed_size'=>8388608]);
        $this->project=Project::create(['name'=>'Current primary project','device'=>'cm4','storage'=>'/dev/mmcblk0','image_id'=>$image->id,'label_moment'=>'never']);
        Setting::updateOrCreate(['key'=>'active_project'],['value'=>$this->project->id]);
        $this->leases=tempnam(sys_get_temp_dir(),'cm-lease-');file_put_contents($this->leases,'0 02:00:00:00:00:20 172.20.0.20 first-time *');
        $this->app->instance(SensorAddress::class,new SensorAddress($this->leases,[]));
        $this->app->instance(ReprovisionStation::class,$this->station=new FakeReprovisionStation);
        $this->app->instance(SensorShell::class,$this->shell=new FakeSensorShell);
        $this->shell->identity=['agent'=>'2','serial'=>'10000000abcdef01','host'=>'first-time','storage'=>'/dev/mmcblk0',
            'cid'=>'0123456789abcdef0123456789abcdef','sectors'=>'65536','model'=>'Raspberry Pi Compute Module 4 Rev 1.1',
            'revision'=>'c03141','mac'=>'02:00:00:00:00:20','boot_order'=>'0xf21','provisioner'=>'0'];
    }
    protected function tearDown(): void {@unlink($this->leases);parent::tearDown();}
    private function check(): ReprovisionOperation {return app(Reprovision::class)->checkTarget('02:00:00:00:00:20',null,$this->user->id,'session');}
    private function wipe(ReprovisionOperation $op): ReprovisionOperation {return app(Reprovision::class)->wipe($op->id,$this->user->id,'session','Operator');}
    public function test_first_time_module_is_checked_and_reset_without_fabricating_history(): void {
        $op=$this->check();$this->assertSame('checked',$op->state);$this->assertSame('10000000abcdef01',$op->serial);
        $this->assertSame(0,Cm::count());$this->assertSame(0,Cmlog::count());
        $this->assertSame('waiting',$this->wipe($op)->state);$this->assertSame(1,$this->shell->wipes());$this->assertSame(0,Cm::count());
        $this->get('/scriptexecute?serial=10000000abcdef01&mac=02:00:00:00:00:20&storagesize=65536')->assertOk();
        $this->assertSame(1,Cm::count());$this->assertSame('booted',$op->fresh()->state);
    }
    public function test_first_time_saved_account_does_not_require_preinstalled_agent(): void {
        $this->shell->keyStatus='denied';$op=$this->check();$this->assertSame('checked',$op->state);$this->assertSame('account',$op->method);
        $this->assertSame('wlanpi',$op->username);$this->assertSame('waiting',$this->wipe($op)->state);
    }
    public function test_legacy_key_cannot_bypass_first_time_inspection_and_can_fall_back(): void {
        $shell=new class extends FakeSensorShell {
            public function key(string $address,string $operation,string $command): ShellResult {
                return new ShellResult('error','refused: unknown command');
            }
        };
        $shell->identity=$this->shell->identity;$this->app->instance(SensorShell::class,$this->shell=$shell);
        $op=$this->check();$this->assertSame('checked',$op->state);$this->assertSame('account',$op->method);
    }
    public function test_conflicting_registered_serial_is_refused_without_wipe(): void {
        Cm::create(['serial'=>'10000000abcdef01','mac'=>'02:00:00:00:00:21','phase'=>'done']);
        $this->assertSame('failed',$this->check()->state);$this->assertSame(0,$this->shell->wipes());
    }
    public function test_second_operator_cannot_dispatch_a_second_reset_for_same_target(): void {
        $first=$this->check();$second=$this->check();$this->wipe($first);
        $this->expectExceptionMessage('pending');$this->wipe($second);
    }
    public function test_unknown_result_is_durable_and_never_automatically_retried(): void {
        $op=$this->check();$this->shell->wipeStatus='unreachable';$this->assertSame('unknown',$this->wipe($op)->state);
        $this->assertSame('unknown',$this->wipe($op)->state);$this->assertSame(1,$this->shell->wipes());$this->assertSame(0,Cm::count());
    }
    public function test_cancel_and_expired_checks_do_not_write_or_add_history(): void {
        $op=$this->check();app(Reprovision::class)->cancel($op->id,$this->user->id,'session');
        $this->assertSame('cancelled',$op->fresh()->state);$this->assertNull(app(Credentials::class)->get($op->id));
        $op=$this->check();$this->travel(121)->seconds();
        try {$this->wipe($op);$this->fail('Expired check accepted');}catch(\RuntimeException $e){$this->assertStringContainsString('expired',$e->getMessage());}
        $this->assertSame(0,$this->shell->wipes());$this->assertSame(0,Cm::count());
    }
    public function test_changed_binding_role_project_or_disk_stops_before_dispatch(): void {
        $op=$this->check();$this->station->snapshot['uuid']='22222222-2222-2222-2222-222222222222';
        try {$this->wipe($op);$this->fail('Changed stand accepted');}catch(\RuntimeException $e){$this->assertStringContainsString('changed',$e->getMessage());}
        $this->assertSame(0,$this->shell->wipes());
        $op=$this->check();$this->shell->identity['cid']=str_repeat('f',32);
        try {$this->wipe($op);$this->fail('Changed disk accepted');}catch(\RuntimeException $e){$this->assertStringContainsString('changed',$e->getMessage());}
        $this->assertSame(0,$this->shell->wipes());
        $op=$this->check();$this->project->update(['name'=>'Another project']);
        try {$this->wipe($op);$this->fail('Changed project accepted');}catch(\RuntimeException $e){$this->assertStringContainsString('changed',$e->getMessage());}
        $this->assertSame(0,$this->shell->wipes());
    }
    public function test_registration_and_reset_share_mac_lock_even_before_serial_discovery(): void {
        $reply=ModuleOperationLock::run(ModuleOperationLock::targetKeys(null,'02:00:00:00:00:20'),fn()=>
            $this->get('/scriptexecute?serial=10000000abcdef01&mac=02:00:00:00:00:20&storagesize=65536'));
        $reply->assertStatus(503);$this->assertSame(0,Cm::count());
    }
    public function test_network_gate_blocks_destructive_dispatch(): void {
        $gate=$this->createMock(NetworkChangeGate::class);$gate->method('start')->willReturn(response('busy',503));$this->app->instance(NetworkChangeGate::class,$gate);
        $op=$this->check();
        try {$this->wipe($op);$this->fail('Maintenance ignored');}catch(\RuntimeException $e){$this->assertStringContainsString('station operation',$e->getMessage());}
        $this->assertSame(0,$this->shell->wipes());$this->assertSame('checked',$op->fresh()->state);
    }
    public function test_pending_first_time_intent_rejects_same_mac_with_another_serial(): void {
        $op=$this->wipe($this->check());
        $body=$this->get('/scriptexecute?serial=1000000000000002&mac=02:00:00:00:00:20&storagesize=65536')->assertOk()->getContent();
        $this->assertStringContainsString('re-provision',$body);$this->assertSame(0,Cm::count());$this->assertSame('waiting',$op->fresh()->state);
    }
    public function test_project_destination_must_match_the_inspected_running_root_disk(): void {
        $this->project->update(['storage'=>'/dev/mmcblk1']);
        $op=$this->check();$this->assertSame('failed',$op->state);$this->assertSame(0,$this->shell->wipes());
    }
    public function test_a_slow_access_check_keeps_the_module_lock_until_it_finishes(): void {
        $shell=new class extends FakeSensorShell {
            public $onKey;
            public function key(string $address,string $operation,string $command): ShellResult {
                if ($this->onKey) { $f=$this->onKey;$this->onKey=null;$f(); }
                return parent::key($address,$operation,$command);
            }
        };
        $shell->identity=$this->shell->identity;$this->app->instance(SensorShell::class,$this->shell=$shell);
        $blocked=false;
        $shell->onKey=function () use (&$blocked) {
            $this->travel(121)->seconds();
            try {ModuleOperationLock::run(ModuleOperationLock::targetKeys(null,'02:00:00:00:00:20'),fn()=>null);}
            catch (\RuntimeException $e) {$blocked=true;}
        };
        $this->assertSame('checked',$this->check()->state);$this->assertTrue($blocked,'The target lock expired during inspection.');
    }
    public function test_registration_during_confirmation_invalidates_the_checked_target(): void {
        $op=$this->check();Cm::create(['serial'=>$op->serial,'mac'=>$op->mac,'phase'=>'done']);
        try {$this->wipe($op);$this->fail('A new local run was ignored');}
        catch (\RuntimeException $e) {$this->assertStringContainsString('registration changed',$e->getMessage());}
        $this->assertSame(0,$this->shell->wipes());
    }

    public function test_saved_account_without_sudo_does_not_hide_a_later_administrator(): void
    {
        $shell=new class extends FakeSensorShell {
            public function account(string $address,string $operation,string $user,string $password,string $command): ShellResult {
                if ($user==='wlanpi') return ShellResult::from(1,'wlanpi is not in the sudoers file.');
                return parent::account($address,$operation,$user,$password,$command);
            }
        };
        $shell->identity=$this->shell->identity;$shell->keyStatus='denied';$this->app->instance(SensorShell::class,$this->shell=$shell);
        app(\App\Services\Sensor\SavedAccounts::class)->save(null,'Administrator','admin','secret',true);
        $op=$this->check();$this->assertSame('checked',$op->state);$this->assertSame('admin',$op->username);
        $this->assertSame(0,Cm::count());
    }

    public function test_checked_account_keeps_its_full_confirmation_window_after_slow_revalidation(): void
    {
        $station=new class extends FakeReprovisionStation {
            public int $reads=0;
            public $slowRead;
            public function current(): array {
                if (++$this->reads===2) ($this->slowRead)();
                return parent::current();
            }
        };
        $station->slowRead=fn()=> $this->travel(80)->seconds();
        $this->app->instance(ReprovisionStation::class,$station);$this->shell->keyStatus='denied';
        $op=$this->check();$this->assertSame('checked',$op->state);
        $this->travel(81)->seconds();$this->assertFalse($op->expires_at->isPast());
        $this->assertSame('waiting',$this->wipe($op)->state);
    }

    public function test_confirmation_expiring_during_reinspection_never_dispatches(): void
    {
        $shell=new class extends FakeSensorShell {
            public int $reads=0;public $slowRead;
            public function key(string $address,string $operation,string $command): ShellResult {
                if (++$this->reads===2) ($this->slowRead)();
                return parent::key($address,$operation,$command);
            }
        };
        $shell->identity=$this->shell->identity;$shell->slowRead=fn()=> $this->travel(121)->seconds();
        $this->app->instance(SensorShell::class,$this->shell=$shell);$op=$this->check();
        try {$this->wipe($op);$this->fail('Expired confirmation dispatched');}
        catch (\RuntimeException $e) {$this->assertStringContainsString('expired',$e->getMessage());}
        $this->assertSame(0,$this->shell->wipes());
    }

}
