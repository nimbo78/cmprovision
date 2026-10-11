<?php

namespace Tests\Feature;

use App\Models\Cm;
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
                'poe' => (bool) array_filter($ports, function ($p) { return $p['poe'] !== null; }), 'ports' => $byIndex];
    }

    protected function module(string $serial, string $mac, array $attributes = [])
    {
        return new Cm(array_merge(['serial' => $serial, 'mac' => $mac, 'phase' => 'done',
            'provisioning_complete_at' => Carbon::now()->subMinute()], $attributes));
    }

    protected function build(array $ports, array $modules = [])
    {
        return (new StandMap($this->snapshot($ports), $modules, self::SERVER))->build();
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
        $this->assertSame(['module', 'ok', '110C', '✓'], [$t['p1']['state'], $t['p1']['filter'], $t['p1']['short'], $t['p1']['mark']]);
        $this->assertSame(['boot', '···'], [$t['p2']['state'], $t['p2']['short']]);
        $this->assertSame(['foreign', 'MAC'], [$t['p3']['state'], $t['p3']['short']]);
        $this->assertSame('free', $t['p4']['state'], 'no link: free, even though the MAC is still in the switch table');
        $this->assertSame([], $t['p4']['modules']);
        $this->assertSame(['uplink', 'UP', true], [$t['p30']['state'], $t['p30']['short'], $t['p30']['sfp']]);
        $this->assertSame(1, $map['summary']['modules']);
        $this->assertSame(4, $map['summary']['ports']);
        $this->assertSame(['ok' => 1, 'run' => 0, 'fail' => 0, 'stale' => 0, 'unknown' => 0, 'boot' => 1, 'foreign' => 1, 'free' => 1], $map['summary']['counts']);
        $this->assertSame('4 × 1G PoE · 1 × SFP', $map['summary']['composition']);
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

        $tile = $map['tiles']['p1'];
        $this->assertSame(['module', 'fail', '×2', 'A2'], [$tile['state'], $tile['filter'], $tile['mark'], substr($tile['short'], -2)]);
        $this->assertSame(['10000000000000a2', '10000000000000a1'], array_map(function ($cm) { return $cm->serial; }, $tile['modules']));
        $this->assertSame(2, $map['summary']['modules']);
        $this->assertSame(1, $map['summary']['counts']['ok']);
        $this->assertSame(1, $map['summary']['counts']['fail']);
    }

    public function test_a_module_moved_to_another_port_shows_on_the_new_one_only()
    {
        $map = $this->build([
            $this->port(3, 'GE0/0/3', false, ['aa:00:00:00:00:01']),   // the old port: the MAC has not aged out yet
            $this->port(7, 'GE0/0/7', true, ['aa:00:00:00:00:01']),
        ], [$this->module('10000000000000a1', 'aa:00:00:00:00:01')]);

        $this->assertSame('free', $map['tiles']['p3']['state']);
        $this->assertSame('module', $map['tiles']['p7']['state']);
        $this->assertSame(1, $map['summary']['modules']);
    }

    public function test_more_than_three_macs_make_an_uplink()
    {
        $map = $this->build([$this->port(9, 'GE0/0/9', true, ['00:00:00:00:00:01', '00:00:00:00:00:02', '00:00:00:00:00:03', '00:00:00:00:00:04'])]);
        $this->assertSame('uplink', $map['tiles']['p9']['state']);
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
        $this->assertSame('SFP', $map['tiles']['p9']['mark'], 'an empty uplink cage');
        $this->assertSame(8, $map['summary']['ports']);
        $this->assertSame('8 × 1G PoE · 2 × SFP', $map['summary']['composition']);
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
        $this->assertSame('3 × 1G · 3 × SFP', $map['summary']['composition']);
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
}
