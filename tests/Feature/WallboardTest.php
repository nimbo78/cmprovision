<?php

namespace Tests\Feature;

use App\Http\Livewire\WallboardSettings;
use App\Models\Cm;
use App\Models\User;
use App\Models\UsbDiagnosticReport;
use App\Services\Switch\MacTable;
use App\Services\Switch\SwitchSnapshot;
use App\Services\Wallboard;
use App\Support\ModuleDiagnostics;
use App\Support\WallboardSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PoeStand;
use Tests\TestCase;

class WallboardTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::forget(\App\Support\DisplayStation::CACHE_KEY);
        $this->app->instance(\App\Services\ServiceState::class,
            new \Tests\Support\FakeServiceState(array_values(array_filter(array_column(\App\Http\Livewire\Settings::SERVICES, 1)))));
    }

    public function test_rescue_network_is_available_to_hdmi_even_when_fpms_is_disabled(): void
    {
        $this->stand();
        $wifi = new \Tests\Support\FakeRescueWifi;
        $wifi->state = array_merge($wifi->state, ['enabled' => true, 'active' => true, 'state' => 'active', 'clients' => 1]);
        $this->app->instance(\App\Services\RescueWifi::class, $wifi);
        $snapshot = app(WallboardSnapshot::class)->current();
        $this->assertIsArray($snapshot['station'], 'HDMI connections are independent of FPMS enablement');
        $this->assertSame('192.168.240.1', $snapshot['rescue_wifi']['address']);
        $this->assertSame(1, $snapshot['rescue_wifi']['clients']);
        $this->assertArrayNotHasKey('password', $snapshot['rescue_wifi']);
    }

    public function test_snapshot_uses_the_ports_map_and_shared_poll_without_exposing_secrets()
    {
        $cm = $this->stand();
        $cm->update(['diagnostics_token' => 'private-run-token']);
        // A disconnected historical run must not inflate ON STAND.
        Cm::create(['serial' => '10000000000000ff', 'mac' => 'e4:5f:01:00:00:ff', 'phase' => 'done']);
        $snapshot = app(WallboardSnapshot::class)->current();
        $calls = count($this->fake->walks);
        app(WallboardSnapshot::class)->current();
        $this->assertCount($calls, $this->fake->walks, 'the display reuses the Ports cache');
        $this->assertSame([$cm->serial], array_column($snapshot['modules'], 'serial'));
        $this->assertSame(1, $snapshot['counts']['failed']);
        $this->assertSame('sw01 GE0/0/3', $snapshot['modules'][0]['port']);
        $port = collect($snapshot['switches'][0]['blocks'])->pluck('ports')->flatten(1)->firstWhere('name', 'GE0/0/3');
        $this->assertNotEmpty($port['port_id']);
        $this->assertSame($port['port_id'], $snapshot['modules'][0]['port_id']);
        $this->assertContains($cm->mac, $port['info']['macs']);
        $this->assertArrayNotHasKey('media', $port['info'], 'The fallback front panel layout does not identify a physical connector.');
        $this->assertStringNotContainsString('private-run-token', json_encode($snapshot));
        $this->assertArrayNotHasKey('config', $snapshot['switches'][0]);
        $this->assertArrayNotHasKey('storage_diagnostics', $snapshot['modules'][0]);
        $this->assertSame('failed', $cm->fresh()->phase, 'a snapshot does not restart or alter a run');
    }

    public function test_each_switch_keeps_its_own_chassis_serial_after_a_poll_times_out()
    {
        $this->stand();
        $this->secondSwitch();
        $this->fake->tables[''] += [SwitchSnapshot::OID_ENT_CLASS.'.1' => 3, SwitchSnapshot::OID_ENT_SERIAL.'.1' => 'SW01-0123'];
        $this->fake2->tables[''] += [SwitchSnapshot::OID_ENT_CLASS.'.1' => 3, SwitchSnapshot::OID_ENT_SERIAL.'.1' => 'SW02-9876'];

        $reader = app(WallboardSnapshot::class);
        $this->assertSame(['sw01' => 'SW01-0123', 'sw02' => 'SW02-9876'], array_column($reader->current()['switches'], 'serial', 'name'));

        $this->travel(20)->seconds();
        $this->fake2->timeout = true;
        $switches = array_column($reader->current()['switches'], null, 'name');
        $this->assertFalse($switches['sw02']['answering']);
        $this->assertSame('SW02-9876', $switches['sw02']['serial']);
        $this->assertSame('192.0.2.11', $switches['sw02']['host'] ?? null);
        $this->assertNotEmpty($switches['sw02']['blocks'], 'The cached ports remain available for diagnostics.');
        $this->assertTrue($switches['sw01']['answering']);
    }

    public function test_an_unreachable_switch_without_a_snapshot_stays_listed_beside_the_live_switch()
    {
        $this->stand();
        $this->secondSwitch();
        $this->fake2->timeout = true;
        $switches = array_column(app(WallboardSnapshot::class)->current()['switches'], null, 'host');
        $this->assertSame(['192.0.2.10', '192.0.2.11'], array_keys($switches));
        $this->assertTrue($switches['192.0.2.10']['answering']);
        $offline = $switches['192.0.2.11'];
        $this->assertFalse($offline['answering']);
        $this->assertSame('192.0.2.11', $offline['name']);
        $this->assertNull($offline['serial']);
        $this->assertSame([], $offline['blocks']);
    }

    public function test_card_uses_the_project_and_image_of_this_specific_run()
    {
        $cm = $this->stand();
        $image = new \App\Models\Image;
        $image->forceFill(['filename' => 'stock.img.gz', 'filename_extension' => 'gz', 'filename_on_server' => 'file.gz',
            'sha256' => str_repeat('a', 64), 'uncompressed_size' => 1024])->save();
        $attrs = ['device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false];
        $original = \App\Models\Project::create(['name' => 'Friday batch'] + $attrs);
        $active = \App\Models\Project::create(['name' => 'Monday batch'] + $attrs);
        \App\Models\Setting::updateOrCreate(['key' => 'active_project'], ['value' => $active->id]);
        $cm->forceFill(['project_id' => $original->id, 'image_filename' => 'friday.img.gz'])->save();
        $data = app(WallboardSnapshot::class)->current();
        $this->assertSame('Monday batch', $data['project']);
        $this->assertSame('Friday batch', $data['modules'][0]['project']);
        $this->assertSame('friday.img.gz', $data['modules'][0]['image']);
        $this->assertSame($cm->provisioning_started_at->timestamp, $data['modules'][0]['started_at']);
        $this->assertSame($cm->phase_started_at->local()->format('Y-m-d H:i T'), $data['modules'][0]['finished']);
    }

    public function test_multiple_modules_share_the_endpoint_label_in_severity_order()
    {
        $ready = $this->stand();
        $ready->update(['hostname' => 'sensor-ready', 'phase' => 'done', 'polled_at' => now()]);
        $failed = $this->secondModule();
        $failed->update(['hostname' => 'sensor-failed', 'polled_at' => now()]);
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.212.10.0'] = 8;

        $switch = app(WallboardSnapshot::class)->current()['switches'][0];
        $ports = array_column(array_merge(...array_column($switch['blocks'], 'ports')), null, 'name');
        $this->assertSame('fail', $ports['GE0/0/3']['filter']);
        $this->assertSame([$failed->serial, $ready->serial], $ports['GE0/0/3']['serials']);
        $this->assertSame('sensor-failed +1', $ports['GE0/0/3']['endpoint'] ?? null);
    }

    public function test_a_discovered_identity_follows_the_current_device_even_before_it_registers()
    {
        $this->stand();
        $table =& $this->fake->tables[''];
        $table[MacTable::OID_HUAWEI_FDB_PORT.'.16.233.146.18.52.86.10.0'] = 6;
        foreach ([4 => 4, 5 => hex2bin('000a95ddeeff'), 9 => 'previous-device'] as $column => $value)
            $table[\App\Services\Switch\Neighbors::OID_LLDP_REM.'.'.$column.'.0.6.1'] = $value;

        $table[SwitchSnapshot::OID_SYSUPTIME.'.0'] = 100000;
        $table[SwitchSnapshot::OID_IFLASTCHANGE.'.7'] = 99000;
        $table[MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.1.2.3.10.0'] = 7;

        $switch = app(WallboardSnapshot::class)->current()['switches'][0];
        $ports = array_column(array_merge(...array_column($switch['blocks'], 'ports')), null, 'name');
        $this->assertSame('boot', $ports['GE0/0/2']['state']);
        $this->assertSame('10:e9:92:12:34:56', $ports['GE0/0/1']['endpoint'] ?? null);
        $this->assertEmpty($ports['GE0/0/1']['info']['neighbors'] ?? []);
        $this->assertStringNotContainsString('previous-device', json_encode(app(WallboardSnapshot::class)->current()));
        $this->assertSame('e4:5f:01:01:02:03', $ports['GE0/0/2']['endpoint'] ?? null);
    }

    public function test_an_uplink_names_its_peer_switch_and_never_a_module_seen_through_it()
    {
        $this->stand();
        $this->secondSwitch();
        $this->fake2->tables[''][SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0'] = hex2bin('0057d297a180');
        $table =& $this->fake->tables[''];
        $table[MacTable::OID_HUAWEI_FDB_PORT.'.0.87.210.151.161.128.1.0'] = 6;
        $table[MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.213.10.0'] = 6;
        // Four unrelated addresses make port 2 an uplink with no identified peer.
        foreach ([1, 2, 3, 4] as $end)
            $table[MacTable::OID_HUAWEI_FDB_PORT.'.0.10.149.0.0.'.$end.'.10.0'] = 7;

        $switch = app(WallboardSnapshot::class)->current()['switches'][0];
        $ports = array_merge(...array_column($switch['blocks'], 'ports'));
        $this->assertSame(['GE0/0/1' => 'sw02', 'GE0/0/2' => null, 'GE0/0/3' => 'e4:5f:01:62:9f:d3', 'XGE0/0/1' => gethostname()],
            array_column($ports, 'endpoint', 'name'));
    }

    public function test_neighbor_without_an_advertised_mac_does_not_inherit_the_learned_endpoint_mac()
    {
        $this->stand();
        $table =& $this->fake->tables[''];
        $table[MacTable::OID_HUAWEI_FDB_PORT.'.16.233.146.18.52.86.10.0'] = 6;
        foreach ([4 => 7, 5 => 'desk-phone', 9 => 'phone'] as $column => $value)
            $table[\App\Services\Switch\Neighbors::OID_LLDP_REM.'.'.$column.'.0.6.1'] = $value;
        $snapshot = app(WallboardSnapshot::class)->current();
        $device = collect($snapshot['unknown'])->firstWhere('name', 'phone');
        $this->assertNotNull($device);
        $this->assertNull($device['mac']);
        $port = collect($snapshot['switches'][0]['blocks'])->pluck('ports')->flatten(1)->firstWhere('port_id', $device['port_id']);
        $this->assertContains('10:e9:92:12:34:56', $port['info']['macs']);
    }

    public function test_the_port_with_the_provisioner_is_marked_for_the_display()
    {
        $this->stand();
        $ports = [];
        foreach (app(WallboardSnapshot::class)->current()['switches'][0]['blocks'] as $block)
            foreach ($block['ports'] as $port)
                $ports[$port['name']] = $port;
        $this->assertSame('uplink', $ports['XGE0/0/1']['state']);
        $this->assertTrue($ports['XGE0/0/1']['server'], 'the provisioner\'s MAC is on this port');
        foreach (['GE0/0/1', 'GE0/0/2', 'GE0/0/3'] as $name)
            $this->assertFalse($ports[$name]['server'], $name);
    }

    public function test_display_reports_physical_link_and_poe_independently()
    {
        $this->stand();
        $table =& $this->fake->tables[''];
        $table[SwitchSnapshot::OID_IFOPERSTATUS.'.8'] = 2; // Still delivering 6.9 W.
        $table[SwitchSnapshot::OID_IFOPERSTATUS.'.6'] = 4; // Agent reports unknown.
        unset($table[SwitchSnapshot::OID_IFOPERSTATUS.'.7']);

        $switch = app(WallboardSnapshot::class)->current()['switches'][0];
        $ports = array_column(array_merge(...array_column($switch['blocks'], 'ports')), null, 'name');
        $this->assertFalse($ports['GE0/0/3']['link_up']);
        $this->assertSame('delivering', $ports['GE0/0/3']['poe_state']);
        $this->assertEquals(6.9, $ports['GE0/0/3']['watts']);
        $this->assertNull($ports['GE0/0/1']['link_up']);
        $this->assertNull($ports['GE0/0/2']['link_up']);
        $this->assertSame('searching', $ports['GE0/0/2']['poe_state']);
        $this->assertTrue($ports['XGE0/0/1']['link_up']);
        $this->assertNull($ports['XGE0/0/1']['poe_state']);
        $this->assertSame([null, null, null, gethostname()], array_column($ports, 'endpoint'),
            'A port with only power, an unknown link or a stale module MAC has no endpoint');
    }

    public function test_a_device_on_a_port_that_is_not_a_module_is_listed_for_the_display_with_what_it_says_or_its_maker()
    {
        $this->stand();
        config(['cmprovision.ieee_data_dir' => dirname(__DIR__).'/fixtures/ieee']);
        $t =& $this->fake->tables[''];
        // GE0/0/1 (6): an access point that talks LLDP; GE0/0/2 (7): a device that says nothing, from the registry's INGRAM MICRO SERVICES
        $t[MacTable::OID_HUAWEI_FDB_PORT.'.0.10.149.221.238.255.10.0'] = 6;       // 00:0a:95:dd:ee:ff
        $t[MacTable::OID_HUAWEI_FDB_PORT.'.16.233.146.18.52.86.10.0'] = 7;         // 10:e9:92:12:34:56
        foreach ([4 => 4, 5 => hex2bin('000a95ddeeff'), 8 => 'eth0', 9 => "ap-lobby\x1b[31m", 10 => 'Cisco AP firmware', 12 => hex2bin('10')] as $column => $value)
            $t[\App\Services\Switch\Neighbors::OID_LLDP_REM.'.'.$column.'.0.6.1'] = $value;
        $t[\App\Services\Switch\Neighbors::OID_LLDP_MAN.'.0.6.1.1.4.192.168.1.5'] = 2;

        $snapshot = app(WallboardSnapshot::class)->current();
        $unknown = $snapshot['unknown'];
        $ports = array_merge(...array_column($snapshot['switches'][0]['blocks'], 'ports'));
        $this->assertSame(['ap-lobby [31m', '10:e9:92:12:34:56'], array_slice(array_column($ports, 'endpoint'), 0, 2));
        $this->assertSame(['ap-lobby [31m', 'INGRAM MICRO SERVICES'], array_column($unknown, 'name'), 'the name is cleaned: no control character');
        $this->assertSame(['unrecognized', 'unrecognized'], array_column($unknown, 'state'));
        $this->assertSame('Cisco AP firmware / 192.168.1.5 / wlan-ap', $unknown[0]['detail']);
        $this->assertTrue($unknown[0]['says']);
        $this->assertSame($ports[0]['port_id'], $unknown[0]['port_id']);
        $neighbor = $ports[0]['info']['neighbors'][0];
        $this->assertSame('eth0', $neighbor['port']);
        $this->assertSame('192.168.1.5', $neighbor['address']);
        $this->assertSame('Cisco AP firmware', $neighbor['description']);
        $this->assertSame('LLDP', strtoupper($neighbor['protocol']));
        $this->assertSame('10:e9:92:12:34:56', $unknown[1]['detail'], 'no neighbour: the MAC beside the maker');
        $this->assertFalse($unknown[1]['says']);
        $this->assertStringNotContainsString('sw01 GE0/0/3', json_encode($unknown), 'the module on its port is not in the list');
    }

    /* the stand's provisioner, seen from a second station: it names itself a cmprovision station over LLDP and says
       172.20.0.1, so it is the provisioner, not a stranger */
    public function test_another_cmprovision_station_is_a_station_on_its_port_not_an_unknown_device()
    {
        $this->stand();
        $t =& $this->fake->tables[''];
        $t[MacTable::OID_HUAWEI_FDB_PORT.'.44.207.103.48.125.181.10.0'] = 6;      // 2c:cf:67:30:7d:b5
        foreach ([4 => 4, 5 => hex2bin('2ccf67307db5'), 8 => 'eth0', 9 => 'wlanpi-da2', 10 => 'cmprovision 2.23.0 station; Debian GNU/Linux 13 (trixie)',
                  12 => hex2bin('10')] as $column => $value)
            $t[\App\Services\Switch\Neighbors::OID_LLDP_REM.'.'.$column.'.0.6.1'] = $value;
        $t[\App\Services\Switch\Neighbors::OID_LLDP_MAN.'.0.6.1.1.4.172.20.0.1'] = 2;

        $data = app(WallboardSnapshot::class)->current();
        $ports = array_column(array_merge(...array_column($data['switches'][0]['blocks'], 'ports')), null, 'name');
        $this->assertSame(['name' => 'wlanpi-da2', 'address' => '172.20.0.1', 'version' => '2.23.0', 'provisioner' => true], $ports['GE0/0/1']['station']);
        $this->assertNull($ports['GE0/0/2']['station']);
        $this->assertSame('wlanpi-da2', $ports['GE0/0/1']['endpoint'] ?? null);
        $this->assertStringNotContainsString('wlanpi-da2', json_encode($data['unknown']));
    }

    protected function markSensorOnFirstPort(): string
    {
        $key = str_repeat('5a', 32);
        app(\App\Services\Sensor\ModuleMark::class)->set($key);
        $mac = '00:0a:95:dd:ee:ff';
        $code = \App\Services\Sensor\ModuleMark::code(hash_hmac('sha256', $mac, hex2bin($key)), 'wlanpi-da2', 1, 20);
        $t =& $this->fake->tables[''];
        $t[MacTable::OID_HUAWEI_FDB_PORT.'.0.10.149.221.238.255.10.0'] = 6;
        foreach ([4 => 4, 5 => hex2bin('000a95ddeeff'), 6 => 3, 7 => hex2bin('000a95ddeeff'), 8 => "cmprovision mark wlanpi-da2 1/20 $code", 9 => 'wlanpi-fd3', 10 => 'Linux', 12 => hex2bin('10')] as $column => $value)
            $t[\App\Services\Switch\Neighbors::OID_LLDP_REM.'.'.$column.'.0.6.1'] = $value;
        return $code;
    }

    public function test_verified_sensors_are_separate_from_local_runs_and_unknown_devices()
    {
        $cm = $this->stand();
        $code = $this->markSensorOnFirstPort();
        $data = app(WallboardSnapshot::class)->current();
        $ports = array_column(array_merge(...array_column($data['switches'][0]['blocks'], 'ports')), null, 'name');
        $this->assertSame(['recognized', 'recognized', []], [$ports['GE0/0/1']['state'], $ports['GE0/0/1']['filter'], $ports['GE0/0/1']['serials']]);
        $this->assertSame([$cm->serial], array_column($data['modules'], 'serial'));
        $this->assertSame(['wlanpi-fd3'], array_column($data['recognized'], 'name'));
        $this->assertSame('wlanpi-fd3', $ports['GE0/0/1']['endpoint'] ?? null);
        $this->assertSame('Flashed by wlanpi-da2 / boot 1 of 20', $data['recognized'][0]['detail']);
        $this->assertTrue($data['recognized'][0]['flashed_by']['verified']);
        $this->assertStringNotContainsString('wlanpi-fd3', json_encode($data['unknown']));
        $this->assertStringNotContainsString($code, json_encode($data));
        $this->assertSame(1, $data['counts']['failed']);

        app(\App\Services\Sensor\ModuleMark::class)->set(str_repeat('00', 32));
        \Illuminate\Support\Facades\Cache::flush();
        $untrusted = app(WallboardSnapshot::class)->current();
        $this->assertSame([], $untrusted['recognized']);
        $this->assertStringNotContainsString($code, json_encode($untrusted));
        $this->assertContains('wlanpi-fd3', array_column($untrusted['unknown'], 'name'));
        $ports = array_column(array_merge(...array_column($untrusted['switches'][0]['blocks'], 'ports')), null, 'name');
        $this->assertSame('unrecognized', $ports['GE0/0/1']['state']);
    }

    public function test_neighbor_cards_follow_each_switch_outage_and_recovery_without_erasing_port_or_module_data()
    {
        $cm = $this->stand();
        $this->secondSwitch();
        $this->markSensorOnFirstPort();
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.0.17.34.51.68.85.10.0'] = 7;
        $this->fake2->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.0.17.34.51.68.86.10.0'] = 6;

        $reader = app(WallboardSnapshot::class);
        $live = $reader->current();
        $this->assertSame(['wlanpi-fd3'], array_column($live['recognized'], 'name'));
        $this->assertSame(['sw01 GE0/0/2', 'sw02 GE0/0/1'], array_column($live['unknown'], 'port'));

        $this->travel(31)->seconds();
        $this->fake->timeout = true;
        UsbDiagnosticReport::create(['serial' => '1000000000000003', 'boot_id' => 'boot', 'event_id' => 'event', 'mac' => null,
            'usb_path' => '/dev/ttyACM0', 'report' => [], 'received_at' => now()]);
        $silent = $reader->current();
        $this->assertSame([], $silent['recognized']);
        $this->assertSame(['sw02 GE0/0/1'], array_column($silent['unknown'], 'port'));
        $cached = array_merge(...array_column($silent['switches'][0]['blocks'], 'ports'));
        $this->assertContains('wlanpi-fd3', array_column($cached, 'endpoint'));
        $this->assertContains($cm->serial, array_column($silent['modules'], 'serial'));
        $this->assertContains('1000000000000003', array_column($silent['modules'], 'serial'));

        $this->travel(31)->seconds();
        $this->fake->timeout = false;
        $back = $reader->current();
        $this->assertSame(array_column($live['recognized'], 'name'), array_column($back['recognized'], 'name'));
        $this->assertSame(array_column($live['unknown'], 'port'), array_column($back['unknown'], 'port'));
    }

    public function test_a_display_takes_a_snapshot_up_to_fifteen_seconds_old_and_polls_after_that()
    {
        $this->stand();
        app(WallboardSnapshot::class)->current();
        $first = count($this->fake->walks);
        $this->assertGreaterThan(0, $first);
        $this->travel(10)->seconds();   // the Ports page would ask the switch again after 5
        app(WallboardSnapshot::class)->current();
        $this->assertCount($first, $this->fake->walks, 'ten seconds old is good enough for a display');
        $this->travel(10)->seconds();
        app(WallboardSnapshot::class)->current();
        $this->assertGreaterThan($first, count($this->fake->walks), 'past the window the display asks the switch itself');
    }

    public function test_switches_that_never_answered_do_not_empty_the_board()
    {
        $this->freezeTime(); // The snapshot and assertion describe the same event, even across a wall-clock second.
        $cm = $this->stand();
        $cm->update(['polled_at' => now()]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->fake->timeout = true;
        $data = app(WallboardSnapshot::class)->current();
        $this->assertSame([$cm->serial], array_column($data['modules'], 'serial'));
        $this->assertFalse($data['switches'][0]['answering']);
        $this->assertSame('192.0.2.10', $data['switches'][0]['host'] ?? null);
        $this->assertArrayHasKey('serial', $data['switches'][0]);
        $this->assertNull($data['switches'][0]['serial']);
        // since when it has not answered: the front panel says "silent 2 min"
        $this->assertSame(now()->getTimestamp(), $data['switches'][0]['silent_since']);
    }

    public function test_without_switches_only_current_reports_or_recent_runs_are_displayed()
    {
        $old = Cm::create(['serial' => '1000000000000001', 'mac' => 'e4:5f:01:00:00:01', 'phase' => 'write', 'provisioning_started_at' => now()->subDays(2)]);
        $current = Cm::create(['serial' => '1000000000000002', 'mac' => 'e4:5f:01:00:00:02', 'phase' => 'done', 'polled_at' => now()]);
        $data = app(WallboardSnapshot::class)->current();
        $this->assertSame([$current->serial], array_column($data['modules'], 'serial'));
        $this->assertSame([], $data['switches']);
        $this->assertSame('write', $old->fresh()->phase);
    }

    public function test_a_module_is_named_by_its_host_name_or_its_mac_and_never_by_its_serial()
    {
        Cm::create(['serial' => '1000000000000001', 'mac' => 'e4:5f:01:00:00:01', 'phase' => 'done', 'polled_at' => now()]);
        Cm::create(['serial' => '1000000000000002', 'mac' => 'e4:5f:01:00:00:02', 'hostname' => 'wlanpi-sensor2', 'phase' => 'done', 'polled_at' => now()]);

        $modules = collect(app(WallboardSnapshot::class)->current()['modules'])->keyBy('serial');

        $this->assertSame(['e4:5f:01:00:00:01', 'e4:5f:01:00:00:01', null], [$modules['1000000000000001']['name'], $modules['1000000000000001']['mac'], $modules['1000000000000001']['hostname']]);
        $this->assertSame(['wlanpi-sensor2', 'e4:5f:01:00:00:02', 'wlanpi-sensor2'], [$modules['1000000000000002']['name'], $modules['1000000000000002']['mac'], $modules['1000000000000002']['hostname']]);
    }

    public function test_an_unregistered_usb_module_is_named_by_the_mac_it_reported()
    {
        $report = ModuleDiagnostics::parse("CMPROVISION_DIAGNOSTICS_V1\ncontext=discovery\nserial=1000000000000003\ntarget=/dev/mmcblk0\nblock=missing\ncontroller=present\nthrottled=0x0\n");
        UsbDiagnosticReport::create(['serial' => '1000000000000003', 'boot_id' => 'boot', 'event_id' => 'event', 'mac' => 'e4:5f:01:00:00:03',
            'usb_path' => '/dev/ttyACM0', 'report' => $report, 'received_at' => now()->subSeconds(18)]);
        UsbDiagnosticReport::create(['serial' => '1000000000000004', 'boot_id' => 'boot', 'event_id' => 'event', 'mac' => null,
            'usb_path' => '/dev/ttyACM1', 'report' => $report, 'received_at' => now()->subSeconds(20)]);

        $modules = collect(app(WallboardSnapshot::class)->current()['modules'])->keyBy('serial');

        $this->assertSame('e4:5f:01:00:00:03', $modules['1000000000000003']['name']);
        $this->assertSame('Unregistered module', $modules['1000000000000004']['name']);
    }

    public function test_the_command_keeps_an_atomic_world_readable_file_for_the_display_user()
    {
        $directory = sys_get_temp_dir().'/wallboard-'.uniqid();
        mkdir($directory);
        try {
            $file = $directory.'/snapshot.json';
            $this->artisan('wallboard:snapshot', ['--output' => $file])->assertSuccessful();
            $data = json_decode(file_get_contents($file), true);
            $this->assertSame(1, $data['version']);
            $this->assertIsArray($data['modules']);
            $this->assertSame(0644, fileperms($file) & 0777, 'the display runs as another user and only reads it');
            $this->assertSame([basename($file)], array_values(array_diff(scandir($directory), ['.', '..'])), 'no temporary file is left behind');
            $this->artisan('wallboard:snapshot', ['--output' => 'relative.json'])->assertFailed();
            $this->artisan('wallboard:snapshot', ['--output' => $directory.'/missing/snapshot.json'])->assertFailed();
        } finally {
            array_map('unlink', glob($directory.'/{,.}*[!.]*', GLOB_BRACE));
            rmdir($directory);
        }
    }

    public function test_local_command_outputs_json_and_no_network_route_is_added()
    {
        $this->artisan('wallboard:snapshot')->assertSuccessful();
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->implode('\n');
        $this->assertStringNotContainsString('wallboard', $routes);
    }

    public function test_usb_only_observation_is_separate_and_diagnostics_remain_a_dated_bounded_snapshot()
    {
        $report = ModuleDiagnostics::parse("CMPROVISION_DIAGNOSTICS_V1\ncontext=discovery\nserial=1000000000000003\ntarget=/dev/mmcblk0\nblock=missing\ncontroller=present\nthrottled=0x0\n[kernel]\nprivate raw line\n");
        $report['source'] = 'usb';
        UsbDiagnosticReport::create(['serial' => '1000000000000003', 'boot_id' => 'boot', 'event_id' => 'event',
            'usb_path' => '/dev/ttyACM0', 'report' => $report, 'received_at' => now()->subSeconds(18)]);
        $data = app(WallboardSnapshot::class)->current();
        $module = $data['modules'][0];
        $this->assertFalse($module['registered']);
        $this->assertSame(0, $data['counts']['failed']);
        $this->assertSame(1, $data['counts']['unknown']);
        $this->assertSame('USB', $module['diagnostics']['source']);
        $this->assertSame(18, $data['generated_at'] - $module['diagnostics']['received_at']);
        $this->assertStringNotContainsString('private raw line', json_encode($module));
        $this->assertSame(0, Cm::count(), 'an observation does not register or provision a module');
    }

    public function test_snapshot_resolves_auto_for_both_displays_and_falls_back_safely()
    {
        config(['app.display_timezone' => 'Europe/Moscow']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-12T09:00:00Z'));
        $this->assertSame('light', app(WallboardSnapshot::class)->current()['display_auto_mode']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-12T15:00:00Z'));
        $this->assertSame('dark', app(WallboardSnapshot::class)->current()['display_auto_mode']);
        config(['app.display_timezone' => 'UTC']);
        $this->assertSame('dark', app(WallboardSnapshot::class)->current()['display_auto_mode']);
    }

    public function test_running_publisher_reads_a_changed_zone_and_updates_both_clock_and_auto_mode()
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-10T06:00:00Z'));
        $zone = $this->mock(\App\Support\SystemTimezone::class);
        $zone->shouldReceive('current')->twice()->andReturn('Pacific/Honolulu', 'Asia/Tokyo');
        $publisher = app(\App\Console\Commands\Wallboard::class);
        $snapshots = app(WallboardSnapshot::class);
        $first = $publisher->sample($snapshots);
        $second = $publisher->sample($snapshots);
        $this->assertSame('dark', $first['display_auto_mode']);
        $this->assertSame('light', $second['display_auto_mode']);
        $this->assertSame('2026-11-09 20:00:00 HST', $first['clock']);
        $this->assertSame('2026-11-10 15:00:00 JST', $second['clock']);
        $this->assertSame($first['generated_at'], $second['generated_at']);
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_existing_hdmi_palette_is_split_without_changing_its_meaning()
    {
        $service = $this->mock(Wallboard::class);
        $service->shouldReceive('settings')->andReturn(
            array_merge(Wallboard::DEFAULTS, ['palette' => 'neutral-light']),
            array_merge(Wallboard::DEFAULTS, ['palette' => 'warm']),
            array_merge(Wallboard::DEFAULTS, ['palette' => 'warm'])
        );
        $service->shouldReceive('status')->andReturn(['state' => 'off', 'connected' => false]);
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(WallboardSettings::class)->assertSet('style', 'neutral')->assertSet('color_mode', 'light');
        Livewire::actingAs($user)->test(WallboardSettings::class)->assertSet('style', 'warm')->assertSet('color_mode', 'dark');
        \App\Models\Setting::updateOrCreate(['key' => 'hdmi_color_mode'], ['value' => 'auto']);
        Livewire::actingAs($user)->test(WallboardSettings::class)->assertSet('style', 'warm')->assertSet('color_mode', 'auto');
    }

    public function test_settings_require_login_validate_all_fields_and_send_only_the_fixed_schema()
    {
        Livewire::test(WallboardSettings::class)->call('save')->assertForbidden();
        $service = $this->mock(Wallboard::class);
        $service->shouldReceive('settings')->andReturn(Wallboard::DEFAULTS);
        $service->shouldReceive('status')->andReturn(['state' => 'off', 'connected' => false]);
        $service->shouldReceive('save')->once()->with(['mode' => 'auto', 'palette' => 'warm', 'view' => 'auto', 'rotation' => 12]);
        $component = Livewire::actingAs(User::factory()->create())->test(WallboardSettings::class)
            ->set('mode', 'shell')->call('save')->assertHasErrors('mode')
            ->set('mode', 'auto')->set('rotation', 0)->call('save')->assertHasErrors('rotation')
            ->set('rotation', 12)->call('save')->assertHasNoErrors()->assertSee('Saved.');
        foreach ([['warm', 'light', 'warm-light'], ['neutral', 'dark', 'neutral'], ['neutral', 'light', 'neutral-light'],
                  ['warm', 'auto', 'warm'], ['neutral', 'auto', 'neutral']] as [$style, $colorMode, $palette]) {
            $service->shouldReceive('save')->once()->with(['mode' => 'auto', 'palette' => $palette, 'view' => 'auto', 'rotation' => 12]);
            $component->set('style', $style)->set('color_mode', $colorMode)->call('save')->assertHasNoErrors();
        }
        $this->assertSame('auto', \App\Models\Setting::find('hdmi_color_mode')->value);
        $this->assertTrue(app(WallboardSnapshot::class)->current()['hdmi_auto_mode']);
        $service->shouldReceive('save')->once()->with(['mode' => 'auto', 'palette' => 'neutral', 'view' => 'map', 'rotation' => 12]);
        $component->assertSee('Full switch map')->set('view', 'map')->call('save')->assertHasNoErrors();
        $component->set('view', 'invalid')->call('save')->assertHasErrors('view');
        $component->set('view', 'auto')->set('style', 'invalid')->call('save')->assertHasErrors('style');
        $component->set('style', 'warm')->set('color_mode', 'invalid')->call('save')->assertHasErrors('color_mode');
    }
}
