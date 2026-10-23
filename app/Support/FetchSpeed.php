<?php

namespace App\Support;

use App\Models\Image;
use Illuminate\Support\Facades\Cache;

/* How fast a fetch runs, from the bytes it had when the page last asked (every few seconds), smoothed with the speed before */
class FetchSpeed
{
    /* a sample is taken at most this often: the page asks every 5 seconds, but a second visitor must not make the numbers jump */
    const EVERY = 2.0;

    public static function note(Image $image, ?float $now = null): ?float
    {
        $now = $now ?? microtime(true);
        $key = 'image-speed:'.$image->id;
        $bytes = (int) $image->download_bytes;
        $last = Cache::get($key);
        if (!$last) {
            Cache::put($key, ['t' => $now, 'b' => $bytes, 'v' => null], 600);

            return null;
        }
        if ($now - $last['t'] < self::EVERY || $bytes < $last['b']) {
            if ($bytes < $last['b']) {
                Cache::put($key, ['t' => $now, 'b' => $bytes, 'v' => null], 600);   // started again from less: the old speed means nothing

                return null;
            }

            return $last['v'];
        }
        $speed = ($bytes - $last['b']) / ($now - $last['t']);
        if ($last['v'] !== null) {
            $speed = ($speed + $last['v']) / 2;
        }
        Cache::put($key, ['t' => $now, 'b' => $bytes, 'v' => $speed], 600);

        return $speed;
    }
}
