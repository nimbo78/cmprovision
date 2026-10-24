<?php

namespace Tests\Feature;

use App\Http\Livewire\Cms;
use App\Http\Livewire\NetboxSettings;
use App\Models\Cm;
use App\Models\Image;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\NetBoxExport;
use App\Services\ServiceState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\FakeServiceState;
use Tests\Support\FakeWifiNetworks;
use App\Services\WifiNetworks;
use Tests\TestCase;

/** The files NetBox imports (2.21.0): the devices, their interfaces and the MAC addresses of the modules that finished */
class NetBoxExportTest extends TestCase
{
    use RefreshDatabase;

    protected $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-24 15:00:00');
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $this->project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected $n = 0;

    protected function module(string $phase = 'done', array $attributes = [])
    {
        $n = ++$this->n;
        return Cm::create(array_merge(['serial' => sprintf('10000000e611%04x', $n), 'mac' => sprintf('e4:5f:01:62:9f:%02x', $n), 'model' => 'CM4',
            'project_id' => $this->project->id, 'phase' => $phase, 'provisioning_started_at' => now()->subMinutes(30),
            'provisioning_complete_at' => $phase === 'done' ? now()->subMinutes(24) : null], $attributes));
    }

    /* the CSV as [header, rows keyed by header] */
    protected function parse(string $csv): array
    {
        $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), array_values(array_filter(explode("\n", $csv))));
        $header = array_shift($lines);
        return [$header, array_map(fn ($line) => array_combine($header, $line), $lines)];
    }

    public function test_only_modules_that_finished_a_provisioning_run_are_exported()
    {
        $done = $this->module('done');
        $this->module('write');
        $this->module('failed', ['phase_started_at' => now()]);
        $this->module('firstboot');
        $this->module('done', ['usb_prep_at' => now()->subHour()]);   // a board that only had its bootloader set over USB

        $this->assertSame([$done->id], NetBoxExport::exportable(Cm::all())->pluck('id')->all());
    }

    public function test_the_devices_file_carries_the_settings_the_serial_and_where_the_module_came_from()
    {
        $this->module('done', ['hostname' => 'wlanpi-fd3']);

        [$header, $rows] = $this->parse(NetBoxExport::csv('devices', Cm::all()));

        $this->assertSame(['name', 'role', 'manufacturer', 'device_type', 'status', 'site', 'serial', 'comments'], $header);
        $this->assertSame(['name' => 'wlanpi-fd3', 'role' => 'Sensor', 'manufacturer' => 'Raspberry Pi', 'device_type' => 'Compute Module 4',
            'status' => 'active', 'site' => 'Default', 'serial' => '10000000e6110001',
            'comments' => 'Provisioned by cmprovision, project 78, 2026-10-24 14:36 UTC'], $rows[0]);
    }

    public function test_the_settings_of_the_operator_go_into_the_files()
    {
        NetBoxExport::save(['site' => 'Moscow lab', 'role' => 'Sensor, field', 'manufacturer' => 'Raspberry Pi Ltd', 'device_type' => 'CM4 8/32',
            'status' => 'staged', 'interface' => 'end0', 'interface_type' => '2.5gbase-t', 'mac_style' => 'objects']);
        $this->module('done', ['hostname' => 'wlanpi-fd3']);

        [, $devices] = $this->parse(NetBoxExport::csv('devices', Cm::all()));
        [, $interfaces] = $this->parse(NetBoxExport::csv('interfaces', Cm::all()));

        $this->assertSame(['Moscow lab', 'Sensor, field', 'Raspberry Pi Ltd', 'CM4 8/32', 'staged'],
            [$devices[0]['site'], $devices[0]['role'], $devices[0]['manufacturer'], $devices[0]['device_type'], $devices[0]['status']], 'a comma in a value is quoted');
        $this->assertSame(['device' => 'wlanpi-fd3', 'name' => 'end0', 'type' => '2.5gbase-t'], $interfaces[0]);
    }

    public function test_a_module_without_a_usable_host_name_is_named_from_its_mac_and_so_is_every_one_of_a_name_two_have()
    {
        $this->module('done', ['hostname' => 'wlanpi-aaa']);
        $this->module('done', ['hostname' => 'twin']);
        $this->module('done', ['hostname' => 'TWIN']);              // the same name, NetBox compares them without the case
        $this->module('done', ['hostname' => null]);
        $this->module('done', ['hostname' => '=cmd|calc']);       // what a spreadsheet would run
        $this->module('done', ['hostname' => 'a name with spaces']);
        $this->module('done', ['hostname' => str_repeat('x', 65)]);

        $names = array_column($this->parse(NetBoxExport::csv('devices', Cm::all()))[1], 'name');

        $this->assertSame(['wlanpi-aaa', 'cm-e45f01629f02', 'cm-e45f01629f03', 'cm-e45f01629f04', 'cm-e45f01629f05', 'cm-e45f01629f06', 'cm-e45f01629f07'], $names);
        $this->assertCount(7, array_unique($names));
    }

    public function test_the_interfaces_and_the_mac_addresses_of_netbox_4_2_and_newer_are_two_files_that_name_the_same_devices()
    {
        $this->module('done', ['hostname' => 'wlanpi-fd3', 'mac' => 'e4:5f:01:62:9f:d3']);

        [$ifHeader, $interfaces] = $this->parse(NetBoxExport::csv('interfaces', Cm::all()));
        [$macHeader, $macs] = $this->parse(NetBoxExport::csv('macs', Cm::all()));

        $this->assertSame(['device', 'name', 'type'], $ifHeader);
        $this->assertSame(['device' => 'wlanpi-fd3', 'name' => 'eth0', 'type' => '1000base-t'], $interfaces[0]);
        $this->assertSame(['mac_address', 'device', 'interface', 'is_primary'], $macHeader);
        $this->assertSame(['mac_address' => 'E4:5F:01:62:9F:D3', 'device' => 'wlanpi-fd3', 'interface' => 'eth0', 'is_primary' => 'true'], $macs[0]);
    }

    public function test_before_netbox_4_2_the_mac_address_is_a_column_of_the_interfaces_and_there_is_no_third_file()
    {
        NetBoxExport::save(['mac_style' => 'column'] + NetBoxExport::DEFAULTS);
        $this->module('done', ['hostname' => 'wlanpi-fd3', 'mac' => 'e4:5f:01:62:9f:d3']);

        [$header, $rows] = $this->parse(NetBoxExport::csv('interfaces', Cm::all()));

        $this->assertSame(['device', 'name', 'type', 'mac_address'], $header);
        $this->assertSame('E4:5F:01:62:9F:D3', $rows[0]['mac_address']);
        $this->assertSame("mac_address,device,interface,is_primary\n", NetBoxExport::csv('macs', Cm::all()), 'only the header');
    }

    public function test_a_list_with_nothing_to_export_gives_the_header_only()
    {
        $this->module('write');
        $this->assertSame("name,role,manufacturer,device_type,status,site,serial,comments\n", NetBoxExport::csv('devices', Cm::all()));
    }

    public function test_an_unknown_kind_is_refused()
    {
        $this->expectException(\InvalidArgumentException::class);
        NetBoxExport::csv('racks', Cm::all());
    }

    public function test_a_project_name_that_looks_like_a_formula_does_not_start_a_cell()
    {
        $this->project->update(['name' => '=1+1']);
        $this->module('done', ['hostname' => 'wlanpi-fd3']);

        [, $rows] = $this->parse(NetBoxExport::csv('devices', Cm::with('project')->get()));

        $this->assertStringStartsWith('Provisioned by cmprovision', $rows[0]['comments']);
    }

    public function test_the_cms_page_downloads_the_files_of_the_list_on_the_screen_in_its_order()
    {
        $other = Project::create(['name' => 'other', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $this->project->image_id, 'label_moment' => 'never', 'verify' => false]);
        $first = $this->module('done', ['hostname' => 'wlanpi-aaa', 'provisioning_complete_at' => now()->subHour()]);
        $latest = $this->module('done', ['hostname' => 'wlanpi-bbb', 'provisioning_complete_at' => now()->subMinutes(5)]);
        $this->module('done', ['hostname' => 'wlanpi-other', 'project_id' => $other->id]);
        $this->module('write', ['hostname' => 'wlanpi-running']);

        // the latest first, as on the screen; the one project, the finished ones
        Livewire::test(Cms::class)->set('projectId', $this->project->id)
            ->call('exportNetBox', 'devices')
            ->assertFileDownloaded('netbox-devices-20261024.csv', NetBoxExport::csv('devices', collect([$latest, $first])));
        Livewire::test(Cms::class)->set('projectId', $this->project->id)
            ->call('exportNetBox', 'macs')
            ->assertFileDownloaded('netbox-macs-20261024.csv', NetBoxExport::csv('macs', collect([$latest, $first])));
    }

    public function test_the_menu_counts_the_modules_and_leaves_out_the_mac_file_for_an_older_netbox()
    {
        $this->module('done', ['hostname' => 'wlanpi-aaa']);
        $this->module('write');

        Livewire::test(Cms::class)->set('projectId', 0)
            ->assertSee('Export for NetBox')->assertSee('1 finished module of this list')
            ->assertSee('3. MAC addresses (CSV)');

        NetBoxExport::save(['mac_style' => 'column'] + NetBoxExport::DEFAULTS);
        Livewire::test(Cms::class)->set('projectId', 0)->assertDontSee('3. MAC addresses (CSV)');
    }

    public function test_a_list_without_a_finished_module_says_so_and_downloads_nothing()
    {
        $this->module('write');

        Livewire::test(Cms::class)->set('projectId', 0)->call('exportNetBox', 'devices')
            ->assertNoFileDownloaded()
            ->assertSee('No finished module in this list');
    }

    public function test_a_kind_that_is_not_a_file_is_not_found()
    {
        Livewire::test(Cms::class)->call('exportNetBox', 'racks')->assertNotFound();
    }

    public function test_the_settings_are_saved_and_checked()
    {
        $this->app->instance(ServiceState::class, new FakeServiceState([]));
        $this->app->instance(WifiNetworks::class, new FakeWifiNetworks);
        $this->actingAs(User::factory()->create());
        $this->get('/settings?tab=provisioning')->assertOk()->assertSee('NetBox export')->assertSee('Device type (model)');

        Livewire::test(NetboxSettings::class)
            ->assertSet('site', 'Default')->assertSet('interface', 'eth0')->assertSet('mac_style', 'objects')
            ->set('site', 'Moscow lab')->set('status', 'staged')->call('save')
            ->assertHasNoErrors()->assertSee('Saved');
        $this->assertSame('Moscow lab', NetBoxExport::settings()['site']);
        $this->assertSame('staged', NetBoxExport::settings()['status']);

        Livewire::test(NetboxSettings::class)->assertSet('site', 'Moscow lab')
            ->set('site', '')->set('status', 'on fire')->set('mac_style', 'other')->set('role', "two\nlines")->call('save')
            ->assertHasErrors(['site', 'status', 'mac_style', 'role']);
        $this->assertSame('Moscow lab', NetBoxExport::settings()['site'], 'a refused form changes nothing');
    }

    public function test_the_defaults_come_back_with_the_button_and_a_blank_setting_means_the_default()
    {
        Setting::create(['key' => 'netbox_site', 'value' => '  ']);
        $this->assertSame('Default', NetBoxExport::settings()['site']);

        NetBoxExport::save(['site' => 'Moscow lab'] + NetBoxExport::DEFAULTS);
        Livewire::test(NetboxSettings::class)->assertSet('site', 'Moscow lab')->call('useDefaults')->assertSet('site', 'Default');
    }
}
