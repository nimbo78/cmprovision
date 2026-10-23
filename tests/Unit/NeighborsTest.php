<?php

namespace Tests\Unit;

use App\Services\Switch\MacTable;
use App\Services\Switch\Neighbors;
use App\Services\Switch\SwitchSnapshot;
use Tests\Support\FakeSnmp;
use Tests\TestCase;

/**
 * What LLDP and CDP say about the devices on a switch's ports. The first test replays what a real Catalyst 2960-CX
 * (IOS 15.2(7)E14) answered about the provisioner on Gi0/8.
 */
class NeighborsTest extends TestCase
{
    const REM = '.1.0.8802.1.1.2.1.4.1.1';
    const MAN = '.1.0.8802.1.1.2.1.4.2.1.3';
    const LOC_ID = '.1.0.8802.1.1.2.1.3.7.1.3';
    const LOC_DESC = '.1.0.8802.1.1.2.1.3.7.1.4';
    const CDP = '.1.3.6.1.4.1.9.9.23.1.2.1.1';

    /* A lldpRemTable row as the agent sends it, columns by number */
    protected function rem(int $local, int $index, array $columns, int $time = 0): array
    {
        $t = [];
        foreach ($columns as $column => $value)
            $t[self::REM.'.'.$column.'.'.$time.'.'.$local.'.'.$index] = $value;
        return $t;
    }

    protected function cx(): array
    {
        $ports = [];
        foreach (range(1, 12) as $n)
            $ports[10100 + $n] = ['name' => 'Gi0/'.$n];
        return $ports;
    }

    protected function read(array $table, array $ports): array
    {
        return (new Neighbors(new FakeSnmp(['' => $table]), 'sw'))->read($ports);
    }

    public function test_the_provisioner_on_a_2960cx_is_found_by_the_name_of_its_port_and_not_by_the_port_number()
    {
        $mac = hex2bin('2ccf67307da2');
        $t = $this->rem(8, 118, [4 => 4, 5 => $mac, 6 => 3, 7 => $mac, 8 => 'eth0', 9 => 'wlanpi-da2',
            10 => "Debian GNU/Linux 13 (trixie) Linux 6.18.50+rpt-rpi-v8 #1 SMP\nsecond line", 11 => hex2bin('3900'), 12 => hex2bin('1000')]);
        $t[self::MAN.'.0.8.118.1.4.172.20.0.1'] = 2;
        $t[self::MAN.'.0.8.118.2.16.254.128.0.0.0.0.0.0.46.207.103.255.254.48.125.162'] = 2;   // fe80::2ecf:67ff:fe30:7da2
        foreach (range(1, 12) as $n)
        {
            $t[self::LOC_ID.'.'.$n] = 'Gi0/'.$n;                       // lldpLocPortNum counts 1, 2, ...; the interfaces are 10101, ...
            $t[self::LOC_DESC.'.'.$n] = 'GigabitEthernet0/'.$n;
        }
        $found = $this->read($t, $this->cx());

        $this->assertSame([10108], array_keys($found['ports']));
        $this->assertSame([
            'protocol' => 'lldp', 'name' => 'wlanpi-da2', 'description' => 'Debian GNU/Linux 13 (trixie) Linux 6.18.50+rpt-rpi-v8 #1 SMP',
            'platform' => '', 'port' => 'eth0', 'chassis' => '2c:cf:67:30:7d:a2', 'mac' => '2c:cf:67:30:7d:a2', 'port_mac' => '2c:cf:67:30:7d:a2',
            'address' => '172.20.0.1', 'capabilities' => ['wlan-ap'],
        ], $found['ports'][10108][0]);
        $this->assertSame(['lldp' => true, 'cdp' => null], $found['discovery']);
    }

    public function test_a_switch_whose_port_number_is_the_ifindex_needs_no_name()
    {
        $found = $this->read($this->rem(6, 1, [4 => 4, 5 => hex2bin('aabbccddeeff'), 6 => 5, 7 => 'ge1', 9 => 'ap-lobby']),
            [6 => ['name' => 'GE0/0/1'], 7 => ['name' => 'GE0/0/2']]);
        $this->assertSame('ap-lobby', $found['ports'][6][0]['name']);
        $this->assertSame('ge1', $found['ports'][6][0]['port'], 'with no description the port id says it');
    }

    public function test_the_port_is_found_by_its_ifdescr_when_the_names_differ()
    {
        $t = $this->rem(77, 1, [4 => 7, 5 => 'chassis-x', 9 => 'phone']);
        $t[self::LOC_ID.'.77'] = 'GigabitEthernet0/0/2';
        $t[SwitchSnapshot::OID_IFDESCR.'.7'] = 'GigabitEthernet0/0/2';
        $t[SwitchSnapshot::OID_IFDESCR.'.6'] = 'GigabitEthernet0/0/1';
        $found = $this->read($t, [6 => ['name' => 'GE0/0/1'], 7 => ['name' => 'GE0/0/2']]);
        $this->assertSame([7], array_keys($found['ports']));
        $this->assertSame('chassis-x', $found['ports'][7][0]['chassis']);
    }

    public function test_a_neighbour_on_a_port_the_switch_does_not_list_is_ignored()
    {
        $found = $this->read($this->rem(99, 1, [4 => 4, 5 => hex2bin('aabbccddeeff'), 9 => 'ghost']), [6 => ['name' => 'GE0/0/1']]);
        $this->assertSame([], $found['ports']);
    }

    public function test_a_cdp_neighbour_has_a_platform_a_port_an_address_and_capabilities()
    {
        $t = [];
        foreach ([3 => 1, 4 => "\xc0\xa8\x01\x05", 5 => "Cisco IP Phone 8851\nlong version text", 6 => 'SEP001122334455', 7 => 'Port 1',
                  8 => 'Cisco IP Phone 8851', 9 => pack('N', 0x90)] as $column => $value)
            $t[self::CDP.'.'.$column.'.10103.1'] = $value;
        $t['.1.3.6.1.4.1.9.9.23.1.3.1.0'] = 1;
        $found = $this->read($t, $this->cx());

        $this->assertSame([
            'protocol' => 'cdp', 'name' => 'SEP001122334455', 'description' => 'Cisco IP Phone 8851', 'platform' => 'Cisco IP Phone 8851',
            'port' => 'Port 1', 'chassis' => '', 'mac' => null, 'port_mac' => null, 'address' => '192.168.1.5', 'capabilities' => ['station', 'telephone'],
        ], $found['ports'][10103][0]);
        $this->assertSame(['lldp' => false, 'cdp' => true], $found['discovery']);
    }

    public function test_one_port_can_have_an_lldp_and_a_cdp_neighbour_and_shows_at_most_four()
    {
        $t = [];
        for ($i = 1; $i <= 6; $i++)
            $t += $this->rem(10103, $i, [4 => 4, 5 => hex2bin('02000000000'.$i), 9 => 'dev'.$i]);   // the number is an ifIndex here
        $t[self::CDP.'.6.10103.1'] = 'cdp-dev';
        $found = $this->read($t, $this->cx());
        $this->assertCount(4, $found['ports'][10103]);
        $this->assertSame('lldp', $found['ports'][10103][0]['protocol'], 'LLDP first');
    }

    public function test_what_a_device_says_is_made_safe_to_show()
    {
        $evil = "ev\x1b[31mil\x07 name\u{202E}rtl\u{200B}\x00 and \xff bytes";
        $found = $this->read($this->rem(6, 1, [4 => 7, 5 => 'id', 9 => $evil, 10 => str_repeat('x', 400), 8 => "port\r\n9"]), [6 => ['name' => 'GE0/0/1']]);
        $n = $found['ports'][6][0];
        $this->assertTrue(mb_check_encoding($n['name'], 'UTF-8'));
        $this->assertDoesNotMatchRegularExpression('/[\p{Cc}\p{Cf}]/u', $n['name'].$n['port'].$n['description']);
        $this->assertStringContainsString('name', $n['name']);
        $this->assertLessThanOrEqual(80, mb_strlen($n['name']));
        $this->assertLessThanOrEqual(160, mb_strlen($n['description']));
        $this->assertSame('port 9', $n['port']);
        $this->assertSame(80, mb_strlen(Neighbors::text(str_repeat('é', 200))));
    }

    public function test_a_switch_without_lldp_and_cdp_has_no_neighbours_and_no_error()
    {
        $found = $this->read([SwitchSnapshot::OID_SYSNAME.'.0' => 'sw'], $this->cx());
        $this->assertSame([], $found['ports']);
        $this->assertSame(['lldp' => false, 'cdp' => null], $found['discovery']);
    }

    public function test_cdp_switched_off_is_told_apart_from_a_switch_without_the_mib()
    {
        $found = $this->read(['.1.3.6.1.4.1.9.9.23.1.3.1.0' => 2], $this->cx());
        $this->assertFalse($found['discovery']['cdp']);
    }

    public function test_the_neighbours_are_asked_once_in_a_few_seconds_and_a_silent_switch_keeps_the_last_look()
    {
        $t = $this->rem(6, 1, [4 => 4, 5 => hex2bin('aabbccddeeff'), 9 => 'ap-lobby']);
        $snmp = new FakeSnmp(['' => $t]);
        $neighbors = new Neighbors($snmp, 'sw');
        $ports = [6 => ['name' => 'GE0/0/1']];
        $neighbors->read($ports);
        $asked = count($snmp->walks);
        $this->assertGreaterThan(0, $asked);
        $neighbors->read($ports);
        $this->assertCount($asked, $snmp->walks, 'a second look within the time to live asks nothing');

        $this->travel(30)->seconds();
        $snmp->timeout = true;
        $found = $neighbors->read($ports);
        $this->assertSame('ap-lobby', $found['ports'][6][0]['name'], 'a switch that stops answering shows what it said last');
        $failed = count($snmp->walks);
        $neighbors->read($ports);
        $this->assertCount($failed, $snmp->walks, 'a failed read is not tried again at once: every snapshot would wait for the timeout');

        $this->travel(11)->minutes();
        $found = $neighbors->read($ports);
        $this->assertSame([], $found['ports'], 'but not for ever');
    }

    /* IF-MIB rows of two Ethernet ports, enough for a snapshot */
    protected function interfaces(): array
    {
        $t = [];
        foreach ([6 => 'GE0/0/1', 7 => 'GE0/0/2'] as $ifIndex => $name)
            $t += [SwitchSnapshot::OID_IFNAME.'.'.$ifIndex => $name, SwitchSnapshot::OID_IFTYPE.'.'.$ifIndex => 6,
                   SwitchSnapshot::OID_IFCONNECTOR.'.'.$ifIndex => 1, SwitchSnapshot::OID_IFOPERSTATUS.'.'.$ifIndex => 1,
                   SwitchSnapshot::OID_IFHIGHSPEED.'.'.$ifIndex => 1000];
        return $t;
    }

    public function test_what_lldp_and_cdp_say_goes_on_the_port_in_the_snapshot_with_a_word_on_whether_the_switch_tells_it()
    {
        $table = $this->interfaces() + $this->rem(6, 1, [4 => 7, 5 => 'ap-1', 9 => 'ap-lobby']);
        $snmp = new FakeSnmp(['' => $table]);
        $s = (new SwitchSnapshot($snmp, new MacTable($snmp), null, '192.0.2.10', new Neighbors($snmp, '192.0.2.10')))->take();
        $this->assertSame('ap-lobby', $s['ports'][6]['neighbors'][0]['name']);
        $this->assertSame([], $s['ports'][7]['neighbors']);
        $this->assertSame(['lldp' => true, 'cdp' => null], $s['discovery']);

        $snmp = new FakeSnmp(['' => $this->interfaces()]);
        $plain = (new SwitchSnapshot($snmp, new MacTable($snmp), null, '192.0.2.10'))->take();
        $this->assertSame(['lldp' => false, 'cdp' => null], $plain['discovery'], 'a snapshot made without a reader of neighbours');
        $this->assertSame([], $plain['ports'][6]['neighbors']);
    }

    public function test_a_switch_whose_neighbours_cannot_be_read_still_has_its_snapshot()
    {
        $snmp = new FakeSnmp(['' => $this->interfaces()]);
        $neighbors = new class($snmp, 'x') extends Neighbors {
            protected function fetch(array $ports): array
            {
                throw new \ErrorException('snmp2_walk(): No response');
            }
        };
        $s = (new SwitchSnapshot($snmp, new MacTable($snmp), null, '192.0.2.10', $neighbors))->take();
        $this->assertSame([6, 7], array_keys($s['ports']));
        $this->assertSame([], $s['ports'][6]['neighbors']);
    }

    public function test_a_device_that_talks_both_lldp_and_cdp_is_one_neighbour()
    {
        // a WLAN Pi sends both; the same name on the same port
        $t = $this->rem(10101, 1, [4 => 4, 5 => hex2bin('e45f016b6c18'), 8 => 'eth0', 9 => 'wlanpi-c18', 10 => 'Debian GNU/Linux 13', 12 => hex2bin('20')]);
        foreach ([4 => "\xac\x14\xf3\x95", 5 => 'Debian GNU/Linux 13', 6 => 'WLANPI-C18', 7 => 'eth0', 8 => 'Linux', 9 => pack('N', 0x12)] as $column => $value)
            $t[self::CDP.'.'.$column.'.10101.1'] = $value;
        $t[self::CDP.'.6.10102.1'] = 'phone-9';
        $found = $this->read($t, $this->cx());

        $this->assertCount(1, $found['ports'][10101]);
        $n = $found['ports'][10101][0];
        $this->assertSame(['lldp/cdp', 'wlanpi-c18', 'Linux', '172.20.243.149', ['bridge', 'station']],
            [$n['protocol'], $n['name'], $n['platform'], $n['address'], $n['capabilities']]);
        $this->assertSame('e4:5f:01:6b:6c:18', $n['mac']);
        $this->assertSame(['cdp'], array_column($found['ports'][10102], 'protocol'), 'another device stays what it is');
    }

    public function test_two_different_devices_on_one_port_stay_two()
    {
        $t = $this->rem(10101, 1, [4 => 7, 5 => 'a', 9 => 'switch-a']);
        $t[self::CDP.'.6.10101.1'] = 'phone-9';
        $found = $this->read($t, $this->cx());
        $this->assertSame(['switch-a', 'phone-9'], array_column($found['ports'][10101], 'name'));
    }

    public function test_a_flood_of_fake_neighbours_is_not_read()
    {
        $t = [];
        for ($i = 0; $i < Neighbors::MAX_ROWS + 1; $i++)
            $t += $this->rem(10101, $i + 1, [4 => 7, 5 => 'id'.$i, 9 => 'fake'.$i]);
        $snmp = new FakeSnmp(['' => $t]);
        $found = (new Neighbors($snmp, 'sw'))->read($this->cx());
        $this->assertSame([], $found['ports']);
        $this->assertTrue($found['discovery']['lldp'], 'the switch does answer');
        foreach ($snmp->walks as [$oid])
            $this->assertNotSame(self::REM, $oid, 'the whole table is not walked');
    }

    public function test_cdp_failing_alone_keeps_what_lldp_said_and_is_left_alone_for_a_minute()
    {
        $snmp = new FakeSnmp(['' => $this->rem(6, 1, [4 => 7, 5 => 'ap-1', 9 => 'ap-lobby'])]);
        $snmp->onWalk = function ($oid) {
            if (strpos($oid, self::CDP) === 0)
                throw new \App\Services\Snmp\SnmpError('No response from 192.0.2.1', true);
        };
        $neighbors = new Neighbors($snmp, 'sw');
        $found = $neighbors->read([6 => ['name' => 'GE0/0/1']]);
        $this->assertSame('ap-lobby', $found['ports'][6][0]['name']);
        $this->assertNull($found['discovery']['cdp']);

        $this->travel(30)->seconds();   // the answer has expired, CDP is still left alone
        $asked = count(array_filter($snmp->walks, fn ($w) => strpos($w[0], self::CDP) === 0));
        $neighbors->read([6 => ['name' => 'GE0/0/1']]);
        $this->assertCount($asked, array_filter($snmp->walks, fn ($w) => strpos($w[0], self::CDP) === 0));
    }

    public function test_neighbours_of_a_port_that_is_down_or_came_up_after_they_were_read_are_not_shown()
    {
        $table = $this->interfaces() + $this->rem(6, 1, [4 => 7, 5 => 'ap-1', 9 => 'ap-lobby'])
            + [SwitchSnapshot::OID_SYSUPTIME.'.0' => 100000, SwitchSnapshot::OID_IFLASTCHANGE.'.6' => 50000, SwitchSnapshot::OID_IFLASTCHANGE.'.7' => 50000];
        $snmp = new FakeSnmp(['' => $table]);
        $snapshot = fn () => (new SwitchSnapshot($snmp, new MacTable($snmp), null, '192.0.2.10', new Neighbors($snmp, '192.0.2.10')))->take();
        $this->assertSame('ap-lobby', $snapshot()['ports'][6]['neighbors'][0]['name'], 'a link up for minutes keeps them');

        $this->travel(10)->seconds();   // read 10 s ago, still in the cache
        $snmp->tables[''][SwitchSnapshot::OID_IFLASTCHANGE.'.6'] = 99700;   // the link came up 3 s ago
        $this->assertSame([], $snapshot()['ports'][6]['neighbors'], 'a link that came up after the neighbours were read is another device');

        $snmp->tables[''][SwitchSnapshot::OID_IFLASTCHANGE.'.6'] = 50000;
        $snmp->tables[''][SwitchSnapshot::OID_IFOPERSTATUS.'.6'] = 2;
        $this->assertSame([], $snapshot()['ports'][6]['neighbors'], 'a port that is down has no neighbour');
    }
}
