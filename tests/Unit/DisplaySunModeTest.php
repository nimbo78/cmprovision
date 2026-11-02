<?php

namespace Tests\Unit;

use App\Support\DisplaySunMode;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class DisplaySunModeTest extends TestCase
{
    public function test_uses_same_sun_events_as_the_web_appearance()
    {
        $moscow = ['lat' => 55.7558, 'lng' => 37.6178];
        $sun = DisplaySunMode::times(new DateTimeImmutable('2026-10-12T09:00:00Z'), $moscow);
        $this->assertEqualsWithDelta(strtotime('2026-10-12T03:53:00Z'), $sun['sunrise'], 120);
        $this->assertEqualsWithDelta(strtotime('2026-10-12T14:38:00Z'), $sun['sunset'], 120);
        $this->assertSame('light', DisplaySunMode::auto(new DateTimeImmutable('2026-10-12T09:00:00Z'), $moscow));
        $this->assertSame('dark', DisplaySunMode::auto(new DateTimeImmutable('2026-10-12T15:00:00Z'), $moscow));
        $this->assertSame('dark', DisplaySunMode::auto(new DateTimeImmutable('2026-10-12T02:00:00Z'), $moscow));
    }

    public function test_midnight_crossing_and_dark_fallback_without_sun()
    {
        $east = ['lat' => 43.1333, 'lng' => 131.9];
        $this->assertSame('light', DisplaySunMode::auto(new DateTimeImmutable('2026-10-12T02:00:00Z'), $east));
        $this->assertSame('dark', DisplaySunMode::auto(new DateTimeImmutable('2026-10-12T13:00:00Z'), $east));
        $night = new DateTimeImmutable('2026-12-20T09:00:00Z');
        $this->assertNull(DisplaySunMode::times($night, ['lat' => 68.9667, 'lng' => 33.0833]));
        $this->assertSame('dark', DisplaySunMode::auto($night, ['lat' => 68.9667, 'lng' => 33.0833]));
        $this->assertSame('dark', DisplaySunMode::auto($night, null));
    }
}
