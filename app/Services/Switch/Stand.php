<?php

namespace App\Services\Switch;

use App\Models\Cm;
use App\Models\EthernetSwitch;
use App\Services\NetworkStatus;
use App\Support\StandMap;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The stand as the Ports page shows it, for every page that needs it (the Ports page, the module card, a PoE
 * restart): each switch's snapshot (SnapshotCache, shared by all pages, at most FRESH_SECONDS old) and one map of
 * them all (StandMap). Each switch's row keeps what it said about itself and the links found to the others.
 */
class Stand
{
    /* Modules are looked for on ports with at most this many MAC addresses: a desktop switch full of
       modules, not the office network behind an uplink */
    const LOOKUP_MACS = 48;

    /** ['switches' => [id => ['switch' => EthernetSwitch, 'config', 'snapshot', 'error', 'checked_at', 'same_as' => id of the
        row of the same switch, which is in the map instead]], 'map'] (a
        snapshot is null until its switch has answered once, the map until one of them has; checked_at: when the switch
        was last asked), or null without a switch. $notOlderThan and $wait go to SnapshotCache::current(); $extra:
        modules the database does not hold yet (one at its start) */
    public static function current(?int $notOlderThan = null, ?float $wait = null, iterable $extra = []): ?array
    {
        $switches = EthernetSwitch::orderBy('id')->get();
        if ($switches->isEmpty())
            return null;
        $states = [];
        foreach ($switches as $switch)
        {
            $config = $switch->config();
            $state = (new SnapshotCache($config['host'], function () use ($config) {
                return SwitchSnapshot::forConfig($config)->take();
            }))->current($notOlderThan, $wait ?? SnapshotCache::LOCK_WAIT);
            if ($state['snapshot'] && !$state['error'])
                self::remember($switch, $state['snapshot']);
            $states[$switch->id] = ['switch' => $switch, 'config' => $switch->config(), 'snapshot' => $state['snapshot'],
                                    'error' => $state['error'], 'checked_at' => $state['checked_at'], 'busy' => $state['busy'] ?? false];
        }

        // one switch under two addresses (its IP and its name, say) answers with one base MAC: the later row stays out
        // of the map, or every module would tie between their twin ports
        $first = [];
        foreach ($states as $id => $state)
        {
            $mac = $state['snapshot']['identity']['mac'] ?? null;
            $states[$id]['same_as'] = $mac !== null && isset($first[$mac]) ? $first[$mac] : null;
            if ($mac !== null && !isset($first[$mac]))
                $first[$mac] = $id;
        }

        $parts = [];
        foreach ($states as $id => $state)
            if (!$state['same_as'])
                $parts[] = ['id' => $id, 'name' => $state['switch']->hostname(), 'snapshot' => $state['snapshot'],
                            'answering' => self::answering($state), 'own_macs' => $state['switch']->own_macs ?? [],
                            'links' => $state['switch']->links ?? []];
        $snapshots = array_values(array_filter(array_column($parts, 'snapshot')));
        $extra = is_array($extra) ? $extra : iterator_to_array($extra, false);
        $map = $snapshots
            ? (new StandMap($parts, array_merge(self::modulesOn($snapshots), $extra), app(NetworkStatus::class)->serverMac()))->build()
            : null;
        if ($map)
            foreach ($states as $id => $state)
                if (self::answering($state) && isset($map['switches'][$id]))
                    self::rememberLinks($state, $map['switches'][$id]['links']);
        return ['switches' => $states, 'map' => $map];
    }

    /** The switch answered its last poll */
    public static function answering(array $state): bool
    {
        return $state['snapshot'] !== null && $state['error'] === null;
    }

    /** The port a module is on now: the one the map gives its MAC (StandMap::owners()), if the module is among the
        port's modules there. Never an uplink, a port without link or a port where the switch still lists its MAC next
        to the module now plugged in there (StandMap::present()); null when the switches do not show it */
    public static function tileOf(?array $map, Cm $cm): ?array
    {
        $key = $map && $cm->mac ? ($map['owners'][strtolower($cm->mac)]['key'] ?? null) : null;
        $tile = $key !== null ? ($map['tiles'][$key] ?? null) : null;
        if (!$tile)
            return null;
        $serials = array_map(function ($module) { return $module->serial; }, $tile['modules']);
        return in_array($cm->serial, $serials, true) ? $tile : null;
    }

    /** Why a module is not on the map: no switch shows it, and which switches could not be asked */
    public static function notSeen(array $stand): string
    {
        $silent = [];
        foreach ($stand['switches'] as $state)
            if (!self::answering($state))
                $silent[] = $state['switch']->hostname();
        return $silent ? 'not seen on the switches ('.implode(', ', $silent).' '.(count($silent) === 1 ? 'does' : 'do').' not answer)'
                       : 'not seen on the switch';
    }

    /* A link is found again while both switches answer; while its neighbour is silent the map keeps it from here. One
       snapshot without the neighbour's MAC on it does not forget it (a neighbour just back is learnt there with its
       next answers), the next one does, and so does the port's link going down */
    protected static function rememberLinks(array $state, array $found): void
    {
        $switch = $state['switch'];
        $takenAt = (int) $state['snapshot']['taken_at'];
        $up = array_column($state['snapshot']['ports'], 'up', 'ifIndex');
        $links = $found;
        foreach ($switch->links ?? [] as $ifIndex => $neighbour)
        {
            $miss = 'switch-link-miss:'.$switch->id.':'.$ifIndex;
            if (isset($found[$ifIndex]) || !($up[$ifIndex] ?? false))
            {
                Cache::forget($miss);
                continue;
            }
            $first = Cache::get($miss);
            if ($first === null || $first >= $takenAt)
            {
                Cache::put($miss, $first ?? $takenAt, 3600);
                $links[$ifIndex] = (int) $neighbour;
            }
            else
                Cache::forget($miss);
        }
        ksort($links);
        if ($links != ($switch->links ?? []))
            $switch->forceFill(['links' => $links])->save();
    }

    /* What the switch said about itself; a base MAC another row holds already stays unset (two rows for one switch) */
    protected static function remember(EthernetSwitch $switch, array $snapshot): void
    {
        $name = $snapshot['identity']['name'] ?? null;
        if ($name !== null)
            $switch->sys_name = $name;
        if (isset($snapshot['own_macs']))
            $switch->own_macs = $snapshot['own_macs'];
        if ($snapshot['mac_method'] ?? null)
            $switch->method_detected = $snapshot['mac_method'];
        $mac = $snapshot['identity']['mac'] ?? null;
        if ($mac && $mac !== $switch->base_mac && !EthernetSwitch::where('base_mac', $mac)->whereKeyNot($switch->id)->exists())
            $switch->base_mac = $mac;
        if ($switch->isDirty())
            $switch->save();
    }

    /* Modules behind ports with a link and few enough addresses to be devices, not uplinks */
    protected static function modulesOn(array $snapshots)
    {
        $macs = [];
        foreach ($snapshots as $snapshot)
            foreach ($snapshot['ports'] as $port)
                if ($port['up'] && ($port['mac_count'] ?? count($port['macs'])) <= self::LOOKUP_MACS)
                    foreach ($port['macs'] as $mac)
                        $macs[strtolower($mac)] = true;
        $modules = [];
        foreach (array_chunk(array_keys($macs), 500) as $chunk)
            foreach (Cm::whereIn(DB::raw('lower(mac)'), $chunk)->get() as $cm)
                $modules[] = $cm;
        return $modules;
    }
}
