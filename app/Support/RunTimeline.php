<?php

namespace App\Support;

use App\Models\Cm;
use Illuminate\Support\Carbon;

/* The steps of a module's last run for the module card, from the timeline Cm::setPhase keeps */
class RunTimeline
{
    /* Per step: phase, label, detail (script name or failure reason), at, seconds until the next step
       (null for the last one) and speed (bytes per second of the image write or verification, else null).
       An entry that a step of the same phase replaced within the same second is left out: the server
       moves on to the next phase, and the module names the script a moment later. */
    public static function steps(Cm $cm)
    {
        $entries = array_values($cm->timeline ?: []);
        $steps = [];
        foreach ($entries as $i => $entry)
        {
            $next = $entries[$i + 1] ?? null;
            $seconds = $next ? max(0, $next['at'] - $entry['at']) : null;
            if ($next && $seconds === 0 && $next['phase'] === $entry['phase'])
                continue;

            $speed = null;
            $resumed = isset($entry['from']);   // the write went on after a restart from this byte (2.6.1)
            if ($seconds && ($entry['detail'] === null || $resumed) && in_array($entry['phase'], ['write', 'verify'], true)
                && !self::goesOnLater($entries, $i))
            {
                /* a step cut short by a failure got as far as the byte counter; otherwise the whole image */
                $bytes = ($next['phase'] === 'failed' ? (int) $cm->progress_bytes : (int) $cm->progress_total) - ($resumed ? $entry['from'] : 0);
                $speed = $bytes > 0 ? $bytes / $seconds : null;
            }

            $steps[] = [
                'phase' => $entry['phase'],
                'label' => Cm::PHASE_LABELS[$entry['phase']] ?? $entry['phase'],
                'detail' => $entry['detail'],
                'at' => Carbon::createFromTimestamp($entry['at']),
                'seconds' => $seconds,
                'speed' => $speed,
            ];
        }
        return $steps;
    }

    /* A write that went on after a restart follows entry $i: how far the run of entry $i got is not known */
    protected static function goesOnLater(array $entries, int $i): bool
    {
        foreach (array_slice($entries, $i + 1) as $later)
            if (isset($later['from']))
                return true;
        return false;
    }
}
