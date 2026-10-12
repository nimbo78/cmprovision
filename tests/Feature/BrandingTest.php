<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * cmprovision's own mark (docs/brand) stands in for Jetstream's logo; publishing a newer Jetstream's views brings
 * Jetstream's back.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    // the tile's gradient and the line's ink, as theme tokens (a lighter tile in a dark theme)
    const TILE_TOP = 'stop-color: var(--mark-a)';
    const TILE_BOTTOM = 'stop-color: var(--mark-b)';
    const INK = 'stroke: var(--mark-ink)';
    // the red of the Raspberry Pi logo the fork showed before 2.1.1
    const RASPBERRY = 'fill:rgb(73.72549%,6.666667%,25.882353%)';

    public function test_the_sign_in_page_shows_the_mark_and_the_name()
    {
        $this->get('/login')->assertOk()
            ->assertSee(self::TILE_TOP, false)
            ->assertSee(self::TILE_BOTTOM, false)
            ->assertSee(self::INK, false)
            ->assertSeeText('cmprovision')   // the name beside the mark labels the link; the mark itself is hidden from screen readers
            ->assertDontSee(self::RASPBERRY, false);
    }

    public function test_the_top_bar_shows_the_mark()
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()
            ->assertSee(self::TILE_TOP, false)
            ->assertSee(self::INK, false)
            ->assertSee('role="img" aria-label="cmprovision"', false)
            ->assertDontSee(self::RASPBERRY, false);
    }

    public function test_the_browser_tab_and_home_screen_icons_are_the_mark()
    {
        $svg = file_get_contents(public_path('favicon.svg'));
        $this->assertStringContainsString('stop-color="#ffd37a"', $svg);   // the tab does not follow the page's theme
        $this->assertStringNotContainsString(self::RASPBERRY, $svg);
        // the .ico holds 16, 32 and 48 px; phones take a 180 px PNG for the home screen
        $this->assertSame([0, 1, 3], array_values(unpack('v3', file_get_contents(public_path('favicon.ico')))));
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('apple-touch-icon.png')), 0, 2));
        $this->get('/login')->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png', false);
    }
}
