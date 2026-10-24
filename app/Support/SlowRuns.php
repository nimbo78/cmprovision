<?php

namespace App\Support;

use App\Models\Cm;
use Illuminate\Support\Collection;

/**
 * A module that takes clearly longer than the rest of its batch (2.19.0): a slow card, a bad cable, a stuck download.
 * The typical time is the median of the batch's finished runs, so one run that took an hour does not raise it; with
 * fewer than MIN_FINISHED finished there is nothing to compare with. A running module is slow once it has been going on
 * longer than FACTOR times the typical time and longer than the typical time plus MARGIN_SECONDS: a fast batch
 * (a minute per module) does not flag a module for a few seconds, a slow one (a quarter of an hour) not for a minute.
 * A module that stopped reporting is not slow, it has its own flag ("no report").
 */
class SlowRuns
{
    const MIN_FINISHED = 3;
    const FACTOR = 1.5;
    const MARGIN_SECONDS = 120;

    /* The median of the seconds the finished runs of these modules took, null with fewer than MIN_FINISHED of them.
       A board that only had its bootloader set over USB (USB prep) is not a run of the project */
    public static function typical(Collection $modules): ?int
    {
        $seconds = $modules->filter(function (Cm $cm) {
            return $cm->phase === 'done' && $cm->usb_prep_at === null && $cm->provisioning_started_at && $cm->provisioning_complete_at;
        })->map(function (Cm $cm) {
            return $cm->provisioning_complete_at->getTimestamp() - $cm->provisioning_started_at->getTimestamp();
        })->filter(function ($s) { return $s > 0; })->sort()->values();
        $count = $seconds->count();
        if ($count < self::MIN_FINISHED)
            return null;
        $middle = intdiv($count, 2);
        return $count % 2 ? (int) $seconds[$middle] : (int) round(($seconds[$middle - 1] + $seconds[$middle]) / 2);
    }

    /* How many seconds the module's run is past the typical time, null when it is not slow (or not running and reporting) */
    public static function over(Cm $cm, ?int $typical): ?int
    {
        if ($typical === null || !$cm->isRunning() || $cm->silentFor() !== null)
            return null;
        $elapsed = now()->getTimestamp() - $cm->provisioning_started_at->getTimestamp();
        return $elapsed > max($typical * self::FACTOR, $typical + self::MARGIN_SECONDS) ? $elapsed - $typical : null;
    }

    /* The slow ones among $modules: [module id => seconds past the typical time] */
    public static function among(Collection $modules, ?int $typical): array
    {
        $slow = [];
        foreach ($modules as $cm)
            if (($over = self::over($cm, $typical)) !== null)
                $slow[$cm->id] = $over;
        return $slow;
    }
}
