<?php

namespace Tests\Feature;

use App\Http\Livewire\Settings;
use App\Models\Host;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Static DHCP leases live in the hosts table and as dhcp-host= lines in dnsmasq.conf; the two
 * must stay in sync, and dnsmasq must be restarted so it notices.
 */
class StaticIpSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected $conf;
    protected $restartLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conf = tempnam(sys_get_temp_dir(), 'dnsmasq');
        $this->restartLog = tempnam(sys_get_temp_dir(), 'restart');
        file_put_contents($this->conf, "port=0\nenable-tftp\ndhcp-range=172.20.0.2,172.20.255.255,255.255.0.0\n");
        config(['cmprovision.dnsmasq_conf' => $this->conf]);
        config(['cmprovision.dnsmasq_restart' => 'echo restarted >> '.escapeshellarg($this->restartLog)]);
    }

    protected function tearDown(): void
    {
        @unlink($this->conf);
        @unlink($this->restartLog);
        parent::tearDown();
    }

    public function test_adding_a_static_ip_writes_it_to_dnsmasq_conf_and_restarts_dnsmasq()
    {
        Livewire::test(Settings::class)
            ->call('addStaticIP')
            ->set('ip', '172.20.0.10')->set('mac', 'E4:5F:01:00:00:10')
            ->call('storeStaticIP');

        $conf = file_get_contents($this->conf);
        $this->assertStringContainsString("dhcp-host=e4:5f:01:00:00:10,set:client_is_a_pi,172.20.0.10\n", $conf);
        $this->assertStringContainsString("dhcp-range=", $conf, 'the rest of the configuration is kept');
        $this->assertSame("restarted\n", file_get_contents($this->restartLog));
    }

    public function test_deleting_a_static_ip_removes_it_from_dnsmasq_conf_and_restarts_dnsmasq()
    {
        $keep = Host::create(['ip' => '172.20.0.10', 'mac' => 'e4:5f:01:00:00:10']);
        $gone = Host::create(['ip' => '172.20.0.11', 'mac' => 'e4:5f:01:00:00:11']);
        file_put_contents($this->conf,
            "port=0\ndhcp-host=e4:5f:01:00:00:10,set:client_is_a_pi,172.20.0.10\ndhcp-host=e4:5f:01:00:00:11,set:client_is_a_pi,172.20.0.11\n");

        Livewire::test(Settings::class)->call('deleteStaticIP', $gone->id);

        $conf = file_get_contents($this->conf);
        $this->assertStringContainsString('dhcp-host=e4:5f:01:00:00:10,', $conf);
        $this->assertStringNotContainsString('e4:5f:01:00:00:11', $conf);
        $this->assertSame(0, Host::where('id', $gone->id)->count());
        $this->assertSame("restarted\n", file_get_contents($this->restartLog));
    }

    public function test_a_hostname_is_written_with_the_lease()
    {
        Host::create(['ip' => '172.20.0.12', 'mac' => 'e4:5f:01:00:00:12', 'hostname' => 'sensor0']);
        $other = Host::create(['ip' => '172.20.0.13', 'mac' => 'e4:5f:01:00:00:13']);

        Livewire::test(Settings::class)->call('deleteStaticIP', $other->id);   // any change rewrites the file

        $this->assertStringContainsString("dhcp-host=e4:5f:01:00:00:12,set:client_is_a_pi,172.20.0.12,sensor0\n", file_get_contents($this->conf));
    }
}
