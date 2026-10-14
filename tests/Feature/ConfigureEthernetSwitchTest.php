<?php

namespace Tests\Feature;

use App\Models\EthernetSwitch;
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
