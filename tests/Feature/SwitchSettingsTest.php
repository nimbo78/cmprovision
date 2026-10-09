<?php

namespace Tests\Feature;

use App\Http\Livewire\SwitchSettings;
use App\Models\Cm;
use App\Models\Image;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\Snmp\SnmpClient;
use App\Services\SwitchPortFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeSnmp;
use Tests\TestCase;

/** The SNMP switch used to tell which port a module is plugged into is set up on the settings page. */
class SwitchSettingsTest extends TestCase
{
    use RefreshDatabase;

    const MODULE = 'e4:5f:01:62:9f:d3';

    protected $configs = [];

    protected function fakeSwitch()
    {
        $fake = new FakeSnmp(['' => [
            SwitchPortFinder::OID_QBRIDGE_FDB_PORT.'.10.228.95.1.98.159.211' => 5,
            SwitchPortFinder::OID_QBRIDGE_FDB_PORT.'.10.0.17.34.51.68.85' => 8,
            SwitchPortFinder::OID_BASEPORT_IFINDEX.'.5' => 5,
            SwitchPortFinder::OID_BASEPORT_IFINDEX.'.8' => 8,
            SwitchPortFinder::OID_IFNAME.'.5' => 'GE0/0/5',
            SwitchPortFinder::OID_IFNAME.'.8' => 'GE0/0/8',
        ]]);
        $configs = &$this->configs;
        $this->app->bind(SnmpClient::class, function ($app, $params) use ($fake, &$configs) {
            $configs[] = $params['config'];
            return $fake;
        });
        return $fake;
    }

    protected function setting($key)
    {
        return Setting::find('ethernetswitch_'.$key)->value ?? null;
    }

    public function test_the_settings_page_has_a_switch_section()
    {
        $this->actingAs(User::factory()->create())->get('/settings')->assertOk()->assertSee('Ethernet switch');
    }

    public function test_test_shows_ports_methods_and_known_modules()
    {
        $this->fakeSwitch();
        Cm::create(['serial' => '10000000e611110c', 'mac' => self::MODULE]);

        Livewire::test(SwitchSettings::class)
            ->set('host', '192.168.25.2')->set('version', '2c')->set('community', 'line-ro')
            ->call('test')
            ->assertSee('GE0/0/5')
            ->assertSee(self::MODULE)
            ->assertSee('10000000e611110c')
            ->assertSee('Q-BRIDGE-MIB');

        $this->assertSame('line-ro', $this->configs[0]['community']);
        $this->assertNull($this->setting('ip'), 'testing does not save');
    }

    public function test_a_switch_that_does_not_answer_is_reported_as_such()
    {
        $this->fakeSwitch()->timeout = true;

        Livewire::test(SwitchSettings::class)
            ->set('host', '192.0.2.1')->set('version', '2c')->set('community', 'wrong')
            ->call('test')
            ->assertSee('did not answer')
            ->assertDontSee('switch answered');
    }

    public function test_v2c_settings_are_saved_and_an_empty_address_switches_the_lookup_off()
    {
        Livewire::test(SwitchSettings::class)
            ->set('host', '192.168.25.2')->set('version', '2c')->set('community', 'line-ro')->set('method', 'auto')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('192.168.25.2', $this->setting('ip'));
        $this->assertSame('line-ro', $this->setting('snmp_community'));
        $this->assertSame('2c', $this->setting('snmp_version'));

        Livewire::test(SwitchSettings::class)->set('host', '')->call('save');
        $this->assertSame(0, Setting::where('key', 'like', 'ethernetswitch_%')->count());
    }

    public function test_secrets_are_not_shown_and_kept_when_left_empty()
    {
        foreach (['ip' => '192.168.25.2', 'snmp_version' => '3', 'snmp_user' => 'cmprov', 'snmp_auth_protocol' => 'SHA',
                  'snmp_auth_password' => 'auth-secret', 'snmp_priv_protocol' => 'AES', 'snmp_priv_password' => 'priv-secret'] as $k => $v)
            Setting::create(['key' => 'ethernetswitch_'.$k, 'value' => $v]);

        Livewire::test(SwitchSettings::class)
            ->assertSet('host', '192.168.25.2')->assertSet('user', 'cmprov')
            ->assertSet('authPassword', '')->assertSet('privPassword', '')
            ->assertDontSee('auth-secret')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('auth-secret', $this->setting('snmp_auth_password'));
        $this->assertSame('priv-secret', $this->setting('snmp_priv_password'));
    }

    public function test_settings_are_checked()
    {
        Livewire::test(SwitchSettings::class)
            ->set('host', '192.168.25.2')->set('version', '3')->set('user', '')
            ->set('authProtocol', 'SHA')->set('authPassword', 'short')
            ->call('save')
            ->assertHasErrors(['user', 'authPassword']);

        Livewire::test(SwitchSettings::class)
            ->set('host', '192.168.25.2')->set('version', '2c')->set('community', '')
            ->call('save')
            ->assertHasErrors(['community']);
    }

    public function test_a_module_gets_its_switch_port_as_board_and_the_working_method_is_remembered()
    {
        $this->fakeSwitch();
        Setting::create(['key' => 'ethernetswitch_ip', 'value' => '192.168.25.2']);
        Setting::create(['key' => 'ethernetswitch_snmp_community', 'value' => 'line-ro']);

        $image = new Image;
        $image->filename = 'os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => 'p', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
                                    'label_moment' => 'never', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);

        $this->get('/scriptexecute?serial=10000000e611110c&model=CM4&storagesize=62500000&mac='.self::MODULE)->assertOk();

        $this->assertSame('GE0/0/5', Cm::where('serial', '10000000e611110c')->value('provisioning_board'));
        $this->assertSame('qbridge', $this->setting('method_detected'));
    }
}
