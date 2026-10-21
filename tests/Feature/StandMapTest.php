<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Services\Switch\Stand;
use App\Support\StandMap;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The stand as the Ports page draws it: one tile per port in front-panel order, the module behind
 * each MAC address, and what is on the stand now.
 */
class StandMapTest extends TestCase
{
    const SERVER = '2c:cf:67:30:7d:a2';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function port(int $ifIndex, string $name, bool $up = true, array $macs = [], $poe = null, $speed = 1000)
    {
        return ['ifIndex' => $ifIndex, 'name' => $name, 'alias' => '', 'up' => $up, 'speed' => $speed, 'macs' => $macs, 'poe' => $poe];
    }

    protected function snapshot(array $ports)
    {
        $byIndex = [];
        foreach ($ports as $p)
            $byIndex[$p['ifIndex']] = $p;
        return ['id' => '192.0.2.10', 'model' => 'S5731', 'taken_at' => 0, 'duration' => 0.3, 'mac_method' => 'huawei',
                'identity' => ['revision' => null, 'serial' => null, 'name' => 'sw01', 'mac' => null],
                'poe' => (bool) array_filter($ports, function ($p) { return $p['poe'] !== null; }), 'ports' => $byIndex];
    }

    protected function module(string $serial, string $mac, array $attributes = [])
    {
        return new Cm(array_merge(['serial' => $serial, 'mac' => $mac, 'phase' => 'done',
            'provisioning_complete_at' => Carbon::now()->subMinute()], $attributes));
    }

    protected function build(array $ports, array $modules = [])
    {
        return (new StandMap([['id' => 1, 'name' => 'sw01', 'snapshot' => $this->snapshot($ports), 'answering' => true]], $modules, self::SERVER))->build();
    }

    /* One switch of a stand for StandMap: its id, name, ports (null: it never answered), own MACs and remembered links */
    protected function part(int $id, string $name, ?array $ports, array $ownMacs = [], array $links = [], bool $answering = true)
    {
        return ['id' => $id, 'name' => $name, 'snapshot' => $ports === null ? null : $this->snapshot($ports),
                'answering' => $answering && $ports !== null, 'own_macs' => $ownMacs, 'links' => $links];
    }

    public function test_a_chain_puts_each_module_on_its_own_switch()
    {
        // sw01 Gi0/24 leads to sw02: its table holds sw02's modules and sw02's own SVI MAC
        $m1 = $this->module('10000000000000a1', 'e4:5f:01:00:00:a1');
        $m2 = $this->module('10000000000000a2', 'e4:5f:01:00:00:a2');
        $m3 = $this->module('10000000000000a3', 'e4:5f:01:00:00:a3');
        $map = (new StandMap([
            $this->part(1, 'sw01', [$this->port(1, 'Gi0/1', true, [$m1->mac]),
                                    $this->port(24, 'Gi0/24', true, [$m2->mac, $m3->mac, '00:57:d2:97:a1:c0'])]),
            $this->part(2, 'sw02', [$this->port(1, 'Gi0/1', true, [$m2->mac]), $this->port(2, 'Gi0/2', true, [$m3->mac])],
                        ['00:57:d2:97:a1:80', '00:57:d2:97:a1:c0']),
        ], [$m1, $m2, $m3], self::SERVER))->build();

        $link = $map['tiles']['s1p24'];
        $this->assertSame(['uplink', 'sw02', []], [$link['state'], $link['link'], $link['modules']]);
        $this->assertSame(['→', 'sw02'], [$link['short'], $link['mark']], 'the tile names where it leads');
        $this->assertSame('sw01 Gi0/24 is the link to sw02', StandMap::restartBlocker($link));
        $this->assertSame([$m2->serial], array_map(function ($cm) { return $cm->serial; }, $map['tiles']['s2p1']['modules']));
        $this->assertSame('s2p1', $map['owners'][$m2->mac]['key']);
        $this->assertSame(['s1p24', 's2p1'], $map['owners'][$m2->mac]['seen']);
        $this->assertSame(3, $map['summary']['modules'], 'each module once');
        $this->assertSame([24 => 2], $map['switches'][1]['links']);
    }

    public function test_a_remembered_link_holds_while_the_neighbour_is_silent()
    {
        $m2 = $this->module('10000000000000a2', 'e4:5f:01:00:00:a2');
        $map = (new StandMap([
            $this->part(1, 'sw01', [$this->port(24, 'Gi0/24', true, [$m2->mac])], [], [24 => 2]),
            $this->part(2, 'sw02', null, ['00:57:d2:97:a1:c0'], [], false),
        ], [$m2], self::SERVER))->build();

        $this->assertSame(['uplink', 'sw02'], [$map['tiles']['s1p24']['state'], $map['tiles']['s1p24']['link']]);
        $this->assertNull($map['owners'][$m2->mac]['key'], 'behind the link: no port of its own');
        $this->assertSame([], $map['switches'][2]['blocks']);
    }

    public function test_a_module_seen_alike_on_two_switches_is_on_neither()
    {
        $m = $this->module('10000000000000a4', 'e4:5f:01:00:00:a4');
        $map = (new StandMap([
            $this->part(1, 'sw01', [$this->port(5, 'Gi0/5', true, [$m->mac])]),
            $this->part(2, 'sw02', [$this->port(3, 'Gi0/3', true, [$m->mac])]),
        ], [$m], self::SERVER))->build();

        $this->assertNull($map['owners'][$m->mac]['key']);
        $this->assertSame([[], []], [$map['tiles']['s1p5']['modules'], $map['tiles']['s2p3']['modules']]);
        $this->assertSame(0, $map['summary']['modules']);
    }

    public function test_the_port_where_only_modules_are_wins_a_tie()
    {
        $m = $this->module('10000000000000a5', 'e4:5f:01:00:00:a5');
        $n = $this->module('10000000000000a6', 'e4:5f:01:00:00:a6');
        $map = (new StandMap([
            $this->part(1, 'sw01', [$this->port(5, 'Gi0/5', true, [$m->mac, $n->mac])]),
            $this->part(2, 'sw02', [$this->port(3, 'Gi0/3', true, [$m->mac, 'aa:00:00:00:00:01'])]),
        ], [$m, $n], self::SERVER))->build();

        $this->assertSame('s1p5', $map['owners'][$m->mac]['key']);
        $this->assertSame(1, $map['tiles']['s2p3']['others'], 'the module that belongs elsewhere is no other device here');
    }

    public function test_a_module_moved_to_the_other_switch_leaves_its_old_port_to_the_new_one()
    {
        // x went from sw01 Gi0/5 to sw02 Gi0/3; sw01 still lists it on Gi0/5, where y is plugged in now
        $x = $this->module('10000000000000a7', 'e4:5f:01:00:00:a7');
        $y = $this->module('10000000000000a8', 'e4:5f:01:00:00:a8');
        $map = (new StandMap([
            $this->part(1, 'sw01', [$this->port(5, 'Gi0/5', true, [$x->mac, $y->mac])]),
            $this->part(2, 'sw02', [$this->port(3, 'Gi0/3', true, [$x->mac])]),
        ], [$x, $y], self::SERVER))->build();

        $this->assertSame(['s2p3', 's1p5'], [$map['owners'][$x->mac]['key'], $map['owners'][$y->mac]['key']]);
        $this->assertSame(0, $map['tiles']['s1p5']['others'], 'x belongs to sw02: y alone may be restarted');
        $this->assertNull(StandMap::restartBlocker(['poe' => ['state' => 'delivering', 'class' => 4, 'watts' => 6.9]] + $map['tiles']['s1p5']));
    }

    public function test_ports_of_two_switches_never_share_a_key()
    {
        $map = (new StandMap([
            $this->part(1, 'Switch', [$this->port(5, 'GE0/0/5', false)]),
            $this->part(2, 'Switch', [$this->port(5, 'GE0/0/5', false)]),
        ], [], self::SERVER))->build();

        $this->assertSame(['s1p5', 's2p5'], array_keys($map['tiles']));
        $this->assertSame(['Switch GE0/0/5', 2], [$map['tiles']['s2p5']['label'], $map['tiles']['s2p5']['switch_id']]);
    }

    public function test_the_board_takes_the_port_description()
    {
        $port = $this->port(3, 'Gi0/3', true);
        $port['alias'] = 'rack 3';
        $map = (new StandMap([$this->part(1, 'sw01', [$port])], [], self::SERVER))->build();
        $this->assertSame(['sw01 rack 3', 'rack 3', 'sw01 Gi0/3'],
            [$map['tiles']['s1p3']['board'], $map['tiles']['s1p3']['board_port'], $map['tiles']['s1p3']['label']]);
    }

    public function test_every_kind_of_port()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $poe = ['state' => 'delivering', 'class' => 4, 'watts' => 6.9];
        $map = $this->build([
            $this->port(1, 'GE0/0/1', true, ['e4:5f:01:00:00:01'], $poe),
            $this->port(2, 'GE0/0/2', true, [], $poe),                                // link, no MAC yet
            $this->port(3, 'GE0/0/3', true, ['3c:22:fb:91:0a:5e'], $poe),             // a laptop
            $this->port(4, 'GE0/0/4', false, ['e4:5f:01:00:00:04'], ['state' => 'searching', 'class' => null, 'watts' => null]),
            $this->port(30, 'XGE0/0/1', true, [self::SERVER, '00:11:22:33:44:55'], null, 10000),
        ], [
            $this->module('10000000e611110c', 'E4:5F:01:00:00:01'),
            $this->module('10000000aaaa0004', 'e4:5f:01:00:00:04'),
        ]);

        $t = $map['tiles'];
        $this->assertSame(['module', 'ok', '110C', '✓'], [$t['s1p1']['state'], $t['s1p1']['filter'], $t['s1p1']['short'], $t['s1p1']['mark']]);
        $this->assertSame(['boot', '···'], [$t['s1p2']['state'], $t['s1p2']['short']]);
        $this->assertSame(['foreign', 'MAC'], [$t['s1p3']['state'], $t['s1p3']['short']]);
        $this->assertSame('free', $t['s1p4']['state'], 'no link: free, even though the MAC is still in the switch table');
        $this->assertSame([], $t['s1p4']['modules']);
        $this->assertSame(['uplink', 'UP', true], [$t['s1p30']['state'], $t['s1p30']['short'], $t['s1p30']['sfp']]);
        $this->assertSame(1, $map['summary']['modules']);
        $this->assertSame(4, $map['summary']['ports']);
        $this->assertSame(['ok' => 1, 'run' => 0, 'fail' => 0, 'stale' => 0, 'unknown' => 0, 'boot' => 1, 'foreign' => 1, 'free' => 1], $map['summary']['counts']);
        $this->assertSame('4 × 1G PoE · 1 × SFP', $map['switches'][1]['composition']);
    }

    public function test_module_states_like_the_dashboard()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $ports = [];
        for ($i = 1; $i <= 7; $i++)
            $ports[] = $this->port($i, 'ether'.$i, true, ['aa:00:00:00:00:0'.$i]);
        $map = $this->build($ports, [
            $this->module('10000000000000a1', 'aa:00:00:00:00:01', ['phase' => 'write', 'progress_bytes' => 430, 'progress_total' => 1000, 'progress_updated_at' => Carbon::now()->subSeconds(5)]),
            $this->module('10000000000000a2', 'aa:00:00:00:00:02', ['phase' => 'write', 'progress_bytes' => 120, 'progress_total' => 1000, 'progress_updated_at' => Carbon::now()->subMinutes(2)]),
            $this->module('10000000000000a3', 'aa:00:00:00:00:03', ['phase' => 'preinstall', 'phase_detail' => 'Flash EEPROM firmware (default/pieeprom-2026-09-23.bin)', 'progress_updated_at' => Carbon::now()]),
            $this->module('10000000000000a4', 'aa:00:00:00:00:04', ['phase' => 'failed']),
            $this->module('10000000000000a5', 'aa:00:00:00:00:05', ['phase' => null, 'provisioning_complete_at' => null]),
            $this->module('10000000000000a6', 'aa:00:00:00:00:06', ['phase' => null]),
            $this->module('10000000000000a7', 'aa:00:00:00:00:07', ['phase' => 'postinstall', 'phase_detail' => 'label', 'progress_updated_at' => Carbon::now()]),
        ]);

        $marks = array_map(function ($t) { return [$t['filter'], $t['mark']]; }, array_values($map['tiles']));
        $this->assertSame([['run', '43%'], ['stale', '!'], ['run', 'EEPROM'], ['fail', '✕'], ['unknown', '?'], ['ok', '✓'], ['run', 'POST']], $marks);
        $this->assertSame('running', $map['summary']['verdict']);
        $this->assertSame(4, $map['summary']['busy'], 'three running and one silent');
    }

    public function test_several_modules_behind_one_port_show_the_worst_with_their_count()
    {
        $map = $this->build([$this->port(1, 'GE0/0/1', true, ['aa:00:00:00:00:01', 'aa:00:00:00:00:02'])], [
            $this->module('10000000000000a1', 'aa:00:00:00:00:01'),
            $this->module('10000000000000a2', 'aa:00:00:00:00:02', ['phase' => 'failed']),
        ]);

        $tile = $map['tiles']['s1p1'];
        $this->assertSame(['module', 'fail', '×2', 'A2'], [$tile['state'], $tile['filter'], $tile['mark'], substr($tile['short'], -2)]);
        $this->assertSame(['10000000000000a2', '10000000000000a1'], array_map(function ($cm) { return $cm->serial; }, $tile['modules']));
        $this->assertSame(2, $map['summary']['modules']);
        $this->assertSame(1, $map['summary']['counts']['ok']);
        $this->assertSame(1, $map['summary']['counts']['fail']);
    }

    public function test_four_modules_behind_one_port_are_modules_not_an_uplink()
    {
        $macs = ['aa:00:00:00:00:01', 'aa:00:00:00:00:02', 'aa:00:00:00:00:03', 'aa:00:00:00:00:04'];
        $modules = [];
        foreach ($macs as $i => $mac)
            $modules[] = $this->module('10000000000000a'.($i + 1), $mac);
        // a desktop switch: four modules and a laptop
        $map = $this->build([$this->port(1, 'GE0/0/1', true, array_merge($macs, ['3c:22:fb:91:0a:5e']))], $modules);

        $this->assertSame(['module', '×4'], [$map['tiles']['s1p1']['state'], $map['tiles']['s1p1']['mark']]);
        $this->assertSame(4, $map['summary']['modules']);
    }

    public function test_a_module_that_left_gives_way_to_the_one_now_on_its_port()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        // the switch still lists the MAC of the module taken away next to the one put in its place
        $map = $this->build([$this->port(1, 'GE0/0/1', true, ['aa:00:00:00:00:01', 'aa:00:00:00:00:02'])], [
            $this->module('10000000000000a1', 'aa:00:00:00:00:01', ['phase' => 'failed', 'polled_at' => Carbon::now()->subMinutes(10)]),
            $this->module('10000000000000a2', 'aa:00:00:00:00:02', ['phase' => 'write', 'progress_bytes' => 100, 'progress_total' => 1000, 'progress_updated_at' => Carbon::now()]),
        ]);

        $tile = $map['tiles']['s1p1'];
        $this->assertSame(['run', '10%', '00A2'], [$tile['filter'], $tile['mark'], $tile['short']]);
        $this->assertSame(1, $map['summary']['modules']);
        $this->assertSame(0, $map['summary']['counts']['fail']);
    }

    public function test_a_link_without_any_mac_for_minutes_is_a_quiet_device_not_a_boot()
    {
        $map = $this->build([
            array_merge($this->port(1, 'GE0/0/1', true), ['changed' => 30]),
            array_merge($this->port(2, 'GE0/0/2', true), ['changed' => 600]),   // a LAG member, an idle provisioner port...
        ]);

        $this->assertSame(['boot', 'boot', '···'], [$map['tiles']['s1p1']['state'], $map['tiles']['s1p1']['filter'], $map['tiles']['s1p1']['short']]);
        $this->assertSame(['quiet', 'foreign', 'LNK'], [$map['tiles']['s1p2']['state'], $map['tiles']['s1p2']['filter'], $map['tiles']['s1p2']['short']]);
        $this->assertSame([1, 1], [$map['summary']['counts']['boot'], $map['summary']['counts']['foreign']]);
        $this->assertSame(1, $map['summary']['busy'], 'only the booting port keeps the batch in progress');
    }

    public function test_an_unknown_raspberry_pi_boots_for_the_first_minutes_of_its_link()
    {
        // a module asks for DHCP before it asks the server: for a few seconds the switch knows its MAC, the server does not
        $map = $this->build([
            array_merge($this->port(1, 'GE0/0/1', true, ['E4:5F:01:62:9F:D4']), ['changed' => 20]),
            array_merge($this->port(2, 'GE0/0/2', true, ['d8:3a:dd:00:00:02']), ['changed' => 600]),   // a Pi that never asks
            array_merge($this->port(3, 'GE0/0/3', true, ['3c:22:fb:91:0a:5e']), ['changed' => 20]),    // a laptop
            array_merge($this->port(4, 'GE0/0/4', true, ['e4:5f:01:00:00:04', '3c:22:fb:91:0a:5e']), ['changed' => 20]),
            $this->port(5, 'GE0/0/5', true, ['dc:a6:32:00:00:05']),                                    // no link time from the switch
            array_merge($this->port(6, 'GE0/0/6', true, ['f0:40:af:90:00:06']), ['changed' => 180]),   // a 28-bit block, at the limit
            array_merge($this->port(7, 'GE0/0/7', true, ['f0:40:af:a0:00:07']), ['changed' => 20]),    // next to it, not Raspberry Pi's
        ]);

        $states = array_map(function ($t) { return $t['state']; }, $map['tiles']);
        $this->assertEquals(['s1p1' => 'boot', 's1p2' => 'foreign', 's1p3' => 'foreign', 's1p4' => 'foreign', 's1p5' => 'foreign',
            's1p6' => 'boot', 's1p7' => 'foreign'], $states);
        $this->assertSame(['boot', '···'], [$map['tiles']['s1p1']['filter'], $map['tiles']['s1p1']['short']]);
        $this->assertSame(2, $map['summary']['counts']['boot']);
    }

    public function test_a_port_with_several_modules_answers_to_each_of_their_states()
    {
        $map = $this->build([$this->port(1, 'GE0/0/1', true, ['aa:00:00:00:00:01', 'aa:00:00:00:00:02'])], [
            $this->module('10000000000000a1', 'aa:00:00:00:00:01'),
            $this->module('10000000000000a2', 'aa:00:00:00:00:02', ['phase' => 'failed']),
        ]);

        $this->assertSame(['fail', 'ok'], $map['tiles']['s1p1']['filters']);
        $this->assertSame(['free'], $this->build([$this->port(2, 'GE0/0/2', false)])['tiles']['s1p2']['filters']);
    }

    public function test_an_uplink_counts_all_its_macs_not_just_those_kept()
    {
        $port = array_merge($this->port(30, 'XGE0/0/1', true, ['00:00:00:00:00:01']), ['mac_count' => 500]);
        $this->assertSame('uplink', $this->build([$port])['tiles']['s1p30']['state']);
    }

    public function test_the_map_names_each_switch_as_its_part_says()
    {
        $map = (new StandMap([$this->part(1, 't-iof-sw01-provision', [$this->port(1, 'GE0/0/1', false)])], [], self::SERVER))->build();
        $this->assertSame(['t-iof-sw01-provision', 't-iof-sw01-provision GE0/0/1'], [$map['switches'][1]['name'], $map['tiles']['s1p1']['label']]);
    }

    public function test_a_port_that_sees_two_switches_leads_to_the_nearer_one()
    {
        // sw01 Gi0/24 -> sw02 Gi0/24, sw02 Gi0/23 -> sw03 Gi0/1: sw01's port sees both, sw02 sees sw03 where sw01 is not
        $own = ['sw01' => '00:00:00:00:01:01', 'sw02' => '00:00:00:00:02:02', 'sw03' => '00:00:00:00:03:03'];
        $map = (new StandMap([
            $this->part(1, 'sw01', [$this->port(24, 'Gi0/24', true, [$own['sw02'], $own['sw03']])], [$own['sw01']]),
            $this->part(2, 'sw03', [$this->port(1, 'Gi0/1', true, [$own['sw01'], $own['sw02']])], [$own['sw03']]),
            $this->part(3, 'sw02', [$this->port(24, 'Gi0/24', true, [$own['sw01']]), $this->port(23, 'Gi0/23', true, [$own['sw03']])], [$own['sw02']]),
        ], [], self::SERVER))->build();

        $this->assertSame(['sw02', 'sw02', 'sw03', 'sw01'],
            [$map['tiles']['s1p24']['link'], $map['tiles']['s2p1']['link'], $map['tiles']['s3p23']['link'], $map['tiles']['s3p24']['link']]);
    }

    public function test_a_module_moved_to_another_port_shows_on_the_new_one_only()
    {
        $map = $this->build([
            $this->port(3, 'GE0/0/3', false, ['aa:00:00:00:00:01']),   // the old port: the MAC has not aged out yet
            $this->port(7, 'GE0/0/7', true, ['aa:00:00:00:00:01']),
        ], [$this->module('10000000000000a1', 'aa:00:00:00:00:01')]);

        $this->assertSame('free', $map['tiles']['s1p3']['state']);
        $this->assertSame('module', $map['tiles']['s1p7']['state']);
        $this->assertSame(1, $map['summary']['modules']);
    }

    public function test_more_than_three_macs_make_an_uplink()
    {
        $map = $this->build([$this->port(9, 'GE0/0/9', true, ['00:00:00:00:00:01', '00:00:00:00:00:02', '00:00:00:00:00:03', '00:00:00:00:00:04'])]);
        $this->assertSame('uplink', $map['tiles']['s1p9']['state']);
    }

    public function test_eight_poe_ports_in_one_row_and_the_rest_as_uplinks()
    {
        $poe = ['state' => 'searching', 'class' => null, 'watts' => null];
        $ports = [];
        for ($i = 1; $i <= 8; $i++)
            $ports[] = $this->port($i, 'Gi1/0/'.$i, false, [], $poe);
        $ports[] = $this->port(9, 'Gi1/0/9', false);
        $ports[] = $this->port(10, 'Gi1/0/10', false);
        $map = $this->build($ports);

        $this->assertCount(2, $map['blocks']);
        $this->assertSame([false, 1, 8], [$map['blocks'][0]['uplinks'], $map['blocks'][0]['rows'], count($map['blocks'][0]['tiles'])]);
        $this->assertSame([true, 1, ['Gi1/0/9', 'Gi1/0/10']], [$map['blocks'][1]['uplinks'], $map['blocks'][1]['rows'], array_column($map['blocks'][1]['tiles'], 'name')]);
        $this->assertSame('SFP', $map['tiles']['s1p9']['mark'], 'an empty uplink cage');
        $this->assertSame(8, $map['summary']['ports']);
        $this->assertSame('8 × 1G PoE · 2 × SFP', $map['switches'][1]['composition']);
    }

    public function test_the_switch_tells_copper_uplinks_from_cages()
    {
        // the stand's Catalyst 2960-CX: Cisco gives a port without a link the speed 10
        $poe = ['state' => 'searching', 'class' => null, 'watts' => null];
        $ports = [];
        for ($i = 1; $i <= 8; $i++)
        {
            $up = in_array($i, [1, 3, 8], true);
            $ports[] = $this->port(10100 + $i, 'Gi0/'.$i, $up, [], $poe, $up ? 1000 : 10) + ['media' => 'copper', 'transceiver' => null];
        }
        foreach ([9 => 'copper', 10 => 'copper', 11 => 'sfp', 12 => 'sfp'] as $i => $media)
            $ports[] = $this->port(10100 + $i, 'Gi0/'.$i, false, [], null, 10) + ['media' => $media, 'transceiver' => $media === 'sfp' ? false : null];
        $map = $this->build($ports);

        $this->assertSame('8 × 1G PoE · 2 × 1G · 2 × SFP', $map['switches'][1]['composition']);
        $this->assertSame(['Gi0/9', 'Gi0/10', 'Gi0/11', 'Gi0/12'], array_column($map['blocks'][1]['tiles'], 'name'));
        $t = $map['tiles'];
        $this->assertSame([true, false, false, ''], [$t['s1p10109']['aside'], $t['s1p10109']['sfp'], $t['s1p10109']['combo'], $t['s1p10109']['mark']], 'a copper uplink');
        $this->assertSame([true, 'SFP'], [$t['s1p10111']['sfp'], $t['s1p10111']['mark']], 'an empty cage');
    }

    public function test_verified_panel_preserves_ownership_keys_and_main_port_count_even_without_poe_tables()
    {
        $ports = [];
        $cm = $this->module('10000000000000ab', 'e4:5f:01:00:00:ab');
        for ($i = 1; $i <= 12; $i++)
            $ports[] = $this->port(10100 + $i, 'Gi0/'.$i, $i === 3, $i === 3 ? [$cm->mac] : []);
        $snapshot = $this->snapshot($ports);
        $snapshot['model'] = 'WS-C2960CX-8PC-L';
        $map = (new StandMap([['id' => 7, 'name' => 'sw01', 'snapshot' => $snapshot]], [$cm], self::SERVER))->build();
        $this->assertSame(['model' => 'WS-C2960CX-8PC-L'], $map['switches'][7]['panel']);
        $this->assertSame([2, 2, 1, 1], array_column($map['switches'][7]['blocks'], 'rows'));
        $this->assertSame(8, $map['summary']['ports']);
        $this->assertCount(12, $map['tiles']);
        $this->assertSame('s7p10103', $map['owners'][$cm->mac]['key']);
        $this->assertSame('sw01 Gi0/3', $map['tiles']['s7p10103']['board']);
        $this->assertSame([1, 11, 'sw01 Gi0/11', true, true, 'SFP'],
            [$map['tiles']['s7p10111']['panel_number'], $map['tiles']['s7p10111']['number'], $map['tiles']['s7p10111']['label'],
             $map['tiles']['s7p10111']['sfp'], $map['tiles']['s7p10111']['aside'], $map['tiles']['s7p10111']['mark']]);
    }

    public function test_verified_fiber_switch_counts_all_nine_ports_and_does_not_invent_poe_output()
    {
        $cm = $this->module('10000000000000ac', 'e4:5f:01:00:00:ac');
        $ports = [$this->port(1, 'ether1', false)];
        for ($i = 1; $i <= 8; $i++)
            $ports[] = $this->port($i + 1, 'sfp-sfpplus'.$i, $i === 1, $i === 1 ? [$cm->mac] : [], null, 10000);
        $snapshot = $this->snapshot($ports);
        $snapshot['model'] = 'RouterOS CRS309-1G-8S+';
        $map = (new StandMap([['id' => 1, 'name' => 'sw01', 'snapshot' => $snapshot]], [$cm], self::SERVER))->build();
        $this->assertNotNull($map['switches'][1]['panel']);
        $this->assertSame([9, 1], [$map['summary']['ports'], $map['summary']['modules']]);
        $this->assertSame('s1p2', $map['owners'][$cm->mac]['key']);
        $this->assertSame('no PoE on sw01 sfp-sfpplus1', StandMap::restartBlocker($map['tiles']['s1p2']));
        $this->assertNull($map['tiles']['s1p1']['poe'], 'PoE in is not PoE output');
        $this->assertFalse($map['tiles']['s1p1']['aside']);
    }

    public function test_the_usual_speed_is_what_the_names_say_then_what_the_links_run_at()
    {
        // Huawei gives a port without a link 1000, and a module may link at 100M (a two-pair cable)
        $poe = ['state' => 'searching', 'class' => null, 'watts' => null];
        $ports = [];
        for ($i = 1; $i <= 24; $i++)
            $ports[] = $this->port($i, 'GE0/0/'.$i, $i === 1, [], $poe, $i === 1 ? 100 : 1000);
        $this->assertSame('24 × 1G PoE', $this->build($ports)['switches'][1]['composition']);

        // names that tell no speed: the ports with a link, not the 10 a Cisco-like agent gives the others
        $ports = [];
        for ($i = 1; $i <= 8; $i++)
            $ports[] = $this->port($i, 'ether'.$i, $i <= 2, [], null, $i <= 2 ? 1000 : 10);
        $this->assertSame('8 × 1G', $this->build($ports)['switches'][1]['composition']);
    }

    public function test_combo_ports_go_with_the_uplinks_and_show_their_transceiver()
    {
        // a switch without PoE with two dual-purpose uplinks, an SFP in the second one
        $ports = [];
        for ($i = 1; $i <= 4; $i++)
            $ports[] = $this->port($i, 'Gi0/'.$i, false) + ['media' => 'copper', 'transceiver' => null];
        $ports[] = $this->port(5, 'Gi0/5', false) + ['media' => 'combo', 'transceiver' => false];
        $ports[] = $this->port(6, 'Gi0/6', false) + ['media' => 'combo', 'transceiver' => true];
        $map = $this->build($ports);

        $this->assertSame(['Gi0/5', 'Gi0/6'], array_column($map['blocks'][1]['tiles'], 'name'));
        $t = $map['tiles'];
        $this->assertSame([true, false, false, ''], [$t['s1p5']['combo'], $t['s1p5']['sfp'], $t['s1p5']['transceiver'], $t['s1p5']['mark']]);
        $this->assertTrue($t['s1p6']['transceiver']);
        $this->assertSame('4 × 1G · 2 × combo', $map['switches'][1]['composition']);
    }

    public function test_forty_eight_ports_in_blocks_of_twelve_on_two_rows()
    {
        $poe = ['state' => 'searching', 'class' => null, 'watts' => null];
        $ports = [];
        for ($i = 48; $i >= 1; $i--)
            $ports[] = $this->port($i, 'GE0/0/'.$i, false, [], $poe);
        $map = $this->build($ports);

        $this->assertCount(4, $map['blocks']);
        $this->assertSame(2, $map['blocks'][0]['rows']);
        $this->assertSame(['GE0/0/1', 'GE0/0/2', 'GE0/0/3'], array_slice(array_column($map['blocks'][0]['tiles'], 'name'), 0, 3), 'natural order');
        $this->assertSame('GE0/0/13', $map['blocks'][1]['tiles'][0]['name']);
        $this->assertSame(12, $map['blocks'][0]['tiles'][11]['number']);
    }

    public function test_without_poe_names_and_speeds_tell_copper_from_cages()
    {
        $map = $this->build([
            $this->port(10, 'ether10', false),
            $this->port(2, 'ether2', false),
            $this->port(11, 'sfp-sfpplus1', false),
            $this->port(12, 'Te1/1/1', false),
            $this->port(13, 'GE0/0/25', true, [], null, 10000),
            $this->port(14, 'GE0/0/26', false, [], null, 10000),   // 10G without a link: copper by the spec's rule
        ]);

        $this->assertSame(['ether2', 'ether10', 'GE0/0/26'], array_column($map['blocks'][0]['tiles'], 'name'));
        $this->assertSame(['GE0/0/25', 'sfp-sfpplus1', 'Te1/1/1'], array_column($map['blocks'][1]['tiles'], 'name'));
        $this->assertSame('3 × 1G · 3 × SFP', $map['switches'][1]['composition']);
    }

    public function test_verdicts()
    {
        $port = $this->port(1, 'GE0/0/1', true, ['aa:00:00:00:00:01']);
        $this->assertSame('done', $this->build([$port], [$this->module('10000000000000a1', 'aa:00:00:00:00:01')])['summary']['verdict']);
        $this->assertSame('failed', $this->build([$port], [$this->module('10000000000000a1', 'aa:00:00:00:00:01', ['phase' => 'failed'])])['summary']['verdict']);
        $this->assertSame('failed', $this->build([$port], [$this->module('10000000000000a1', 'aa:00:00:00:00:01', ['phase' => null, 'provisioning_complete_at' => null])])['summary']['verdict'],
            'a module without data is not ready either');
        $this->assertSame('empty', $this->build([$this->port(1, 'GE0/0/1', false)])['summary']['verdict']);
    }

    public function test_only_ports_with_nothing_but_our_modules_or_a_booting_pi_may_be_restarted()
    {
        $poe = ['state' => 'delivering', 'class' => null, 'watts' => 6.9];
        $map = $this->build([
            $this->port(1, 'GE0/0/1', true, ['e4:5f:01:00:00:01'], $poe),                         // our module alone
            $this->port(2, 'GE0/0/2', true, ['e4:5f:01:00:00:02', '00:11:22:33:44:55'], $poe),    // a module and a camera
            $this->port(3, 'GE0/0/3', true, ['e4:5f:01:00:00:03']),                               // no PoE
            $this->port(4, 'GE0/0/4', true, ['00:11:22:33:44:66'], $poe),                         // someone else's device
            $this->port(5, 'GE0/0/5', false, ['e4:5f:01:00:00:05'], $poe),                        // no link
            $this->port(6, 'GE0/0/6', true, [self::SERVER], $poe),                                // the provisioner
            $this->port(7, 'GE0/0/7', true, ['d8:3a:dd:00:00:07'], $poe) + ['changed' => 20],     // a Pi that has not asked yet
        ], [$this->module('1000000000000001', 'e4:5f:01:00:00:01'), $this->module('1000000000000002', 'e4:5f:01:00:00:02'),
            $this->module('1000000000000003', 'e4:5f:01:00:00:03'), $this->module('1000000000000005', 'e4:5f:01:00:00:05')]);
        $why = function ($ifIndex) use ($map) { return StandMap::restartBlocker($map['tiles']['s1p'.$ifIndex]); };

        $this->assertNull($why(1));
        $this->assertSame('other devices on sw01 GE0/0/2', $why(2));
        $this->assertSame('no PoE on sw01 GE0/0/3', $why(3));
        $this->assertSame('other devices on sw01 GE0/0/4', $why(4));
        $this->assertSame('sw01 GE0/0/5 has no link', $why(5));
        $this->assertSame('sw01 GE0/0/6 is the uplink', $why(6));
        $this->assertNull($why(7));
        $this->assertSame([1, 2], [$map['tiles']['s1p2']['others'], $map['tiles']['s1p2']['ifIndex']]);
    }

    public function test_a_module_is_found_on_its_own_port_not_on_the_uplink()
    {
        $module = $this->module('1000000000000001', 'E4:5F:01:00:00:01');
        $map = $this->build([
            $this->port(1, 'GE0/0/1', true, ['e4:5f:01:00:00:01'], ['state' => 'delivering', 'class' => null, 'watts' => 6.9]),
            $this->port(30, 'XGE0/0/1', true, [self::SERVER, 'e4:5f:01:00:00:01']),
        ], [$module]);
        $this->assertSame('GE0/0/1', Stand::tileOf($map, $module)['name']);
        $this->assertNull(Stand::tileOf($map, $this->module('1000000000000009', 'e4:5f:01:00:00:09')));
        $this->assertNull(Stand::tileOf(null, $module));
    }

    public function test_a_port_with_several_modules_or_a_module_writing_its_eeprom_is_not_restarted()
    {
        Carbon::setTestNow('2026-10-12 12:00:00');
        $poe = ['state' => 'delivering', 'class' => null, 'watts' => 6.9];
        $running = ['phase' => 'write', 'provisioning_complete_at' => null, 'provisioning_started_at' => Carbon::now()->subMinutes(2),
                    'progress_updated_at' => Carbon::now()];
        $eeprom = ['phase' => 'preinstall', 'phase_detail' => 'Flash EEPROM firmware (pieeprom-2026-09-23.bin)',
                   'provisioning_complete_at' => null, 'provisioning_started_at' => Carbon::now()->subHours(2), 'progress_updated_at' => Carbon::now()];
        $map = $this->build([
            $this->port(1, 'GE0/0/1', true, ['e4:5f:01:00:00:01', 'e4:5f:01:00:00:02'], $poe),   // two running modules behind one port
            $this->port(2, 'GE0/0/2', true, ['e4:5f:01:00:00:03'], $poe),                         // flashing its bootloader
            $this->port(3, 'GE0/0/3', true, ['e4:5f:01:00:00:04'], $poe),                         // silent on that step for an hour
        ], [$this->module('1000000000000001', 'e4:5f:01:00:00:01', $running), $this->module('1000000000000002', 'e4:5f:01:00:00:02', $running),
            $this->module('1000000000000003', 'e4:5f:01:00:00:03', $eeprom),
            $this->module('1000000000000004', 'e4:5f:01:00:00:04', ['progress_updated_at' => Carbon::now()->subHour()] + $eeprom)]);
        $why = function ($ifIndex) use ($map) { return StandMap::restartBlocker($map['tiles']['s1p'.$ifIndex]); };

        $this->assertSame('2 modules on sw01 GE0/0/1', $why(1));
        $this->assertSame('the module on sw01 GE0/0/2 is writing its bootloader EEPROM', $why(2));
        $this->assertNull($why(3), 'a module silent on that step for long may be restarted');
    }

    public function test_a_port_whose_poe_is_off_is_not_restarted()
    {
        $map = $this->build([$this->port(1, 'GE0/0/1', true, ['e4:5f:01:00:00:01'], ['state' => 'disabled', 'class' => null, 'watts' => null])],
            [$this->module('1000000000000001', 'e4:5f:01:00:00:01')]);
        $this->assertSame('PoE is off on sw01 GE0/0/1', StandMap::restartBlocker($map['tiles']['s1p1']), 'someone switched it off: a restart would switch it on');
    }

    public function test_poe_in_words()
    {
        $this->assertSame('delivering 6.9 W, class 4', StandMap::poeText(['state' => 'delivering', 'class' => 4, 'watts' => 6.9]));
        $this->assertSame('looking for a device', StandMap::poeText(['state' => 'searching', 'class' => null, 'watts' => null]));
        $this->assertSame('off', StandMap::poeText(['state' => 'disabled', 'class' => null, 'watts' => null]));
        $this->assertSame('none', StandMap::poeText(null));
    }
}
