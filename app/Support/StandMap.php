<?php

namespace App\Support;

use App\Models\Cm;

/**
 * The stand as the Ports page draws it: the snapshots of its switches and the modules behind their MAC addresses as
 * port tiles laid out like each switch's front panel, and what is on the stand now. A port towards another switch of
 * the stand is a link (it shows that switch's own MACs), and each module belongs to one port of the whole stand.
 */
class StandMap
{
    /* More MAC addresses than this that are not modules make a port an uplink */
    const UPLINK_MACS = 3;
    /* A module asks for DHCP seconds after its link comes up: a link without any MAC for longer is a quiet device */
    const BOOT_SECONDS = 180;
    /* Raspberry Pi's IEEE blocks (MA-L, MA-M and MA-S; standards-oui.ieee.org, October 2026) as leading hex digits */
    const RASPBERRY_PI_PREFIXES = ['28cdc1', '2ccf67', '88a29e', '98fe54', 'b827eb', 'd83add', 'dca632', 'e45f01', 'f040af9', '8c1f6434a'];
    /* Main ports per block: six pairs, odd ones on top as on the case */
    const BLOCK = 12;
    /* Cages for a switch without PoE tables: 10G and faster names, and MikroTik's sfp ports */
    const CAGE_NAMES = '/^(xge|xgigabitethernet|25ge|40ge|100ge|te|fo|hu|twe|sfp)/i';
    /* The speed a port's name stands for (usualSpeed()), checked in this order */
    const NAME_SPEEDS = [
        '/^(fa|fastethernet)\d/i' => 100,
        '/^(xge|xgigabitethernet|te|tengigabitethernet)\d/i' => 10000,
        '/^(25ge|twe|twentyfivegige)\d/i' => 25000,
        '/^(40ge|fo|fortygigabitethernet)\d/i' => 40000,
        '/^(100ge|hu|hundredgige)\d/i' => 100000,
        '/^(ge|gi|gigabitethernet)\d/i' => 1000,
    ];
    /* Worst first: a port with several modules shows the first of these that one of them has */
    const SEVERITY = ['fail', 'stale', 'run', 'unknown', 'ok'];
    /* A running module's tile without a percent shows its step */
    const STEP_MARKS = ['preinstall' => 'PRE', 'write' => 'WRITE', 'verify' => 'VERIFY', 'postinstall' => 'POST', 'firstboot' => 'OS'];

    protected $parts = [], $modules = [], $serverMac;

    /**
     * @param array $parts one per switch: ['id' => int, 'name' => string, 'snapshot' => ?array (SwitchSnapshot::take()),
     *        'answering' => bool (its last poll worked), 'own_macs' => string[], 'links' => [ifIndex => id of the
     *        neighbour] found before]
     * @param iterable $modules Cm models; their mac in any case
     */
    public function __construct(array $parts, iterable $modules, ?string $serverMac)
    {
        foreach ($parts as $part)
            $this->parts[$part['id']] = [
                'id' => $part['id'], 'name' => (string) $part['name'], 'snapshot' => $part['snapshot'] ?? null,
                'answering' => $part['answering'] ?? !empty($part['snapshot']),
                'own_macs' => array_map('strtolower', $part['own_macs'] ?? []), 'links' => $part['links'] ?? [],
            ];
        foreach ($modules as $cm)
            if ($cm->mac)
                $this->modules[strtolower($cm->mac)] = $cm;
        $this->serverMac = $serverMac ? strtolower($serverMac) : null;
    }

    /**
     * ['switches' => [id => ['id', 'name', 'blocks', 'composition', 'links' => [ifIndex => id of the neighbour]]],
     *  'tiles' => [key => tile] of every switch, 'summary' => the whole stand,
     *  'owners' => [module MAC => ['key' => its port or null, 'seen' => every port that lists it]],
     *  'blocks' => the first switch's blocks]
     */
    public function build(): array
    {
        $ports = [];
        foreach ($this->parts as $id => $part)
            foreach ($part['snapshot']['ports'] ?? [] as $port)
                $ports['s'.$id.'p'.$port['ifIndex']] = ['switch' => $id, 'port' => $port, 'macs' => array_map('strtolower', $port['macs'])];

        $links = $this->links($ports);
        $uplinks = [];
        foreach ($ports as $key => $p)
            $uplinks[$key] = isset($links[$key]) || $this->hasServer($p)
                || $this->foreign($p) > self::UPLINK_MACS;
        $owners = $this->owners($ports, $uplinks);

        $switches = $tiles = [];
        $allMain = [];
        foreach ($this->parts as $id => $part)
        {
            $mine = array_filter($ports, function ($p) use ($id) { return $p['switch'] === $id; });
            [$blocks, $main, $aside, $withPoe, $panel] = $this->layout($mine, $owners, $links, $uplinks, $part['snapshot']['model'] ?? null);
            foreach (array_merge($main, $aside) as $tile)
                $tiles[$tile['key']] = $tile;
            $allMain = array_merge($allMain, $main);
            $found = [];
            foreach ($mine as $key => $p)
                if (isset($links[$key]))
                    $found[$p['port']['ifIndex']] = $links[$key];
            $switches[$id] = ['id' => $id, 'name' => $part['name'], 'blocks' => $blocks, 'panel' => $panel,
                              'composition' => $main || $aside ? $this->composition($main, $aside, $withPoe) : '', 'links' => $found];
        }
        $first = reset($switches);
        return ['switches' => $switches, 'tiles' => $tiles, 'summary' => $this->summary($allMain, $tiles),
                'owners' => $owners, 'blocks' => $first ? $first['blocks'] : []];
    }

    /* The provisioner is on this port: its MAC is in the switch's table, or the port's neighbour (LLDP) is the provisioner */
    protected function hasServer(array $p): bool
    {
        if (!$this->serverMac)
            return false;
        if (in_array($this->serverMac, $p['macs'], true))
            return true;
        foreach ($p['port']['neighbors'] ?? [] as $neighbour)
            if (($neighbour['mac'] ?? null) === $this->serverMac || ($neighbour['port_mac'] ?? null) === $this->serverMac)
                return true;
        return false;
    }

    /* The port's neighbour (LLDP, CDP) is that switch: it says a MAC the switch answers from, or, when it says no MAC
       (CDP does not), the switch's name. A neighbour that says another MAC is another device, whatever it calls itself
       (a module running lldpd names itself by its host name, and the stand's switches have names too) */
    protected function neighbourIs(array $p, array $other): bool
    {
        $names = array_filter([self::shortName($other['name']), self::shortName((string) ($other['snapshot']['identity']['name'] ?? ''))]);
        foreach ($p['port']['neighbors'] ?? [] as $neighbour)
        {
            $macs = array_filter([$neighbour['mac'] ?? null, $neighbour['port_mac'] ?? null]);
            if ($macs)
            {
                if (array_intersect($macs, $other['own_macs']))
                    return true;
                continue;
            }
            $name = self::shortName((string) ($neighbour['name'] ?? ''));
            if ($name !== '' && in_array($name, $names, true))
                return true;
        }
        return false;
    }

    /* A device name before its domain; an address stays whole */
    protected static function shortName(string $name): string
    {
        $name = strtolower(trim($name));
        return filter_var($name, FILTER_VALIDATE_IP) !== false ? $name : (string) strtok($name, '.');
    }

    /* A port towards another switch of the stand: it shows one of that switch's own MACs (the address it answers from
       is learnt there), or its neighbour says it is that switch, or it was found so before and that switch is silent
       now. A port that shows several switches leads to the nearest of them (nearest()) */
    protected function links(array $ports): array
    {
        $seen = [];   // switch id => [port key => its MACs]: where each switch sees the others
        foreach ($ports as $key => $p)
            if ($p['port']['up'])
                $seen[$p['switch']][$key] = $p['macs'];
        $links = [];
        foreach ($ports as $key => $p)
        {
            if (!$p['port']['up'])
                continue;
            $remembered = $this->parts[$p['switch']]['links'];
            $candidates = [];
            foreach ($this->parts as $id => $other)
                if ($id !== $p['switch'] && (array_intersect($p['macs'], $other['own_macs']) || $this->neighbourIs($p, $other)
                        || (!$other['answering'] && (int) ($remembered[$p['port']['ifIndex']] ?? 0) === $id)))
                    $candidates[] = $id;
            if ($candidates)
                $links[$key] = $this->nearest($p['switch'], $candidates, $seen);
        }
        return $links;
    }

    /* Of the switches one port of $from leads to, the one all the others are behind; the first when none is */
    protected function nearest(int $from, array $candidates, array $seen): int
    {
        foreach ($candidates as $x)
        {
            $nearer = true;
            foreach ($candidates as $y)
                $nearer = $nearer && ($y === $x || $this->behind($y, $x, $from, $seen));
            if ($nearer)
                return $x;
        }
        return $candidates[0];
    }

    /* $y is behind $x, seen from $from: $x sees $y's own MACs on a port where it does not see $from's */
    protected function behind(int $y, int $x, int $from, array $seen): bool
    {
        foreach ($seen[$x] ?? [] as $macs)
            if (array_intersect($macs, $this->parts[$y]['own_macs']) && !array_intersect($macs, $this->parts[$from]['own_macs']))
                return true;
        return false;
    }

    /* Every module MAC seen anywhere: the port it belongs to (the one with fewest addresses; on a tie the one where all
       the others are modules too; otherwise none) and every port that lists it */
    protected function owners(array $ports, array $uplinks): array
    {
        $owners = [];
        foreach ($this->modules as $mac => $cm)
        {
            $seen = $candidates = [];
            foreach ($ports as $key => $p)
                if (in_array($mac, $p['macs'], true))
                {
                    $seen[] = $key;
                    if ($p['port']['up'] && !$uplinks[$key])
                        $candidates[$key] = $p['port']['mac_count'] ?? count($p['macs']);
                }
            if (!$seen)
                continue;
            $owner = null;
            if ($candidates)
            {
                $fewest = array_keys($candidates, min($candidates));
                if (count($fewest) > 1)
                    $fewest = array_values(array_filter($fewest, function ($key) use ($ports) { return $this->foreign($ports[$key]) === 0; }));
                $owner = count($fewest) === 1 ? $fewest[0] : null;
            }
            $owners[$mac] = ['key' => $owner, 'seen' => $seen];
        }
        return $owners;
    }

    /* Addresses on a port that are not modules */
    protected function foreign(array $p): int
    {
        $modules = count(array_filter($p['macs'], function ($mac) { return isset($this->modules[$mac]); }));
        return ($p['port']['mac_count'] ?? count($p['macs'])) - $modules;
    }

    /* Verified models keep the case's geometry. Otherwise group PoE ports (or copper) in blocks of BLOCK. */
    protected function layout(array $mine, array $owners, array $links, array $uplinks, ?string $model): array
    {
        uasort($mine, function ($a, $b) { return strnatcasecmp($a['port']['name'], $b['port']['name']); });
        $withPoe = (bool) array_filter($mine, function ($p) { return $p['port']['poe'] !== null; });

        $main = $aside = [];
        foreach ($mine as $key => $p)
        {
            $port = $p['port'];
            $tile = $this->tile($key, $p, $owners, $links, $uplinks);
            $media = $port['media'] ?? null;
            // the block of uplinks beside the main ports: no PoE on a PoE switch, otherwise a cage or combo port
            $tile['aside'] = $withPoe ? $port['poe'] === null : ($this->cage($port) || in_array($media, ['sfp', 'combo'], true));
            // the connector as the switch tells it (SwitchSnapshot::media()), otherwise a cage beside and copper in the main blocks
            $tile['media'] = $media ?? ($tile['aside'] ? 'sfp' : 'copper');
            $tile['sfp'] = $tile['media'] === 'sfp';
            $tile['combo'] = $tile['media'] === 'combo';
            $tile['transceiver'] = $port['transceiver'] ?? null;
            $tile['mark'] = $this->mark($tile);
            if ($tile['aside'])
                $aside[] = $tile;
            else
                $main[] = $tile;
        }

        $panel = SwitchFrontPanel::resolve($model, array_merge($main, $aside));
        if ($panel)
        {
            $main = $aside = [];
            $blocks = $panel['blocks'];
            foreach ($blocks as &$block)
                foreach ($block['tiles'] as &$tile)
                {
                    $tile['aside'] = $block['uplinks'];
                    $tile['media'] = $block['media'];
                    $tile['sfp'] = $tile['media'] === 'sfp';
                    $tile['combo'] = false;
                    $tile['mark'] = $this->mark($tile);
                    if ($tile['aside'])
                        $aside[] = $tile;
                    else
                        $main[] = $tile;
                }
            unset($block, $tile);
            return [$blocks, $main, $aside, $withPoe, ['model' => $panel['model']]];
        }

        $rows = count($main) > self::BLOCK ? 2 : 1;
        $blocks = [];
        foreach ($rows === 1 ? [$main] : array_chunk($main, self::BLOCK) as $chunk)
            if ($chunk)
                $blocks[] = ['uplinks' => false, 'rows' => $rows, 'tiles' => $chunk];
        if ($aside)
            $blocks[] = ['uplinks' => true, 'rows' => $rows, 'tiles' => $aside];
        return [$blocks, $main, $aside, $withPoe, null];
    }

    /* What the dashboard says about a module, in one word */
    public static function moduleState(Cm $cm): string
    {
        if ($cm->isActive())
            return $cm->silentFor() ? 'stale' : 'run';
        if ($cm->usb_prep_at !== null && $cm->phase === 'done')
            return 'unknown';   // prepared over USB, not provisioned: the stand has nothing to count
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

    /** Why this port's PoE may not be switched off and on (a PoE restart), null when it may: nothing on the port but
        one of our modules, or a Raspberry Pi that has not asked the server yet */
    public static function restartBlocker(array $tile): ?string
    {
        if ($tile['link'] ?? null)
            return $tile['label'].' is the link to '.$tile['link'];
        if ($tile['state'] === 'free')
            return $tile['label'].' has no link';
        if (!$tile['poe'])
            return 'no PoE on '.$tile['label'];
        if ($tile['poe']['state'] === 'disabled')
            return 'PoE is off on '.$tile['label'];   // switched off on purpose, or left off by a restart (Power on)
        if ($tile['state'] === 'uplink')
            return $tile['label'].' is the uplink';
        // one of them may be gone with its MAC still listed: nothing tells which one the power would cut
        if (count($tile['modules']) > 1)
            return count($tile['modules']).' modules on '.$tile['label'];
        // power cut mid-write can leave a bootloader that no longer boots from the network; a module silent on that step may go
        foreach ($tile['modules'] as $cm)
            if (self::writingEeprom($cm) && $cm->isActive() && $cm->silentFor() === null)
                return 'the module on '.$tile['label'].' is writing its bootloader EEPROM';
        // a module that failed the OS check in its new system runs that system: a power cycle boots it again
        foreach ($tile['modules'] as $cm)
            if ($cm->failedInItsSystem())
                return 'the module on '.$tile['label'].' runs its new system: a restart would boot it again, not provision it';
        if (($tile['state'] === 'module' && $tile['others'] === 0) || ($tile['state'] === 'boot' && $tile['macs']))
            return null;
        return 'other devices on '.$tile['label'];
    }

    /** A port's PoE in words, as the module card shows it */
    public static function poeText(?array $poe): string
    {
        if (!$poe)
            return 'none';
        switch ($poe['state'])
        {
            case 'delivering':
                return 'delivering'.($poe['watts'] !== null ? ' '.number_format($poe['watts'], 1).' W' : '')
                    .($poe['class'] !== null ? ', class '.$poe['class'] : '');
            case 'searching':
                return 'looking for a device';
            case 'fault':
                return 'fault';
            case 'disabled':
                return 'off';
            default:
                return $poe['state'];
        }
    }

    protected function tile(string $key, array $p, array $owners, array $links, array $uplinks): array
    {
        $port = $p['port'];
        $macs = $p['macs'];
        // a module MAC belongs to one port of the stand; on the others it is neither a module nor another device
        $own = $elsewhere = [];
        foreach ($macs as $mac)
            if (isset($this->modules[$mac]))
            {
                if (($owners[$mac]['key'] ?? null) === $key)
                    $own[] = $mac;
                else
                    $elsewhere[] = $mac;
            }
        $count = $port['mac_count'] ?? count($macs);
        $others = $count - count($own) - count($elsewhere);
        $macs = array_values(array_diff($macs, $elsewhere));
        $modules = $this->present(array_map(function ($mac) { return $this->modules[$mac]; }, $own));
        usort($modules, function ($a, $b) {
            return array_search(self::moduleState($a), self::SEVERITY, true) - array_search(self::moduleState($b), self::SEVERITY, true);
        });

        if (!$port['up'])
            $state = 'free';   // a pulled module's MAC stays in the switch table for minutes
        elseif ($uplinks[$key])
            $state = 'uplink';
        elseif ($modules)
            $state = 'module';
        elseif ($macs)
            $state = $this->booting($port, $macs) ? 'boot' : 'foreign';
        elseif (($port['changed'] ?? null) !== null && $port['changed'] > self::BOOT_SECONDS)
            $state = 'quiet';   // a LAG member, a silent device, the provisioner's port after a long idle time
        else
            $state = 'boot';

        $filter = $state === 'module' ? self::moduleState($modules[0]) : ($state === 'quiet' ? 'foreign' : $state);
        $part = $this->parts[$p['switch']];
        $boardPort = ($port['alias'] ?? '') !== '' ? $port['alias'] : $port['name'];
        return [
            'key' => $key,
            'switch_id' => $p['switch'],
            'switch' => $part['name'],
            'label' => trim($part['name'].' '.$port['name']),
            'board_port' => $boardPort,
            'board' => trim($part['name'].' '.$boardPort),
            'link' => isset($links[$key]) ? $this->parts[$links[$key]]['name'] : null,
            // what LLDP and CDP say about the device on the port (Neighbors)
            'neighbors' => $port['neighbors'] ?? [],
            // the provisioner itself is on this port (not behind a link to another switch): the HDMI display says "cm" there
            'server' => !isset($links[$key]) && $this->hasServer($p),
            'ifIndex' => $port['ifIndex'],
            'name' => $port['name'],
            'alias' => $port['alias'],
            'number' => preg_match('/(\d+)\D*$/', $port['name'], $m) ? (int) $m[1] : null,
            'up' => $port['up'],
            'speed' => $port['speed'],
            'changed' => $port['changed'] ?? null,
            'poe' => $port['poe'],
            'macs' => $macs,
            'mac_count' => $count,
            'others' => $others,   // addresses that are not modules (a PoE restart needs none)
            'state' => $state,
            'filter' => $filter,
            // a port with several modules answers to the chip of each of their states
            'filters' => $state === 'module' ? array_values(array_unique(array_map([self::class, 'moduleState'], $modules))) : [$filter],
            'modules' => $state === 'module' ? $modules : [],
            // the last four hex digits of the MAC: what a person reads off a switch or a label
            'short' => $state === 'module' ? strtoupper(substr(str_replace(':', '', $modules[0]->mac ?: $modules[0]->serial), -4))
                : (isset($links[$key]) ? '→' : (['uplink' => 'UP', 'foreign' => 'UNR', 'boot' => '···', 'quiet' => 'LNK'][$state] ?? '')),
        ];
    }

    /* A module asks for DHCP before it asks the server: for its first seconds the switch knows its MAC and the server
       does not. Nothing but Raspberry Pi addresses on a link up for BOOT_SECONDS or less is a module booting. A link of
       unknown age is not: a Pi that never asks the server would keep the batch in progress for good. */
    protected function booting(array $port, array $macs): bool
    {
        if (($port['changed'] ?? null) === null || $port['changed'] > self::BOOT_SECONDS)
            return false;
        foreach ($macs as $mac) {
            $hex = preg_replace('/[^0-9a-f]/', '', strtolower($mac));
            $pi = false;
            foreach (self::RASPBERRY_PI_PREFIXES as $prefix)
                $pi = $pi || strncmp($hex, $prefix, strlen($prefix)) === 0;
            if (!$pi)
                return false;
        }
        return true;
    }

    /* The step that flashes the project's bootloader EEPROM (ScriptExecuteController names it "Flash EEPROM firmware") */
    public static function writingEeprom(Cm $cm): bool
    {
        return $cm->phase === 'preinstall' && stripos((string) $cm->phase_detail, 'eeprom') !== false;
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

    /* The line under the serial: ✓, ✕, !, ?, percent, step, or ×N for several modules; a link names where it leads */
    protected function mark(array $tile): string
    {
        if ($tile['link'])
            return $tile['link'];
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
        if (self::writingEeprom($cm))
            return 'EEPROM';
        return self::STEP_MARKS[$cm->phase] ?? '';
    }

    protected function summary(array $main, array $tiles): array
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

        return ['modules' => $modules, 'ports' => count($main), 'counts' => $counts, 'verdict' => $verdict, 'busy' => $busy];
    }

    /* "8 × 1G PoE · 2 × 1G · 2 × SFP": the main ports with their usual speed, then beside them the copper uplinks,
       the cages and the combo ports */
    protected function composition(array $main, array $aside, bool $withPoe): string
    {
        $parts = [];
        if ($main)
        {
            $speed = self::usualSpeed($main);
            $kind = trim(($speed ? self::speedLabel($speed) : '').($withPoe ? ' PoE' : ''));
            $parts[] = $kind !== '' ? count($main).' × '.$kind : count($main).' ports';
        }
        $copper = array_filter($aside, function ($t) { return $t['media'] === 'copper'; });
        if ($copper)
        {
            $speed = self::usualSpeed($copper);
            $parts[] = count($copper).' × '.($speed ? self::speedLabel($speed) : 'RJ45');
        }
        foreach (['sfp' => 'SFP', 'combo' => 'combo'] as $media => $word)
        {
            $count = count(array_filter($aside, function ($t) use ($media) { return $t['media'] === $media; }));
            if ($count)
                $parts[] = $count.' × '.$word;
        }
        return implode(' · ', $parts);
    }

    /* The speed most of these ports are made for: what their names say, then the speed of those with a link (a module
       may link at 100M; Cisco gives a port without a link the speed 10, Huawei 1000), then what most of them report */
    protected static function usualSpeed(array $tiles): ?int
    {
        $common = function (array $speeds) {
            $speeds = array_count_values(array_filter($speeds));
            arsort($speeds);
            return $speeds ? (int) array_key_first($speeds) : null;
        };
        return $common(array_map(function ($t) { return self::nameSpeed($t['name']); }, $tiles))
            ?? $common(array_map(function ($t) { return $t['up'] ? $t['speed'] : null; }, $tiles))
            ?? $common(array_map(function ($t) { return $t['speed']; }, $tiles));
    }

    /* Mbit/s a port's name stands for: "Gi0/9" and "GE0/0/1" 1G, "Te1/1/1" and "XGE0/0/1" 10G; null for "ether1" */
    protected static function nameSpeed(string $name): ?int
    {
        foreach (self::NAME_SPEEDS as $pattern => $speed)
            if (preg_match($pattern, $name) === 1)
                return $speed;
        return null;
    }
}
