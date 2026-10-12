<?php

namespace App\Support;

use DateTimeZone;

/**
 * The place of the server's display time zone (its reference city, as the tz database gives it): the Auto theme
 * is light from sunrise to sunset there (public/js/appearance.js). A zone without a place (UTC) gives null, and
 * Auto then follows the browser's system setting.
 */
class SunPlace
{
    public static function forZone(?string $zone): ?array
    {
        if ($zone === null || $zone === '')
            return null;
        try {
            $location = (new DateTimeZone($zone))->getLocation();
        } catch (\Exception $e) {
            return null;
        }
        if (!$location || ($location['country_code'] ?? '??') === '??' || ($location['latitude'] == 0 && $location['longitude'] == 0))
            return null;
        $parts = explode('/', $zone);
        return ['lat' => round($location['latitude'], 4), 'lng' => round($location['longitude'], 4),
                'city' => str_replace('_', ' ', end($parts)), 'zone' => $zone];
    }
}
