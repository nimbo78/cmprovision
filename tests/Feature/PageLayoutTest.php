<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every page of the app sits in the compact frame (cmp-page: the page margins, as wide as the window) */
class PageLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_is_in_the_compact_frame()
    {
        $user = User::factory()->create();
        foreach (['/dashboard', '/cms', '/ports', '/images', '/projects', '/scripts', '/labels', '/firmware', '/settings',
                  '/user/profile', '/user/api-tokens'] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/class="[^"]*\bcmp-page\b/', $html, $url);
            $this->assertStringNotContainsString('max-w-7xl', $html, $url);
        }
    }
}
