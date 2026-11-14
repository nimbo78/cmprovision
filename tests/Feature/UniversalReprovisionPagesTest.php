<?php
namespace Tests\Feature;

use App\Http\Livewire\ReprovisionDialog;
use App\Models\{Cm,Image,Project,Setting,User};
use App\Services\Sensor\{Credentials,ReprovisionStation,SensorShell};
use App\Services\SensorAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\{FakeSensorShell,FakeReprovisionStation};
use Tests\TestCase;

class UniversalReprovisionPagesTest extends TestCase
{
    use RefreshDatabase;
    private string $leases;
    private FakeSensorShell $shell;
    private FakeReprovisionStation $station;
    protected function setUp(): void {
        parent::setUp();$this->actingAs(User::factory()->create());
        $image=Image::forceCreate(['filename'=>'test.img.gz','filename_extension'=>'gz','filename_on_server'=>'test.img.gz','sha256'=>str_repeat('a',64),'uncompressed_size'=>8388608]);
        $p=Project::create(['name'=>'Next project','device'=>'cm4','storage'=>'/dev/mmcblk0','image_id'=>$image->id,'label_moment'=>'never']);
        Setting::updateOrCreate(['key'=>'active_project'],['value'=>$p->id]);
        $this->leases=tempnam(sys_get_temp_dir(),'lease-');file_put_contents($this->leases,'0 02:00:00:00:00:20 172.20.0.20 first-time *');
        $this->app->instance(SensorAddress::class,new SensorAddress($this->leases,[]));
        $this->app->instance(ReprovisionStation::class,$this->station=new FakeReprovisionStation);
        $this->app->instance(SensorShell::class,$this->shell=new FakeSensorShell);
        $this->shell->identity=['agent'=>'2','serial'=>'10000000abcdef01','host'=>'first-time','storage'=>'/dev/mmcblk0',
            'cid'=>'0123456789abcdef0123456789abcdef','sectors'=>'65536','model'=>'Raspberry Pi Compute Module 4 Rev 1.1','revision'=>'c03141',
            'mac'=>'02:00:00:00:00:20','boot_order'=>'0xf21','provisioner'=>'0'];
    }
    protected function tearDown(): void {@unlink($this->leases);parent::tearDown();}
    public function test_manual_authenticated_page_does_not_probe_ssh_on_render(): void {
        $this->get('/reprovision?mac=02:00:00:00:00:20&address=172.20.0.20')->assertOk()->assertSee('Check access');
        $this->assertSame([],$this->shell->calls);$this->assertSame(0,Cm::count());
        auth('web')->logout();auth()->forgetGuards();$this->get('/reprovision')->assertRedirect('/login');
    }
    public function test_first_time_dialog_has_back_and_distinguishes_device_identity_from_records(): void {
        $c=Livewire::test(ReprovisionDialog::class,['mac'=>'02:00:00:00:00:20'])->call('begin')->call('check');
        $c->assertSet('error',null)->assertSee('Identity read from device')->assertSee('Next project')->assertSee('test.img.gz')->assertSee('Back');
        $this->assertSame(0,Cm::count());$c->call('back')->assertSet('operationId',null)->assertSee('Check access');
    }
    public function test_expired_checked_screen_offers_recheck_instead_of_destructive_button(): void {
        $c=Livewire::test(ReprovisionDialog::class,['mac'=>'02:00:00:00:00:20'])->call('begin')->call('check');
        $this->travel(121)->seconds();$c->call('$refresh')->assertSee('Check access again')->assertDontSee('Wipe and re-provision');
    }
    public function test_secondary_handoff_preserves_roles_and_contains_only_hints(): void {
        $this->station->snapshot['address']='172.20.0.2';$this->station->snapshot['dhcp']=['active'=>false,'enabled'=>'masked','boot_enabled'=>false];
        Setting::updateOrCreate(['key'=>'reprovision_primary_url'],['value'=>'http://primary.test:8080']);
        $this->get('/reprovision?mac=02:00:00:00:00:20&address=172.20.0.20')->assertOk()->assertSee('Open current primary')
            ->assertSee('http://primary.test:8080/reprovision?mac=02%3A00%3A00%3A00%3A00%3A20&amp;address=172.20.0.20',false);
        $this->assertSame([],$this->shell->calls);$this->assertSame('masked',$this->station->snapshot['dhcp']['enabled']);
    }
    public function test_password_stays_out_of_snapshot_and_back_clears_credentials(): void {
        $this->shell->keyStatus='denied';
        $c=Livewire::test(ReprovisionDialog::class,['mac'=>'02:00:00:00:00:20'])->call('begin')->call('check','Only-a-transient-secret');
        $c->assertSet('error',null);$id=$c->get('operationId');$this->assertNotNull($id);
        $this->assertStringNotContainsString('Only-a-transient-secret',json_encode($c->snapshot));
        $this->assertStringNotContainsString('Only-a-transient-secret',$c->html());
        $c->call('back');$this->assertNull(app(Credentials::class)->get($id));
    }
    public function test_cms_offer_the_shared_manual_entry(): void {
        $this->get('/cms')->assertOk()->assertSee('href="'.route('reprovision').'"',false);
        $this->assertSame([],$this->shell->calls);
    }
    public function test_primary_origin_setting_requires_password_confirmation_and_rejects_non_origins(): void {
        $this->withSession(['auth.password_confirmed_at'=>time()]);
        $c=Livewire::test(\App\Http\Livewire\NetworkSettings::class)->set('primaryWebUrl','https://primary.test/path')->call('savePrimaryUrl');
        $c->assertHasErrors('primaryWebUrl');$this->assertNull(Setting::find('reprovision_primary_url'));
        $c->set('primaryWebUrl','http://primary.test:8080/')->call('savePrimaryUrl')->assertHasNoErrors();
        $this->assertSame('http://primary.test:8080',Setting::find('reprovision_primary_url')->value);
        $c->set('primaryWebUrl','')->call('savePrimaryUrl');$this->assertNull(Setting::find('reprovision_primary_url'));
    }

    public function test_unconfirmed_network_boot_links_to_existing_usb_preparation(): void
    {
        $this->shell->identity['boot_order']='unknown';
        Livewire::test(ReprovisionDialog::class,['mac'=>'02:00:00:00:00:20'])->call('begin')->call('check')
            ->assertSee('Network boot could not be confirmed')
            ->assertSee('href="'.route('settings',['tab'=>'provisioning']).'#usb-prep"',false);
        $this->assertSame(0,$this->shell->wipes());
    }

}
