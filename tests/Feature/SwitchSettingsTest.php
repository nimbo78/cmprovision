<?php

namespace Tests\Feature;

use App\Http\Livewire\SwitchSettings;
use App\Models\Cm;
use App\Models\EthernetSwitch;
use App\Models\Image;
use App\Models\PoeOutage;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\Snmp\SnmpClient;
use App\Services\Switch\SwitchSnapshot;
use App\Services\SwitchPortFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use App\Services\Switch\Stand;
use Tests\Support\FakeSnmp;
use Tests\Support\PoeStand;
use Tests\Support\SwitchRow;
use Tests\TestCase;

/** The SNMP switches that tell which port a module is plugged into are listed and set up on the settings page. */
class SwitchSettingsTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    const MODULE = 'e4:5f:01:62:9f:d3';

    protected $configs = [];

    protected function setUp(): void
    {
        parent::setUp();
        // saving reads the switch's name and base MAC: no real SNMP unless a test fakes a switch
        $this->app->bind(SnmpClient::class, function () { return new FakeSnmp(['' => []]); });
    }

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
        return SwitchRow::get($key);
    }

    public function test_the_settings_page_has_a_switch_section()
    {
        $this->actingAs(User::factory()->create())->get('/settings?tab=network')->assertOk()->assertSee('Ethernet switches');
    }

    public function test_test_shows_ports_methods_and_known_modules()
    {
        $this->fakeSwitch();
        Cm::create(['serial' => '10000000e611110c', 'mac' => self::MODULE]);

        Livewire::test(SwitchSettings::class)
            ->call('add')->set('host', '192.168.25.2')->set('version', '2c')->set('community', 'line-ro')
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
            ->call('add')->set('host', '192.0.2.1')->set('version', '2c')->set('community', 'wrong')
            ->call('test')
            ->assertSee('did not answer')
            ->assertDontSee('switch answered');
    }

    public function test_v2c_settings_are_saved_and_deleting_the_switch_switches_the_lookup_off()
    {
        Livewire::test(SwitchSettings::class)
            ->call('add')->set('host', '192.168.25.2')->set('version', '2c')->set('community', 'line-ro')->set('method', 'auto')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('192.168.25.2', $this->setting('ip'));
        $this->assertSame('line-ro', $this->setting('snmp_community'));
        $this->assertSame('2c', $this->setting('snmp_version'));

        Livewire::test(SwitchSettings::class)->call('delete', EthernetSwitch::sole()->id)->assertSee('Boards come from the jumpers again');
        $this->assertSame(0, EthernetSwitch::count());
    }

    public function test_secrets_are_not_shown_and_kept_when_left_empty()
    {
        SwitchRow::put(['ip' => '192.168.25.2', 'snmp_version' => '3', 'snmp_user' => 'cmprov', 'snmp_auth_protocol' => 'SHA',
                  'snmp_auth_password' => 'auth-secret', 'snmp_priv_protocol' => 'AES', 'snmp_priv_password' => 'priv-secret']);

        Livewire::test(SwitchSettings::class)->call('edit', 1)
            ->assertSet('host', '192.168.25.2')->assertSet('user', 'cmprov')
            ->assertSet('authPassword', '')->assertSet('privPassword', '')
            ->assertDontSee('auth-secret')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('auth-secret', $this->setting('snmp_auth_password'));
        $this->assertSame('priv-secret', $this->setting('snmp_priv_password'));
    }

    public function test_stored_secrets_are_never_sent_to_another_switch()
    {
        $this->fakeSwitch();
        SwitchRow::put(['ip' => '192.168.25.2', 'snmp_version' => '2c', 'snmp_community' => 'stored-secret']);

        Livewire::test(SwitchSettings::class)->call('edit', 1)->set('host', '203.0.113.9')->call('test');
        $this->assertNotSame('stored-secret', $this->configs[0]['community'] ?? null, 'test with a new address uses no stored community');

        Livewire::test(SwitchSettings::class)->call('edit', 1)->set('host', '203.0.113.9')->call('save')->assertHasErrors(['community']);
        $this->assertSame('192.168.25.2', $this->setting('ip'));

        // the same switch keeps its stored community
        Livewire::test(SwitchSettings::class)->call('edit', 1)->call('test');
        $this->assertSame('stored-secret', end($this->configs)['community']);
    }

    public function test_settings_are_checked()
    {
        Livewire::test(SwitchSettings::class)
            ->call('add')->set('host', '192.168.25.2')->set('version', '3')->set('user', '')
            ->set('authProtocol', 'SHA')->set('authPassword', 'short')
            ->call('save')
            ->assertHasErrors(['user', 'authPassword']);

        Livewire::test(SwitchSettings::class)
            ->call('add')->set('host', '192.168.25.2')->set('version', '2c')->set('community', '')
            ->call('save')
            ->assertHasErrors(['community']);
    }

    public function test_one_switch_under_two_addresses_is_named_in_the_list()
    {
        $this->stand();
        $this->secondSwitch(['write_community' => '']);
        foreach ([$this->fake, $this->fake2] as $fake) {
            $fake->tables[''][SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0'] = hex2bin('0057d297a180');
            $fake->tables[''][SwitchSnapshot::OID_SYSNAME.'.0'] = 'sw01';
        }
        Stand::current();   // what each address answered stays in the snapshot cache
        Livewire::test(SwitchSettings::class)
            ->assertSee('sw01 (192.0.2.10) and sw01 (192.0.2.11) are the same switch (base MAC 00:57:d2:97:a1:80): delete one of them.')
            ->assertDontSee('Two switches are called');
    }

    public function test_each_row_tests_its_switch_and_checks_its_write_access()
    {
        $this->fakeSwitch();
        EthernetSwitch::create(['host' => '192.168.25.2', 'community' => 'line-ro', 'sys_name' => 'sw01']);
        Livewire::test(SwitchSettings::class)
            ->assertSeeHtml('wire:click="testSwitch(1)"')->assertSeeHtml('wire:click="checkSwitch(1)"')
            ->call('testSwitch', 1)
            ->assertSet('editingId', 1)
            ->assertSee('GE0/0/5')
            ->call('checkSwitch', 1)
            ->assertSee('Enter the write community (v2c) or the write user (v3) first.');
        $this->assertSame('line-ro', $this->configs[0]['community'], "the row's own community");
    }

    public function test_switches_are_listed_and_a_second_one_is_added()
    {
        EthernetSwitch::create(['host' => '192.0.2.10', 'community' => 'public', 'sys_name' => 'sw01']);
        Livewire::test(SwitchSettings::class)
            ->assertSee('sw01')->assertSee('192.0.2.10')
            ->call('add')->set('host', '192.0.2.11')->set('community', 'public')->call('save')->assertHasNoErrors();
        $this->assertSame(['192.0.2.10', '192.0.2.11'], EthernetSwitch::orderBy('id')->pluck('host')->all());
    }

    public function test_an_address_in_the_list_is_not_added_twice()
    {
        EthernetSwitch::create(['host' => '192.0.2.10', 'community' => 'public']);
        Livewire::test(SwitchSettings::class)
            ->call('add')->set('host', '192.0.2.10')->set('community', 'public')->call('save')->assertHasErrors(['host']);
        $this->assertSame(1, EthernetSwitch::count());
    }

    public function test_a_secret_stays_with_its_own_switch()
    {
        EthernetSwitch::create(['host' => '192.0.2.10', 'community' => 'secret-one']);
        $two = EthernetSwitch::create(['host' => '192.0.2.11', 'community' => 'secret-two']);
        $page = Livewire::test(SwitchSettings::class)->call('edit', $two->id);
        $this->assertStringNotContainsString('secret-two', $page->html());
        $this->assertStringNotContainsString('secret-one', $page->html());
        $page->set('method', 'bridge')->call('save')->assertHasNoErrors();
        $this->assertSame('secret-two', $two->fresh()->community, 'left empty: kept for its own switch');
        $page->call('edit', $two->id)->set('host', '192.0.2.12')->call('save')->assertHasErrors(['community']);
    }

    public function test_the_same_switch_under_another_address_is_refused()
    {
        EthernetSwitch::create(['host' => '192.0.2.10', 'base_mac' => '00:57:d2:97:a1:80', 'sys_name' => 'sw01']);
        $fake = $this->fakeSwitch();   // answers any address: here 192.0.2.20, the same switch
        $fake->tables[''][SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0'] = hex2bin('0057d297a180');
        Livewire::test(SwitchSettings::class)->call('add')->set('host', '192.0.2.20')->set('community', 'public')->call('save')
            ->assertHasErrors(['host'])->assertSee('This is sw01 (192.0.2.10) under another address');
        $this->assertSame(1, EthernetSwitch::count());
    }

    public function test_a_switch_with_a_port_left_off_cannot_be_deleted()
    {
        $switch = EthernetSwitch::create(['host' => '192.0.2.10']);
        PoeOutage::create(['switch_id' => $switch->id, 'switch_host' => '192.0.2.10', 'if_index' => 8, 'port' => 'sw01 GE0/0/3',
            'oid' => '.1.3.6.1.4.1.2011.5.25.195.3.1.3.8', 'on_value' => 1, 'off_at' => now()->subMinutes(5), 'error' => 'timeout']);
        Livewire::test(SwitchSettings::class)->call('delete', $switch->id)->assertSee('Switch them on (Power on) or forget them first');
        $this->assertSame(1, EthernetSwitch::count());
    }

    public function test_test_and_save_read_the_name_and_base_mac()
    {
        $fake = $this->fakeSwitch();
        $fake->tables[''][SwitchSnapshot::OID_SYSNAME.'.0'] = 'sw03.corp.example.com';
        $fake->tables[''][SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0'] = hex2bin('0057d297a180');
        Livewire::test(SwitchSettings::class)
            ->call('add')->set('host', '192.0.2.13')->set('community', 'public')
            ->call('test')->assertSee('sw03.corp.example.com')->assertSee('00:57:d2:97:a1:80')
            ->call('save')->assertHasNoErrors();
        $switch = EthernetSwitch::sole();
        $this->assertSame(['sw03.corp.example.com', '00:57:d2:97:a1:80', 'sw03'], [$switch->sys_name, $switch->base_mac, $switch->hostname()]);
    }

    public function test_a_new_address_forgets_what_the_old_switch_said()
    {
        $switch = EthernetSwitch::create(['host' => '192.0.2.10', 'community' => 'public', 'sys_name' => 'sw01', 'base_mac' => '00:57:d2:97:a1:80',
            'own_macs' => ['00:57:d2:97:a1:80'], 'links' => [24 => 2], 'method_detected' => 'bridge']);
        Livewire::test(SwitchSettings::class)->call('edit', $switch->id)->set('host', '192.0.2.30')->set('community', 'public')
            ->call('save')->assertHasNoErrors();
        $switch->refresh();
        $this->assertSame(['192.0.2.30', null, null, null, null, null],
            [$switch->host, $switch->sys_name, $switch->base_mac, $switch->own_macs, $switch->links, $switch->method_detected]);
    }

    public function test_a_module_gets_its_switch_port_as_board_and_the_working_method_is_remembered()
    {
        $fake = $this->fakeSwitch();
        $fake->tables[''] += [SwitchSnapshot::OID_SYSNAME.'.0' => 'sw01.stand.example',
            SwitchSnapshot::OID_IFTYPE.'.5' => 6, SwitchSnapshot::OID_IFOPERSTATUS.'.5' => 1,
            SwitchSnapshot::OID_IFTYPE.'.8' => 6, SwitchSnapshot::OID_IFOPERSTATUS.'.8' => 1];
        SwitchRow::put(['ip' => '192.168.25.2', 'snmp_community' => 'line-ro']);

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

        $cm = Cm::where('serial', '10000000e611110c')->first();
        $this->assertSame(['sw01 GE0/0/5', 1, 'sw01', 'GE0/0/5'], [$cm->provisioning_board, $cm->switch_id, $cm->switch_name, $cm->switch_port]);
        $this->assertSame('qbridge', $this->setting('method_detected'));
    }
}
