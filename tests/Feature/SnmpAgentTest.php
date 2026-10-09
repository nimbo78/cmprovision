<?php

namespace Tests\Feature;

use App\Services\Snmp\PhpSnmpClient;
use App\Services\Snmp\SnmpError;
use App\Services\SwitchPortFinder;
use Tests\TestCase;

/**
 * The switch lookup against a real SNMP agent: snmpsim serving tests/fixtures/snmp, where the v2c
 * community (and the v3 context) selects the data file, like per-VLAN instances on Cisco IOS.
 * Runs only when SNMPSIM_ENDPOINT is set, e.g. SNMPSIM_ENDPOINT=127.0.0.1:1161 (see docker/README.md).
 */
class SnmpAgentTest extends TestCase
{
    const MODULE = 'e4:5f:01:62:9f:d3';

    protected function setUp(): void
    {
        parent::setUp();
        if (!getenv('SNMPSIM_ENDPOINT'))
            $this->markTestSkipped('SNMPSIM_ENDPOINT is not set');
        if (!class_exists(\SNMP::class))
            $this->markTestSkipped('php-snmp is not installed');
    }

    protected function finder($community, $method = 'auto')
    {
        return new SwitchPortFinder(new PhpSnmpClient(['host' => getenv('SNMPSIM_ENDPOINT'), 'community' => $community]), $method);
    }

    public function test_vlan_aware_switch_through_q_bridge()
    {
        $finder = $this->finder('qbridge');
        $this->assertSame('GE0/0/5', $finder->portOf(self::MODULE));
        $this->assertSame('qbridge', $finder->lastMethod());

        $scan = $this->finder('qbridge')->scan();
        $this->assertSame('uplink', $scan['ports']['00:11:22:33:44:55'], 'a port description wins over the name');
        $this->assertSame(10, $scan['vlans'][self::MODULE]);
    }

    public function test_cisco_ios_per_vlan_instances_through_community_indexing()
    {
        $finder = $this->finder('ciscoios');
        $this->assertSame('Gi0/5', $finder->portOf(self::MODULE));
        $this->assertSame('cisco', $finder->lastMethod());
    }

    public function test_huawei_l2mam_table()
    {
        $finder = $this->finder('huawei');
        $this->assertSame('GE0/0/3', $finder->portOf(self::MODULE));
        $this->assertSame('huawei', $finder->lastMethod());
    }

    public function test_huawei_yunshan_bridge_ports_through_l2if()
    {
        $finder = $this->finder('yunshan');
        $this->assertSame('GE1/0/30', $finder->portOf(self::MODULE));
        $this->assertSame('bridge', $finder->lastMethod());
    }

    public function test_mikrotik_routeros_q_bridge_with_fdb_id_zero_and_comments()
    {
        $finder = $this->finder('routeros');
        $this->assertSame('line slot 3', $finder->portOf(self::MODULE));
        $this->assertSame('qbridge', $finder->lastMethod());
    }

    public function test_snmpv3_vlan_context()
    {
        // php-snmp keeps a pointer to the context name: built at run time ("vlan-20") and freed,
        // it once turned into garbage and the agent never answered
        $client = new PhpSnmpClient(['host' => getenv('SNMPSIM_ENDPOINT'), 'version' => '3', 'user' => 'cmprova',
                                     'auth_protocol' => 'SHA', 'auth_password' => 'authpass123']);

        $rows = $client->walk(SwitchPortFinder::OID_BRIDGE_FDB_PORT, 20);

        $this->assertEquals(5, $rows[SwitchPortFinder::OID_BRIDGE_FDB_PORT.'.228.95.1.98.159.211']);
    }

    public function test_an_agent_that_does_not_answer_is_a_timeout()
    {
        $client = new PhpSnmpClient(['host' => getenv('SNMPSIM_ENDPOINT'), 'community' => 'no-such-community']);
        try {
            $client->walk(SwitchPortFinder::OID_IFNAME);
            $this->fail('expected a timeout');
        } catch (SnmpError $e) {
            $this->assertTrue($e->timeout);
        }
    }
}
