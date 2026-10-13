<?php

namespace Tests\Feature;

use App\Services\Snmp\PhpSnmpClient;
use App\Services\Snmp\SnmpError;
use App\Services\Switch\Poe;
use App\Services\Switch\PoeControl;
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

    protected function snapshot($community)
    {
        $snmp = new PhpSnmpClient(['host' => getenv('SNMPSIM_ENDPOINT'), 'community' => $community]);
        return (new \App\Services\Switch\SwitchSnapshot($snmp, new \App\Services\Switch\MacTable($snmp), new \App\Services\Switch\Poe($snmp), 'snmpsim'))->take();
    }

    public function test_snapshot_of_a_huawei_s5731_with_poe_by_interface()
    {
        $s = $this->snapshot('s5731');
        $this->assertSame('S5731-S24P4X', $s['model']);
        $this->assertCount(28, $s['ports'], '24 GE and 4 XGE, not Vlanif1');
        $this->assertSame([self::MODULE], $s['ports'][3]['macs']);
        $this->assertSame(['state' => 'delivering', 'class' => null, 'watts' => 6.9], $s['ports'][3]['poe']);
        $this->assertSame('searching', $s['ports'][4]['poe']['state']);
        $this->assertNull($s['ports'][25]['poe']);
        $this->assertCount(5, $s['ports'][25]['macs'], 'the office LAN behind the uplink');
        $this->assertSame(100, $s['ports'][1]['changed'], 'TimeTicks come through php-snmp as hundredths');
        $this->assertSame(999990, $s['ports'][2]['changed']);
    }

    public function test_snapshot_of_a_cisco_ios_switch_with_poe_through_entity_mib()
    {
        $s = $this->snapshot('c9200');
        $this->assertSame('C9200L-24P-4G', $s['model'], 'the chassis model from ENTITY-MIB, not the first line of sysDescr');
        $this->assertSame(['revision' => 'V01', 'serial' => 'JAE24420ABC', 'name' => 'c9200-stand', 'mac' => '00:aa:bb:cc:dd:00'],
            $s['identity'], 'dot1dBaseBridgeAddress comes through the real php-snmp as six octets');
        $this->assertSame(['copper', 'sfp', true, 'sfp', false],
            [$s['ports'][10101]['media'], $s['ports'][10201]['media'], $s['ports'][10201]['transceiver'], $s['ports'][10202]['media'], $s['ports'][10202]['transceiver']],
            'ENTITY-MIB containers, names and OID-valued aliases through the real php-snmp');
        $this->assertSame([self::MODULE], $s['ports'][10105]['macs'], 'from the VLAN 20 instance');
        $this->assertSame(['state' => 'delivering', 'class' => 4, 'watts' => 7.1], $s['ports'][10105]['poe']);
        $this->assertSame(['00:11:22:33:44:55'], $s['ports'][10201]['macs']);
    }

    public function test_snapshot_of_a_cisco_business_switch_with_poe_by_interface_index()
    {
        $s = $this->snapshot('cbs350');
        $this->assertSame([self::MODULE], $s['ports'][2]['macs']);
        $this->assertSame('delivering', $s['ports'][2]['poe']['state']);
        $this->assertNull($s['ports'][9]['poe'], 'the uplink ports have no PoE');
    }

    public function test_snapshot_of_a_mikrotik_without_poe()
    {
        $s = $this->snapshot('crs');
        $this->assertSame(['ether1', 'ether2', 'ether3', 'ether4', 'ether5', 'ether6', 'ether7', 'ether8', 'sfp-sfpplus1'], array_column($s['ports'], 'name'));
        $this->assertFalse($s['poe']);
        $this->assertSame([self::MODULE], $s['ports'][4]['macs']);
    }

    public function test_poe_goes_off_and_on_through_php_snmp_on_each_vendor()
    {
        foreach (['s5731', 'c9200', 'cbs350'] as $community)
        {
            $snapshot = $this->snapshot($community);
            $client = new PhpSnmpClient(['host' => getenv('SNMPSIM_ENDPOINT'), 'community' => $community]);
            $admin = (new Poe($client))->admin(array_map(function ($port) { return $port['name']; }, $snapshot['ports']));
            $this->assertNotEmpty($admin, $community.': the PoE ports have their objects');
            $oid = reset($admin);
            $control = new PoeControl($client);
            $control->switchOff($oid);
            $this->assertSame(PoeControl::OFF, $control->value($oid), $community);
            $this->assertNull($control->switchOnChecked($oid), $community);
            $this->assertSame(PoeControl::ON, $control->value($oid), $community);
        }
    }
}
