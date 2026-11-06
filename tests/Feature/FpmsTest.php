<?php

namespace Tests\Feature;

use App\Http\Livewire\FpmsSettings;
use App\Http\Livewire\Settings;
use App\Models\Cm;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\Fpms;
use App\Services\NetworkStatus;
use App\Services\ServiceState;
use App\Support\DisplayStation;
use App\Support\FpmsPanelSettings;
use App\Support\WallboardSnapshot;
use Tests\Support\FakeServiceState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FpmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_require_login_and_a_fixed_boolean_schema()
    {
        Livewire::test(FpmsSettings::class)->call('save')->assertForbidden();
        $service = $this->mock(Fpms::class);
        $service->shouldReceive('settings')->andReturn(Fpms::DEFAULTS);
        $service->shouldReceive('status')->andReturn(['state' => 'off']);
        $service->shouldReceive('save')->once()->with(['enabled' => true, 'open_on_start' => false]);
        Livewire::actingAs(User::factory()->create())->test(FpmsSettings::class)
            ->assertSet('enabled', false)->set('enabled', 'shell')->call('save')->assertHasErrors('enabled')
            ->set('enabled', true)->set('open_on_start', [true])->call('save')->assertHasErrors('open_on_start')
            ->set('open_on_start', false)->call('save')->assertHasNoErrors()->assertSee('Saved.');
    }

    public function test_helper_failure_is_visible_without_a_success_notice()
    {
        $service = $this->mock(Fpms::class);
        $service->shouldReceive('settings')->andReturn(Fpms::DEFAULTS);
        $service->shouldReceive('status')->andReturn(['state' => 'off']);
        $service->shouldReceive('save')->andThrow(new \RuntimeException('Could not save FPMS settings.'));
        Livewire::actingAs(User::factory()->create())->test(FpmsSettings::class)->call('save')
            ->assertHasErrors('enabled')->assertDontSee('Saved.');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(DisplayStation::CACHE_KEY);
        $units = array_filter(array_column(Settings::SERVICES, 1));
        $this->app->instance(ServiceState::class, new FakeServiceState(array_values($units)));
    }

    /* The Wi-Fi of the uplink as NetworkStatus describes it (WifiLink::parse) */
    const WIFI = ['ssid' => 'private-ssid', 'freq' => 5180, 'band' => '5 GHz', 'channel' => 36, 'width' => 80, 'signal' => -61,
        'rx' => 780.0, 'tx' => 866.7, 'generation' => '5', 'streams' => 2];

    protected function network(array $status)
    {
        $service = $this->mock(NetworkStatus::class);
        $service->shouldReceive('current')->andReturn($status + ['uplink' => null, 'backups' => [], 'modules' => null,
            'preferred_down' => null, 'spare' => null, 'links' => [], 'wireless' => []]);
    }

    public function test_station_shows_the_wifi_network_but_never_profiles_or_keys()
    {
        $service = $this->mock(NetworkStatus::class);
        $service->shouldReceive('current')->once()->with(30)->andReturn([
            'uplink' => ['name' => 'wlan1', 'kind' => 'wifi', 'addresses' => ['192.0.2.4/24'], 'wifi' => self::WIFI],
            'modules' => ['name' => 'eth0.42', 'up' => true], 'preferred_down' => 'private-profile', 'links' => [],
            'wireless' => ['wlan1'], 'spare' => null,
        ]);
        $data = DisplayStation::current();
        $this->assertSame($data, DisplayStation::current());
        $this->assertSame('192.0.2.4', $data['management']);
        $this->assertSame('eth0.42 up', $data['provisioning']);
        // the network's name is on the air anyway; NetworkManager's profiles and any key never leave the server
        $this->assertSame('private-ssid', $data['uplink']['ssid']);
        $this->assertStringNotContainsString('private-profile', json_encode($data));
        $this->assertStringNotContainsString('psk', json_encode($data));
    }

    public function test_station_uplink_tells_the_wifi_quality()
    {
        $this->network(['uplink' => ['name' => 'wlan1', 'kind' => 'wifi', 'addresses' => ['192.0.2.4/24'], 'wifi' => self::WIFI],
            'wireless' => ['wlan1']]);
        $this->assertSame(['state' => 'wifi', 'device' => 'wlan1', 'address' => '192.0.2.4', 'ssid' => 'private-ssid',
            'signal' => -61, 'quality' => 'Good', 'bars' => 3, 'band' => '5 GHz', 'generation' => '5', 'rate' => 867,
            'speed' => null, 'spare_active' => false], DisplayStation::current()['uplink']);

        Cache::forget(DisplayStation::CACHE_KEY);
        $this->network(['uplink' => ['name' => 'eth1', 'kind' => 'ethernet', 'addresses' => ['198.51.100.7/24'], 'speed' => 1000],
            'wireless' => ['wlan0']]);
        $uplink = DisplayStation::current()['uplink'];
        $this->assertSame(['wired', 'eth1', '198.51.100.7', 1000], [$uplink['state'], $uplink['device'], $uplink['address'], $uplink['speed']]);

        Cache::forget(DisplayStation::CACHE_KEY);
        $this->network(['wireless' => ['wlan0'], 'spare' => ['state' => 'active']]);
        $uplink = DisplayStation::current()['uplink'];
        $this->assertSame(['wifi_down', 'wlan0', true], [$uplink['state'], $uplink['device'], $uplink['spare_active']]);

        Cache::forget(DisplayStation::CACHE_KEY);
        $this->network([]);
        $this->assertSame('none', DisplayStation::current()['uplink']['state']);
    }

    /* iw did not answer (its 3 s timeout, a missing binary): the Wi-Fi still carries the default route, it is not "down" */
    public function test_a_wifi_uplink_iw_could_not_read_is_not_an_alarm()
    {
        $this->network(['uplink' => ['name' => 'wlan1', 'kind' => 'wifi', 'addresses' => ['192.0.2.4/24'], 'wifi' => null],
            'wireless' => ['wlan1']]);
        $uplink = DisplayStation::current()['uplink'];
        $this->assertSame(['wifi', 'wlan1', '192.0.2.4', null, null], [$uplink['state'], $uplink['device'], $uplink['address'],
            $uplink['signal'], $uplink['quality']]);
    }

    public function test_station_counts_services_and_names_the_stopped_ones()
    {
        $units = array_values(array_filter(array_column(Settings::SERVICES, 1), fn ($u) => $u && $u !== 'cmprovision-dnsmasq'));
        $this->app->instance(ServiceState::class, new FakeServiceState($units));
        $this->network([]);

        // the web interface has no unit of its own: it is not counted
        $this->assertSame(['up' => 5, 'total' => 6, 'stopped' => ['dnsmasq'], 'disabled' => []], DisplayStation::current()['services']);
    }

    public function test_station_separates_disabled_boot_services_from_a_stopped_worker()
    {
        $this->app->instance(ServiceState::class, new FakeServiceState(
            ['cmprovision-usb-diagnostics', 'cmprovision-queue-images', 'cmprovision-uplink.timer'],
            ['cmprovision-dnsmasq', 'cmprovision-rpiboot', 'cmprovision-queue']
        ));
        $this->network([]);

        $this->assertSame(['up' => 3, 'total' => 6, 'stopped' => ['queue'], 'disabled' => ['dnsmasq', 'rpiboot']],
            DisplayStation::current()['services']);
    }

    public function test_station_lists_the_links_with_their_roles()
    {
        $this->network(['links' => [
            ['name' => 'eth0', 'kind' => 'ethernet', 'role' => 'stand', 'up' => true, 'speed' => 1000, 'addresses' => ['172.20.0.1/16']],
            ['name' => 'eth1', 'kind' => 'ethernet', 'role' => 'other', 'up' => true, 'speed' => 100, 'addresses' => []],
            ['name' => 'wlan0', 'kind' => 'wifi', 'role' => 'backup', 'up' => true, 'addresses' => ['192.0.2.9/24'], 'wifi' => self::WIFI],
        ]]);
        $this->assertSame([
            ['name' => 'eth0', 'kind' => 'ethernet', 'role' => 'stand', 'up' => true, 'speed' => 1000, 'address' => '172.20.0.1',
                'ssid' => null, 'signal' => null],
            ['name' => 'eth1', 'kind' => 'ethernet', 'role' => 'other', 'up' => true, 'speed' => 100, 'address' => null,
                'ssid' => null, 'signal' => null],
            // a Wi-Fi backup link tells its network and signal, as the uplink does
            ['name' => 'wlan0', 'kind' => 'wifi', 'role' => 'backup', 'up' => true, 'speed' => null, 'address' => '192.0.2.9',
                'ssid' => 'private-ssid', 'signal' => -61],
        ], DisplayStation::current()['links']);
    }

    public function test_panel_settings_are_saved_without_the_helper_and_reach_the_snapshot()
    {
        $service = $this->mock(Fpms::class);
        $service->shouldReceive('settings')->andReturn(Fpms::DEFAULTS);
        $service->shouldReceive('status')->andReturn(['state' => 'off']);
        $service->shouldReceive('save')->andThrow(new \RuntimeException('Could not save FPMS settings.'));
        $this->assertSame(['theme' => 'new', 'ports_map' => 'pages', 'color_mode' => 'dark'], FpmsPanelSettings::get());

        Livewire::actingAs(User::factory()->create())->test(FpmsSettings::class)
            ->assertSet('theme', 'new')->assertSet('ports_map', 'pages')
            ->set('theme', 'x')->call('save')->assertHasErrors('theme')
            ->set('theme', 'new')->set('color_mode', 'invalid')->call('save')->assertHasErrors('color_mode')
            ->set('theme', 'classic')->set('ports_map', 'whole')->set('color_mode', 'auto')->call('save')->assertHasErrors('enabled');

        // the root helper failed, the panel's look is the server's own setting and is kept
        $this->assertSame(['theme' => 'classic', 'ports_map' => 'whole', 'color_mode' => 'auto'], FpmsPanelSettings::get());
        $this->assertSame(['theme' => 'classic', 'ports_map' => 'whole', 'color_mode' => 'auto'], app(WallboardSnapshot::class)->current()['panel']);

        Setting::updateOrCreate(['key' => 'fpms_theme'], ['value' => 'rainbow']);
        $this->assertSame('new', FpmsPanelSettings::get()['theme']);
        Setting::updateOrCreate(['key' => 'fpms_color_mode'], ['value' => 'invalid']);
        $this->assertSame('dark', FpmsPanelSettings::get()['color_mode']);
    }

    public function test_modules_carry_step_and_short_cause()
    {
        $this->mock(Fpms::class)->shouldReceive('settings')->andReturn(Fpms::DEFAULTS);
        Cm::create(['serial' => '10000000aaaa0c18', 'mac' => 'e4:5f:01:aa:0c:18', 'phase' => 'failed', 'polled_at' => now(),
            'phase_detail' => 'Error during dd. Return code 1. Diagnosis: the connection to the provisioning server was closed '
                .'before the download finished (server timeout, network problem or server restart). Codes: curl 18.',
            'timeline' => [['phase' => 'write', 'detail' => null, 'at' => now()->toIso8601String()],
                ['phase' => 'failed', 'detail' => null, 'at' => now()->toIso8601String()]]]);

        $module = app(WallboardSnapshot::class)->current()['modules'][0];

        $this->assertSame('Writing image', $module['step']);
        $this->assertSame("Download cut (curl\u{00A0}18)", $module['cause']);
        $this->assertSame('curl 18, exit 1', $module['code']);
        $this->assertStringStartsWith('The connection to the provisioning server', $module['reason']);
        $this->assertStringStartsWith('The partition table was cleared', $module['advice']);
    }

    public function test_the_batch_carries_its_eta()
    {
        $this->mock(Fpms::class)->shouldReceive('settings')->andReturn(Fpms::DEFAULTS);
        $image = new Image;
        $image->filename = 'os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1048576;
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        $batch = NotificationBatch::create(['project_id' => $project->id, 'started_at' => now()->subMinutes(10), 'last_event_at' => now()]);
        Cm::create(['serial' => '10000000a1f3c201', 'mac' => 'e4:5f:01:a1:c2:01', 'phase' => 'write', 'notification_batch_id' => $batch->id,
            'project_id' => $project->id, 'provisioning_started_at' => now()->subMinutes(5), 'phase_started_at' => now()->subSeconds(100),
            'progress_bytes' => 300, 'progress_total' => 900, 'progress_updated_at' => now()]);

        $this->assertSame(200, app(WallboardSnapshot::class)->current()['batch']['left_seconds']);
    }

    public function test_the_station_block_is_made_for_the_panel_only_when_fpms_is_on()
    {
        Cache::forget(DisplayStation::CACHE_KEY);
        $fpms = $this->mock(Fpms::class);
        $fpms->shouldReceive('settings')->andReturn(Fpms::DEFAULTS);
        $network = $this->mock(NetworkStatus::class);
        $network->shouldNotReceive('current');    // HDMI alone never asks NetworkManager and iw for anything
        $this->assertNull(app(WallboardSnapshot::class)->current()['station']);

        Cache::forget(DisplayStation::CACHE_KEY);
        $fpms = $this->mock(Fpms::class);
        $fpms->shouldReceive('settings')->andReturn(['enabled' => true, 'open_on_start' => false]);
        $network = $this->mock(NetworkStatus::class);
        $network->shouldReceive('current')->once()->andReturn(['uplink' => ['addresses' => ['192.0.2.4/24']], 'modules' => null]);
        $station = app(WallboardSnapshot::class)->current()['station'];
        $this->assertSame('192.0.2.4', $station['management']);
        $this->assertNull($station['provisioning']);
    }
}
