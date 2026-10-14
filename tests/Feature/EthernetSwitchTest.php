<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\EthernetSwitch;
use App\Models\PoeOutage;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The switches of the stand: a row each, made from the 2.3 settings on upgrade */
class EthernetSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function migration()
    {
        return require database_path('migrations/2026_10_14_100000_create_ethernet_switches_table.php');
    }

    public function test_the_2_3_settings_become_the_first_switch_and_stay()
    {
        foreach (['ip' => '172.20.233.77', 'snmp_version' => '2c', 'snmp_community' => 'public', 'method' => 'auto',
                  'method_detected' => 'bridge', 'snmp_write_community' => 'private'] as $key => $value)
            Setting::create(['key' => 'ethernetswitch_'.$key, 'value' => $value]);

        $this->migration()->copyLegacySettings();

        $switch = EthernetSwitch::sole();
        $this->assertSame(['172.20.233.77', '2c', 'public', 'auto', 'bridge', 'private'],
            [$switch->host, $switch->snmp_version, $switch->community, $switch->method, $switch->method_detected, $switch->write_community]);
        $this->assertSame(6, Setting::where('key', 'like', 'ethernetswitch_%')->count(), 'a 2.3.x after a rollback still finds them');
    }

    public function test_no_switch_without_an_address_in_the_2_3_settings()
    {
        Setting::create(['key' => 'ethernetswitch_snmp_community', 'value' => 'public']);
        $this->migration()->copyLegacySettings();
        $this->assertSame(0, EthernetSwitch::count());
    }

    public function test_config_is_what_the_snmp_classes_take()
    {
        $switch = EthernetSwitch::create(['host' => '192.0.2.10', 'snmp_version' => '3', 'user' => 'ro', 'auth_protocol' => 'SHA',
            'auth_password' => 'auth-secret', 'priv_protocol' => 'AES', 'priv_password' => 'priv-secret', 'method' => 'qbridge',
            'method_detected' => 'qbridge', 'write_user' => 'rw', 'write_auth_protocol' => 'SHA', 'write_auth_password' => 'w-secret',
            'sys_name' => 'sw01']);

        $c = $switch->config();
        $this->assertSame(['id', 'host', 'version', 'community', 'user', 'auth_protocol', 'auth_password', 'priv_protocol',
            'priv_password', 'method', 'detected', 'sys_name', 'write'], array_keys($c));
        $this->assertSame([$switch->id, '192.0.2.10', '3', '', 'ro', 'qbridge'], [$c['id'], $c['host'], $c['version'], $c['community'], $c['user'], $c['detected']]);
        $this->assertSame(['community' => '', 'user' => 'rw', 'auth_protocol' => 'SHA', 'auth_password' => 'w-secret',
            'priv_protocol' => '', 'priv_password' => ''], $c['write']);
        $this->assertSame('', EthernetSwitch::emptyConfig()['host']);

        $copy = (new EthernetSwitch)->fillConfig($c);
        $this->assertSame(['192.0.2.10', '3', 'priv-secret', 'w-secret', null], [$copy->host, $copy->snmp_version,
            $copy->priv_password, $copy->write_auth_password, $copy->method_detected]);
    }

    public function test_the_hostname_is_sysname_before_its_domain()
    {
        $this->assertSame('t-iof-sw01-provision', EthernetSwitch::shortName('t-iof-sw01-provision.corp.example.com'));
        $this->assertSame('Switch', EthernetSwitch::shortName(' Switch '));
        $this->assertSame('172.20.233.77', EthernetSwitch::shortName('172.20.233.77'));
        $this->assertNull(EthernetSwitch::shortName(''));
        $this->assertNull(EthernetSwitch::shortName(null));

        $switch = new EthernetSwitch(['host' => '192.0.2.10']);
        $this->assertSame('192.0.2.10', $switch->hostname(), 'no sysName yet: the address');
        $switch->sys_name = 'sw02.corp.example.com';
        $this->assertSame('sw02 Gi1/0/3', $switch->board('Gi1/0/3'));
    }

    public function test_a_deleted_switch_leaves_modules_and_outages_without_it()
    {
        $switch = EthernetSwitch::create(['host' => '192.0.2.10']);
        $cm = Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'switch_id' => $switch->id,
            'switch_name' => 'sw01', 'switch_port' => 'GE0/0/3', 'provisioning_board' => 'sw01 GE0/0/3']);
        $outage = PoeOutage::create(['switch_id' => $switch->id, 'switch_host' => '192.0.2.10', 'if_index' => 8,
            'port' => 'sw01 GE0/0/3', 'oid' => '.1.3.6.1.4.1.2011.5.25.195.3.1.3.8', 'on_value' => 1, 'off_at' => now()]);

        $switch->delete();

        $this->assertNull($cm->fresh()->switch_id);
        $this->assertSame('sw01 GE0/0/3', $cm->fresh()->provisioning_board);
        $this->assertNull($outage->fresh()->switch_id);
    }
}
