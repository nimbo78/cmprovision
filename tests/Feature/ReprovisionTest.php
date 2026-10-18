<?php

namespace Tests\Feature;

use App\Http\Livewire\ReprovisionDialog;
use App\Http\Livewire\Projects;
use App\Models\{Cm,Cmlog,Image,Project,Script,Setting,User,ReprovisionOperation};
use App\Services\{SensorAddress,ModuleOperationLock};
use App\Services\Sensor\{Reprovision,SensorShell,Credentials,ReprovisionIntent,SavedAccounts};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\FakeSensorShell;
use Tests\TestCase;

class ReprovisionTest extends TestCase
{
    use RefreshDatabase;
    private Cm $cm;
    private Project $project;
    private User $user;
    private FakeSensorShell $shell;
    private string $leases;
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(); $this->actingAs($this->user);
        $image = Image::forceCreate(['filename'=>'test.img.gz','filename_extension'=>'gz','filename_on_server'=>'test.img.gz',
            'sha256'=>str_repeat('a',64),'uncompressed_size'=>8388608]);
        $this->project = Project::create(['name'=>'Re-provision test','device'=>'cm4','storage'=>'/dev/mmcblk0','image_id'=>$image->id,'label_moment'=>'never']);
        Setting::updateOrCreate(['key'=>'active_project'],['value'=>$this->project->id]);
        $this->cm = Cm::create(['serial'=>'10000000abcdef01','mac'=>'02:00:00:00:00:10','phase'=>'done','storage'=>33554432,
            'cid'=>'0123456789abcdef0123456789abcdef','project_id'=>$this->project->id,'provisioning_storage'=>'/dev/mmcblk0',
            'provisioning_started_at'=>now()->subMinutes(10),'durable_bytes'=>8388608,'durable_at'=>now()]);
        $this->leases = tempnam(sys_get_temp_dir(), 'cmprov-leases-');
        file_put_contents($this->leases, '0 02:00:00:00:00:10 172.20.0.10 test-sensor *');
        $this->app->instance(SensorAddress::class, new SensorAddress($this->leases, []));
        $this->app->instance(SensorShell::class, $this->shell = new FakeSensorShell);
        // Existing scenarios exercise explicit account entry; automatic profiles have their own cases below.
        app(SavedAccounts::class)->toggle('wlanpi');
    }
    protected function tearDown(): void { if (isset($this->leases)) @unlink($this->leases); parent::tearDown(); }
    private function check(?array $account = null): ReprovisionOperation
    {
        return app(Reprovision::class)->check($this->cm->serial, $this->user->id, 'test-session', null, $account);
    }
    private function wipe(ReprovisionOperation $op): ReprovisionOperation
    {
        return app(Reprovision::class)->wipe($op->id, $this->user->id, 'test-session', 'Test operator');
    }
    public function test_key_wipe_is_idempotent_and_clears_resume_before_dispatch(): void
    {
        $op = $this->check(); $this->assertSame('checked', $op->state);
        $this->assertSame('waiting', $this->wipe($op)->state);
        $this->assertNull($this->cm->fresh()->durable_bytes);
        $this->wipe($op); $this->assertSame(1, $this->shell->wipes());
        $this->assertStringContainsString('wiped over', Cmlog::latest('id')->first()->msg);
    }
    public function test_password_change_account_fallback_and_secret_cleanup(): void
    {
        $this->shell->keyStatus = 'denied'; $this->shell->expired = true;
        $op = $this->check(['user'=>'wlanpi','password'=>'Old-secret-123!']);
        $this->assertSame('password_expired', $op->state);
        $encrypted = Cache::get('reprovision-secret:'.$op->id);
        $this->assertStringNotContainsString('Old-secret-123!', $encrypted);
        $op = app(Reprovision::class)->changePassword($op->id,$this->user->id,'test-session','New-secret-456!','New-secret-456!');
        $this->assertSame('checked', $op->state); $this->assertTrue($op->password_changed);
        $this->assertSame('waiting', $this->wipe($op)->state);
        $this->assertNull(app(Credentials::class)->get($op->id));
        $this->assertStringNotContainsString('secret-', Cmlog::pluck('msg')->implode("\n"));
    }
    public function test_expired_root_key_login_can_fall_back_to_another_account(): void
    {
        $this->shell->keyStatus='password_expired';
        $op=$this->check(['user'=>'wlanpi','password'=>'Account-secret-123!']);
        $this->assertSame('checked',$op->state); $this->assertSame('account',$op->method);
    }
    public function test_a_different_serial_or_disk_is_refused_before_wipe(): void
    {
        $this->shell->identity['cid'] = str_repeat('a',32);
        $this->assertSame('failed', $this->check()->state);
        $this->assertSame(0, $this->shell->wipes());
    }
    public function test_access_check_expires(): void
    {
        $op=$this->check(); $this->travel(121)->seconds();
        $this->expectExceptionMessage('expired'); $this->wipe($op);
    }
    public function test_project_edit_after_check_invalidates_confirmation(): void
    {
        $op=$this->check(); $this->project->update(['storage'=>'/dev/mmcblk1']);
        $this->expectExceptionMessage('project changed'); $this->wipe($op);
    }
    public function test_script_edit_changes_the_project_fingerprint(): void
    {
        $script=Script::create(['name'=>'post','script_type'=>'postinstall','priority'=>50,'bg'=>false,'script'=>'true']);
        $this->project->scripts()->attach($script);
        $op=$this->check(); $script->update(['script'=>'false']);
        $this->expectExceptionMessage('project changed'); $this->wipe($op);
    }
    public function test_changed_address_after_check_is_not_used(): void
    {
        $op=$this->check(); file_put_contents($this->leases, '0 02:00:00:00:00:10 172.20.0.11 test-sensor *');
        $this->expectExceptionMessage('address or project changed'); $this->wipe($op);
    }
    public function test_wipe_without_a_successful_check_does_nothing(): void
    {
        $this->shell->keyStatus='denied'; $op=$this->check();
        $this->assertSame('account', $this->wipe($op)->state); $this->assertSame(0,$this->shell->wipes());
    }
    public function test_a_new_run_between_check_and_wipe_blocks_the_action(): void
    {
        $op=$this->check(); $this->cm->update(['phase'=>'firstboot','provisioning_started_at'=>now()]);
        $this->expectExceptionMessage('first boot'); $this->wipe($op);
    }
    public function test_an_unknown_ssh_result_is_not_retried(): void
    {
        $op=$this->check(); $this->shell->wipeStatus='unreachable';
        $this->assertSame('unknown',$this->wipe($op)->state);
        $this->wipe($op); $this->assertSame(1,$this->shell->wipes());
    }
    public function test_two_operators_cannot_dispatch_two_checked_requests(): void
    {
        $first=$this->check(); $second=$this->check(); $this->wipe($first);
        $this->expectExceptionMessage('already pending'); $this->wipe($second);
    }
    public function test_a_check_is_bound_to_the_web_session(): void
    {
        $op=$this->check();
        try { app(Reprovision::class)->wipe($op->id,$this->user->id,'another-session','Operator'); $this->fail('Foreign session accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        $this->assertSame(0,$this->shell->wipes());
    }
    public function test_pending_boot_refuses_a_changed_project_until_explicitly_confirmed(): void
    {
        $this->wipe($this->check()); $this->project->update(['name'=>'Changed project']);
        $this->assertStringContainsString('project changed',ReprovisionIntent::guard($this->cm->serial,$this->project,$this->cm->mac));
        app(Reprovision::class)->confirmBoot($this->cm->serial,'Operator');
        $this->assertNull(ReprovisionIntent::guard($this->cm->serial,$this->project,$this->cm->mac));
        $response=$this->get('/scriptexecute?serial='.$this->cm->serial.'&mac='.$this->cm->mac.'&storagesize=65536')->assertOk();
        $this->assertSame('booted',ReprovisionOperation::first()->state);
        $this->assertSame('/dev/mmcblk0',$this->cm->fresh()->provisioning_storage);
    }
    public function test_module_lock_blocks_a_concurrent_action(): void
    {
        $this->expectExceptionMessage('Another operation');
        ModuleOperationLock::run([$this->cm->serial],fn()=> $this->check());
    }
    public function test_livewire_never_returns_the_sensor_password(): void
    {
        $this->shell->keyStatus='denied';
        $component=Livewire::test(ReprovisionDialog::class,['serial'=>$this->cm->serial])->call('begin')->call('check');
        $component->call('check','Very-private-123!')->assertSet('error',null)->assertSee('Serial matches');
        $this->assertStringNotContainsString('Very-private-123!',json_encode($component->snapshot));
        $this->assertStringNotContainsString('Very-private-123!',$component->html());
        $id=$component->get('operationId'); $component->call('close');
        $this->assertNull(app(Credentials::class)->get($id));
    }
    public function test_project_flag_defaults_off_and_round_trips_through_ui_and_api(): void
    {
        $this->assertFalse($this->project->fresh()->reprovision);
        Livewire::test(Projects::class)->call('edit',$this->project->id)->set('reprovision',true)->call('store')->assertHasNoErrors();
        $this->assertTrue($this->project->fresh()->reprovision);
        Sanctum::actingAs($this->user,['update']);
        $this->patchJson('/api/projects/'.$this->project->id,['reprovision'=>false])->assertOk()->assertJsonPath('reprovision',false);
    }
    public function test_provisioning_script_installs_only_the_public_key_and_agent_when_opted_in(): void
    {
        $this->project->update(['reprovision'=>true,'os_check'=>true]);
        $script=$this->get('/scriptexecute?serial='.$this->cm->serial.'&mac='.$this->cm->mac.'&storagesize=65536')->assertOk()->getContent();
        $this->assertStringContainsString('Re-provision agent',$script);
        $this->assertStringContainsString('restrict,command=', $script);
        $this->assertStringNotContainsString('BEGIN OPENSSH PRIVATE KEY',$script);
        $this->assertLessThan(strpos($script,"progress_mark postinstall 'Installing the OS check'"),strpos($script,"Running post-installation script 'Re-provision agent'"));
    }

    public function test_key_success_never_tries_saved_passwords(): void
    {
        app(SavedAccounts::class)->toggle('wlanpi');
        $this->assertSame('key', $this->check()->method);
        $this->assertSame([], $this->shell->accountAttempts);
    }

    public function test_saved_account_is_checked_and_cached_for_confirmed_wipe(): void
    {
        app(SavedAccounts::class)->toggle('wlanpi'); $this->shell->keyStatus = 'denied';
        $op = $this->check();
        $this->assertSame('checked', $op->state); $this->assertSame('account', $op->method);
        $this->assertSame('wlanpi', $op->username);
        $this->assertSame(['user'=>'wlanpi','password'=>'wlanpi'], app(Credentials::class)->get($op->id));
        $this->assertSame(0, $this->shell->wipes());
        $this->assertSame('waiting', $this->wipe($op)->state);
        $this->assertSame(1, $this->shell->wipes());
    }

    public function test_saved_accounts_follow_order_and_skip_disabled_profiles(): void
    {
        $a = app(SavedAccounts::class);
        $a->save(null,'Bad account','first','bad-secret',true);
        $a->save(null,'Good account','second','good-secret',true);
        $this->shell->keyStatus = 'denied';
        $this->shell->accountResults = ["second\0good-secret"=>'ok'];
        $op = $this->check();
        $this->assertSame('checked',$op->state); $this->assertSame('second',$op->username);
        $this->assertSame(['first','second','second'],array_column($this->shell->accountAttempts,0));
    }

    public function test_saved_expired_account_opens_password_change_without_asking_for_old_password(): void
    {
        app(SavedAccounts::class)->save(null,'Factory','factory','factory-old-secret',true);
        $this->shell->keyStatus='denied'; $this->shell->expired=true;
        $op=$this->check();
        $this->assertSame('password_expired',$op->state); $this->assertSame('factory',$op->username);
        $this->assertSame('factory-old-secret',app(Credentials::class)->get($op->id)['password']);
        $op=app(Reprovision::class)->changePassword($op->id,$this->user->id,'test-session','factory-new-secret','factory-new-secret');
        $this->assertSame('checked',$op->state);
        // A one-device password change must not replace the shared saved profile.
        $this->assertSame('factory-old-secret',app(SavedAccounts::class)->enabled()[0]['password']);
    }

    public function test_exhausted_profiles_offer_manual_entry_and_are_not_repeated(): void
    {
        app(SavedAccounts::class)->toggle('wlanpi'); $this->shell->keyStatus='denied'; $this->shell->accountStatus='denied';
        $op=$this->check(); $this->assertSame('account',$op->state);
        $this->assertSame(1,count($this->shell->accountAttempts));
        app(Reprovision::class)->check($this->cm->serial,$this->user->id,'test-session',$op->id);
        $this->check(); // A fresh dialog/operator still shares the per-module cooldown.
        $this->assertSame(1,count($this->shell->accountAttempts));
        $this->shell->accountStatus='ok';
        $op=app(Reprovision::class)->check($this->cm->serial,$this->user->id,'test-session',$op->id,['user'=>'manual','password'=>'manual-secret']);
        $this->assertSame('checked',$op->state); $this->assertSame('manual',$op->username);
    }

    public function test_network_or_host_key_failure_does_not_try_more_accounts(): void
    {
        app(SavedAccounts::class)->save(null,'First','one','one-secret',true);
        app(SavedAccounts::class)->save(null,'Second','two','two-secret',true);
        $this->shell->keyStatus='denied'; $this->shell->accountStatus='host_changed';
        $this->assertSame('account',$this->check()->state);
        $this->assertCount(1,$this->shell->accountAttempts);
    }

    public function test_saved_login_with_wrong_identity_stops_before_another_account_or_wipe(): void
    {
        app(SavedAccounts::class)->toggle('wlanpi'); $this->shell->keyStatus='denied';
        $this->shell->identity['serial']='1000000000000001';
        $op=$this->check(); $this->assertSame('failed',$op->state);
        $this->assertCount(1,$this->shell->accountAttempts); $this->assertSame(0,$this->shell->wipes());
        $this->assertNull(app(Credentials::class)->get($op->id));
    }

    public function test_manual_account_is_not_preceded_by_saved_attempts(): void
    {
        app(SavedAccounts::class)->toggle('wlanpi'); $this->shell->keyStatus='denied';
        $op=$this->check(['user'=>'custom','password'=>'manual-secret']);
        $this->assertSame('checked',$op->state);
        $this->assertSame(['custom','custom'],array_column($this->shell->accountAttempts,0));
    }
}
