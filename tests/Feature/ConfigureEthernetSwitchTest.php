<?php

namespace Tests\Feature;

use App\Models\EthernetSwitch;
use App\Models\PoeOutage;
use App\Services\Snmp\SnmpClient;
use App\Services\SwitchPortFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSnmp;
use Tests\TestCase;

/** artisan ethernetswitch:configure adds a v2c switch or changes the one at that address; it never drops write access */
class ConfigureEthernetSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $fake = new FakeSnmp(['' => [
            SwitchPortFinder::OID_BRIDGE_FDB_PORT.'.228.95.1.98.159.211' => 5,
            SwitchPortFinder::OID_BASEPORT_IFINDEX.'.5' => 5,
            SwitchPortFinder::OID_IFNAME.'.5' => 'GE0/0/5',
        ]]);
        $this->app->bind(SnmpClient::class, function () use ($fake) { return $fake; });
    }

    public function test_a_new_address_adds_a_switch()
    {
        $this->artisan('ethernetswitch:configure')
            ->expectsQuestion('Ethernet switch IP-address (leave empty to list the switches)', '192.0.2.10')
            ->expectsQuestion('SNMP v2c community name', 'public')
            ->assertExitCode(0);

        $switch = EthernetSwitch::sole();
        $this->assertSame(['192.0.2.10', '2c', 'public', 'bridge'], [$switch->host, $switch->snmp_version, $switch->community, $switch->method_detected]);
    }

    public function test_a_known_address_keeps_its_write_access()
    {
        EthernetSwitch::create(['host' => '192.0.2.10', 'community' => 'old', 'write_community' => 'private']);

        $this->artisan('ethernetswitch:configure')
            ->expectsQuestion('Ethernet switch IP-address (leave empty to list the switches)', '192.0.2.10')
            ->expectsQuestion('SNMP v2c community name', 'public')
            ->assertExitCode(0);

        $switch = EthernetSwitch::sole();
        $this->assertSame(['public', 'private'], [$switch->community, $switch->write_community]);
    }

    public function test_remove_keeps_a_switch_with_a_port_left_off()
    {
        $switch = EthernetSwitch::create(['host' => '192.0.2.10']);
        PoeOutage::create(['switch_id' => $switch->id, 'switch_host' => '192.0.2.10', 'if_index' => 8, 'port' => 'sw01 GE0/0/3',
            'oid' => '.1.3.6.1.4.1.2011.5.25.195.3.1.3.8', 'on_value' => 1, 'off_at' => now()->subMinutes(5), 'error' => 'timeout']);

        $this->artisan('ethernetswitch:configure', ['--remove' => '192.0.2.10'])
            ->expectsOutputToContain('Switch them on (Power on) or forget them first')
            ->assertExitCode(1);
        $this->assertSame(1, EthernetSwitch::count());
    }

    public function test_a_switch_on_snmpv3_is_not_turned_into_v2c()
    {
        EthernetSwitch::create(['host' => '192.0.2.10', 'snmp_version' => '3', 'user' => 'cmprov']);

        $this->artisan('ethernetswitch:configure')
            ->expectsQuestion('Ethernet switch IP-address (leave empty to list the switches)', '192.0.2.10')
            ->expectsOutputToContain('uses SNMPv3')
            ->assertExitCode(1);
        $this->assertSame('3', EthernetSwitch::sole()->snmp_version);
    }

    public function test_an_empty_address_lists_and_remove_deletes_one()
    {
        EthernetSwitch::create(['host' => '192.0.2.10', 'sys_name' => 'sw01']);
        EthernetSwitch::create(['host' => '192.0.2.11']);

        $this->artisan('ethernetswitch:configure')
            ->expectsQuestion('Ethernet switch IP-address (leave empty to list the switches)', '')
            ->expectsOutputToContain('192.0.2.10')
            ->assertExitCode(0);
        $this->assertSame(2, EthernetSwitch::count(), 'Enter alone removes nothing');

        $this->artisan('ethernetswitch:configure', ['--remove' => '192.0.2.11'])->assertExitCode(0);
        $this->assertSame(['192.0.2.10'], EthernetSwitch::pluck('host')->all());
    }
}
