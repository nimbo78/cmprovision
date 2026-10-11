<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Raspberry Pi logo stands in for Jetstream's; publishing a newer Jetstream's views brings Jetstream's back.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    // the red of the raspberry in the logo's SVG
    const RASPBERRY = 'fill:rgb(73.72549%,6.666667%,25.882353%)';

    public function test_the_login_page_shows_the_raspberry_pi_logo()
    {
        $this->get('/login')->assertOk()->assertSee(self::RASPBERRY, false);
    }

    public function test_the_top_bar_shows_the_raspberry_pi_logo()
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertOk()
            ->assertSee(self::RASPBERRY, false);
    }
}
