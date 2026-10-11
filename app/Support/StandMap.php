<?php

namespace App\Support;

use App\Models\Cm;

/**
 * The stand as the Ports page draws it: a SwitchSnapshot and the modules behind its MAC addresses as
 * port tiles laid out like the switch's front panel, and what is on the stand now.
 */
class StandMap
{
    /* More MAC addresses than this that are not modules make a port an uplink */
    const UPLINK_MACS = 3;
    /* A module asks for DHCP seconds after its link comes up: a link without any MAC for longer is a quiet device */
    const BOOT_SECONDS = 180;
    /* Main ports per block: six pairs, odd ones on top as on the case */
    const BLOCK = 12;
    /* Cages for a switch without PoE tables: 10G and faster names, and MikroTik's sfp ports */
    const CAGE_NAMES = '/^(xge|xgigabitethernet|25ge|40ge|100ge|te|fo|hu|twe|sfp)/i';
    /* Worst first: a port with several modules shows the first of these that one of them has */
    const SEVERITY = ['fail', 'stale', 'run', 'unknown', 'ok'];
    /* A running module's tile without a percent shows its step */
    const STEP_MARKS = ['preinstall' => 'PRE', 'write' => 'WRITE', 'verify' => 'VERIFY', 'postinstall' => 'POST'];

    protected $snapshot, $modules = [], $serverMac;

    /**
     * @param array $snapshot SwitchSnapshot::take()
     * @param iterable $modules Cm models; their mac in any case
     */
    public function __construct(array $snapshot, iterable $modules, ?string $serverMac)
    {
        $this->snapshot = $snapshot;
        foreach ($modules as $cm)
            if ($cm->mac)
                $this->modules[strtolower($cm->mac)] = $cm;
        $this->serverMac = $serverMac ? strtolower($serverMac) : null;
    }

    public function build(): array
    {
        $ports = array_values($this->snapshot['ports'] ?? []);
        usort($ports, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        $withPoe = (bool) array_filter($ports, function ($p) { return $p['poe'] !== null; });

        $main = $uplinks = [];
        foreach ($ports as $port)
        {
            $tile = $this->tile($port);
            $tile['sfp'] = $withPoe ? $port['poe'] === null : $this->cage($port);
            $tile['mark'] = $this->mark($tile);
            if ($tile['sfp'])
                $uplinks[] = $tile;
            else
                $main[] = $tile;
        }

        $rows = count($main) > self::BLOCK ? 2 : 1;
        $blocks = [];
        foreach ($rows === 1 ? [$main] : array_chunk($main, self::BLOCK) as $chunk)
            if ($chunk)
                $blocks[] = ['uplinks' => false, 'rows' => $rows, 'tiles' => $chunk];
        if ($uplinks)
            $blocks[] = ['uplinks' => true, 'rows' => $rows, 'tiles' => $uplinks];

        $tiles = [];
        foreach (array_merge($main, $uplinks) as $tile)
            $tiles[$tile['key']] = $tile;

        return ['switch' => $this->snapshot['id'] ?? null, 'blocks' => $blocks, 'tiles' => $tiles,
                'summary' => $this->summary($main, $tiles, count($uplinks), $withPoe)];
    }

    /* What the dashboard says about a module, in one word */
    public static function moduleState(Cm $cm): string
    {
        if ($cm->isActive())
            return $cm->silentFor() ? 'stale' : 'run';
        if ($cm->phase === 'done')
            return 'ok';
        if ($cm->phase === 'failed')
            return 'fail';
        return $cm->provisioning_complete_at ? 'ok' : 'unknown';
    }

    /* 1000 -> "1G", 2500 -> "2.5G", 100 -> "100M" */
    public static function speedLabel(int $mbps): string
    {
        return $mbps >= 1000 ? ($mbps / 1000).'G' : $mbps.'M';
    }

    protected function tile(array $port): array
    {
        $macs = array_map('strtolower', $port['macs']);
        $modules = [];
        foreach ($macs as $mac)
            if (isset($this->modules[$mac]))
                $modules[] = $this->modules[$mac];
        $others = ($port['mac_count'] ?? count($macs)) - count($modules);
        $modules = $this->present($modules);
        usort($modules, function ($a, $b) {
            return array_search(self::moduleState($a), self::SEVERITY, true) - array_search(self::moduleState($b), self::SEVERITY, true);
        });

        if (!$port['up'])
            $state = 'free';   // a pulled module's MAC stays in the switch table for minutes
        elseif (($this->serverMac && in_array($this->serverMac, $macs, true)) || $others > self::UPLINK_MACS)
            $state = 'uplink';
        elseif ($modules)
            $state = 'module';
        elseif ($macs)
            $state = 'foreign';
        elseif (($port['changed'] ?? null) !== null && $port['changed'] > self::BOOT_SECONDS)
            $state = 'quiet';   // a LAG member, a silent device, the provisioner's port after a long idle time
        else
            $state = 'boot';

        $filter = $state === 'module' ? self::moduleState($modules[0]) : ($state === 'quiet' ? 'foreign' : $state);
        return [
            'key' => 'p'.$port['ifIndex'],
            'name' => $port['name'],
            'alias' => $port['alias'],
            'number' => preg_match('/(\d+)\D*$/', $port['name'], $m) ? (int) $m[1] : null,
            'up' => $port['up'],
            'speed' => $port['speed'],
            'changed' => $port['changed'] ?? null,
            'poe' => $port['poe'],
            'macs' => $macs,
            'mac_count' => $port['mac_count'] ?? count($macs),
            'state' => $state,
            'filter' => $filter,
            // a port with several modules answers to the chip of each of their states
            'filters' => $state === 'module' ? array_values(array_unique(array_map([self::class, 'moduleState'], $modules))) : [$filter],
            'modules' => $state === 'module' ? $modules : [],
            'short' => $state === 'module' ? strtoupper(substr($modules[0]->serial, -4))
                : (['uplink' => 'UP', 'foreign' => 'MAC', 'boot' => '···', 'quiet' => 'LNK'][$state] ?? ''),
        ];
    }

    /* Several modules on one port: a switch that keeps a MAC after the link drops lists the module taken
       away next to the one put in its place. Those that neither run nor poll give way to one that does. */
    protected function present(array $modules): array
    {
        if (count($modules) < 2)
            return $modules;
        $alive = array_values(array_filter($modules, function ($cm) { return $cm->isActive() || $cm->canIdentify(); }));
        return $alive ?: $modules;
    }

    protected function cage(array $port): bool
    {
        return preg_match(self::CAGE_NAMES, $port['name']) === 1
            || ($port['up'] && $port['speed'] !== null && $port['speed'] >= 10000);
    }

    /* The line under the serial: ✓, ✕, !, ?, percent, step, or ×N for several modules */
    protected function mark(array $tile): string
    {
        if ($tile['state'] === 'free')
            return $tile['sfp'] ? 'SFP' : '';
        if ($tile['state'] !== 'module')
            return '';
        if (count($tile['modules']) > 1)
            return '×'.count($tile['modules']);
        $cm = $tile['modules'][0];
        switch ($tile['filter'])
        {
            case 'ok': return '✓';
            case 'fail': return '✕';
            case 'stale': return '!';
            case 'unknown': return '?';
        }
        $percent = $cm->progressPercent();
        if ($percent !== null)
            return $percent.'%';
        if ($cm->phase === 'preinstall' && stripos((string) $cm->phase_detail, 'eeprom') !== false)
            return 'EEPROM';
        return self::STEP_MARKS[$cm->phase] ?? '';
    }

    protected function summary(array $main, array $tiles, int $cages, bool $withPoe): array
    {
        $counts = array_fill_keys(['ok', 'run', 'fail', 'stale', 'unknown', 'boot', 'foreign', 'free'], 0);
        $modules = 0;
        foreach ($tiles as $tile)
            foreach ($tile['modules'] as $cm)
            {
                $counts[self::moduleState($cm)]++;
                $modules++;
            }
        foreach ($main as $tile)
            if (in_array($tile['state'], ['boot', 'foreign', 'quiet', 'free'], true))
                $counts[$tile['filter']]++;

        $busy = $counts['run'] + $counts['stale'] + $counts['boot'];
        if ($busy)
            $verdict = 'running';
        elseif ($counts['fail'] + $counts['unknown'])
            $verdict = 'failed';
        elseif ($modules)
            $verdict = 'done';
        else
            $verdict = 'empty';

        return ['modules' => $modules, 'ports' => count($main), 'counts' => $counts, 'verdict' => $verdict, 'busy' => $busy,
                'composition' => $this->composition($main, $cages, $withPoe)];
    }

    /* "24 × 1G PoE · 4 × SFP": the main ports with their usual speed, and the cages */
    protected function composition(array $main, int $cages, bool $withPoe): string
    {
        $speeds = array_count_values(array_filter(array_map(function ($t) { return $t['speed']; }, $main)));
        arsort($speeds);
        $kind = trim(($speeds ? self::speedLabel((int) array_key_first($speeds)) : '').($withPoe ? ' PoE' : ''));
        $parts = [];
        if ($main)
            $parts[] = $kind !== '' ? count($main).' × '.$kind : count($main).' ports';
        if ($cages)
            $parts[] = $cages.' × SFP';
        return implode(' · ', $parts);
    }
}
