<?php

namespace App\Support;

use App\Http\Livewire\Settings;
use App\Services\NetworkStatus;
use App\Services\ServiceState;
use Illuminate\Support\Facades\Cache;

/* The provisioner itself for the front panel's Station: its links, the uplink of the management network with the quality
   of its Wi-Fi, its services and its free space. Names of NetworkManager profiles and keys never get here. */
class DisplayStation
{
    const CACHE_KEY = 'display-station-v2';
    /* less free space than this is told in yellow: an image download or two more and it runs out */
    const LOW_STORAGE = 2 * 1073741824;

    public static function current(): array
    {
        return Cache::remember(self::CACHE_KEY, 30, function () {
            $network = app(NetworkStatus::class)->current(30) ?? [];
            $management = $network['uplink']['addresses'][0] ?? null;
            $link = $network['modules'] ?? null;
            $free = @disk_free_space(storage_path());
            return [
                'management' => $management ? explode('/', $management)[0] : null,
                'provisioning' => $link ? $link['name'].' '.($link['up'] ? 'up' : 'down') : null,
                'storage' => $free === false ? null : round($free / 1073741824, 1).' GiB free',
                'storage_low' => $free !== false && $free < self::LOW_STORAGE,
                'uplink' => self::uplink($network),
                'links' => array_map(fn ($l) => ['name' => $l['name'], 'kind' => $l['kind'] ?? 'other', 'role' => $l['role'] ?? 'other',
                    'up' => (bool) ($l['up'] ?? false), 'speed' => $l['speed'] ?? null,
                    'address' => isset($l['addresses'][0]) ? explode('/', $l['addresses'][0])[0] : null], $network['links'] ?? []),
                'services' => self::services(),
            ];
        });
    }

    /* How the management network is reached: wifi (with the quality the uplink indicator of the web UI shows), wired,
       wifi_down (a wireless interface, no uplink) or none */
    protected static function uplink(array $network): array
    {
        $up = $network['uplink'] ?? null;
        $wifi = $up['wifi'] ?? null;
        // a Wi-Fi that carries the default route is up even when iw could not tell its quality (timeout, no binary)
        $state = $up === null ? (empty($network['wireless']) ? 'none' : 'wifi_down')
            : (($up['kind'] ?? null) === 'wifi' ? 'wifi' : 'wired');
        $signal = $wifi['signal'] ?? null;
        return [
            'state' => $state,
            'device' => $up['name'] ?? ($network['wireless'][0] ?? null),
            'address' => isset($up['addresses'][0]) ? explode('/', $up['addresses'][0])[0] : null,
            'ssid' => $wifi['ssid'] ?? null,
            'signal' => $signal,
            'quality' => $signal !== null ? WifiLink::quality($signal) : null,
            'bars' => $signal !== null ? WifiLink::bars($signal) : null,
            'band' => $wifi['band'] ?? null,
            'generation' => $wifi['generation'] ?? null,
            'rate' => isset($wifi['tx']) ? (int) round($wifi['tx']) : null,
            'speed' => $state === 'wired' ? ($up['speed'] ?? null) : null,
            'spare_active' => ($network['spare']['state'] ?? null) === 'active',
        ];
    }

    /* The systemd units of Settings → Status: how many run, and the short names of those that do not */
    protected static function services(): array
    {
        $state = app(ServiceState::class);
        $up = $total = 0;
        $stopped = [];
        foreach (Settings::SERVICES as [, $unit, $short])
        {
            if ($unit === null)
                continue;
            $total++;
            if ($state->isActive($unit))
                $up++;
            else
                $stopped[] = $short;
        }
        return ['up' => $up, 'total' => $total, 'stopped' => $stopped];
    }
}
