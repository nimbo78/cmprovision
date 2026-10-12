<?php

namespace App\Services\Switch;

use App\Models\Cm;
use App\Services\NetworkStatus;
use App\Services\SwitchPortFinder;
use App\Support\StandMap;
use Illuminate\Support\Facades\DB;

/**
 * The stand as the Ports page shows it, for every page that needs it (the Ports page, the module card, a PoE
 * restart): the switch's snapshot (SnapshotCache, shared by all pages, at most FRESH_SECONDS old) and its map.
 */
class Stand
{
    /* Modules are looked for on ports with at most this many MAC addresses: a desktop switch full of
       modules, not the office network behind an uplink */
    const LOOKUP_MACS = 48;

    /** ['config' => SwitchPortFinder::config(), 'snapshot', 'error', 'map'] (snapshot and map are null until the
        switch has answered once), or null without a switch */
    public static function current(): ?array
    {
        $config = SwitchPortFinder::config();
        if (!$config['host'])
            return null;
        $state = (new SnapshotCache($config['host'], function () use ($config) {
            return SwitchSnapshot::forConfig($config)->take();
        }))->current();
        $map = $state['snapshot']
            ? (new StandMap($state['snapshot'], self::modulesOn($state['snapshot']), app(NetworkStatus::class)->serverMac()))->build()
            : null;
        return ['config' => $config, 'snapshot' => $state['snapshot'], 'error' => $state['error'], 'map' => $map];
    }

    /** The port a module is on now, by its MAC: never an uplink, and the port with the fewest addresses when the
        switch shows the MAC on several; null when the switch does not show it */
    public static function tileOf(?array $map, Cm $cm): ?array
    {
        if (!$map || !$cm->mac)
            return null;
        $mac = strtolower($cm->mac);
        $found = null;
        foreach ($map['tiles'] as $tile)
            if ($tile['state'] !== 'uplink' && in_array($mac, $tile['macs'], true)
                && (!$found || $tile['mac_count'] < $found['mac_count']))
                $found = $tile;
        return $found;
    }

    /* Modules behind ports with a link and few enough addresses to be devices, not uplinks */
    protected static function modulesOn(array $snapshot)
    {
        $macs = [];
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
