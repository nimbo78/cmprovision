<?php

namespace App\Support;

use App\Services\NetworkStatus;
use Illuminate\Support\Facades\Cache;

class DisplayStation
{
    public static function current(): array
    {
        return Cache::remember('display-station-v1', 30, function () {
            $network = app(NetworkStatus::class)->current(30);
            $management = $network['uplink']['addresses'][0] ?? null;
            $link = $network['modules'] ?? null;
            $free = @disk_free_space(storage_path());
            return [
                'management' => $management ? explode('/', $management)[0] : null,
                'provisioning' => $link ? $link['name'].' '.($link['up'] ? 'up' : 'down') : null,
                'storage' => $free === false ? null : round($free / 1073741824, 1).' GiB free',
            ];
        });
    }
}
