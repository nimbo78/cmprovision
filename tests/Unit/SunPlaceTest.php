<?php

namespace Tests\Unit;

use App\Support\SunPlace;
use PHPUnit\Framework\TestCase;

/** Where the server's display time zone points: the city whose sunrise and sunset drive the Auto theme */
class SunPlaceTest extends TestCase
{
    public function test_a_city_zone_gives_its_place()
    {
        $this->assertSame(['lat' => 55.7558, 'lng' => 37.6178, 'city' => 'Moscow', 'zone' => 'Europe/Moscow'], SunPlace::forZone('Europe/Moscow'));
    }

    public function test_underscores_in_the_city_become_spaces()
    {
        $this->assertSame('New York', SunPlace::forZone('America/New_York')['city']);
    }

    public function test_utc_unknown_and_empty_zones_have_no_place()
    {
        $this->assertNull(SunPlace::forZone('UTC'));
        $this->assertNull(SunPlace::forZone('Not/A_Zone'));
        $this->assertNull(SunPlace::forZone(''));
        $this->assertNull(SunPlace::forZone(null));
    }
}
