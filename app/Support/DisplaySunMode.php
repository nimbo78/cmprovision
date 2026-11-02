<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/** The web UI's UTC-day sunrise calculation, shared by the unattended local displays. */
class DisplaySunMode
{
    private static function mod(float $value, float $base): float
    {
        return fmod(fmod($value, $base) + $base, $base);
    }

    private static function eventHours(int $day, float $lat, float $lng, bool $rising): ?float
    {
        $lngHour = $lng / 15;
        $t = $day + (($rising ? 6 : 18) - $lngHour) / 24;
        $m = 0.9856 * $t - 3.289;
        $l = self::mod($m + 1.916 * sin(deg2rad($m)) + 0.02 * sin(deg2rad(2 * $m)) + 282.634, 360);
        $ra = self::mod(rad2deg(atan(0.91764 * tan(deg2rad($l)))), 360);
        $ra = ($ra + floor($l / 90) * 90 - floor($ra / 90) * 90) / 15;
        $sinDec = 0.39782 * sin(deg2rad($l));
        $cosDec = cos(asin($sinDec));
        $cosH = (cos(deg2rad(90.833)) - $sinDec * sin(deg2rad($lat))) / ($cosDec * cos(deg2rad($lat)));
        if ($cosH > 1 || $cosH < -1)
            return null;
        $h = ($rising ? 360 - rad2deg(acos($cosH)) : rad2deg(acos($cosH))) / 15;
        return self::mod($h + $ra - 0.06571 * $t - 6.622 - $lngHour, 24);
    }

    /** UTC-day events match public/js/appearance.js; null means no location or polar day/night. */
    public static function times(DateTimeImmutable $now, ?array $place): ?array
    {
        if ($place === null)
            return null;
        $utc = $now->setTimezone(new DateTimeZone('UTC'));
        $day = (int) $utc->format('z') + 1;
        $rise = self::eventHours($day, $place['lat'], $place['lng'], true);
        $set = self::eventHours($day, $place['lat'], $place['lng'], false);
        if ($rise === null || $set === null)
            return null;
        $midnight = $utc->setTime(0, 0)->getTimestamp();
        return ['sunrise' => $midnight + $rise * 3600, 'sunset' => $midnight + $set * 3600];
    }

    /** An unattended screen has no browser/OS appearance preference: use dark when the web algorithm has no sun. */
    public static function auto(DateTimeImmutable $now, ?array $place): string
    {
        $sun = self::times($now, $place);
        if ($sun === null)
            return 'dark';
        $time = (float) $now->format('U.u');
        $day = $sun['sunrise'] < $sun['sunset']
            ? $time >= $sun['sunrise'] && $time < $sun['sunset']
            : $time >= $sun['sunrise'] || $time < $sun['sunset'];
        return $day ? 'light' : 'dark';
    }
}
