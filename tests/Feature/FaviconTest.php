<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The browser tab shows the application mark (public/favicon.ico used to be an empty file). */
class FaviconTest extends TestCase
{
    public function test_pages_link_a_real_icon()
    {
        // the file's time in the address: a new icon gets a new address, and browsers drop the one they cached
        $this->get('/login')->assertOk()->assertSee(
            '<link rel="icon" href="/favicon.svg?v='.filemtime(public_path('favicon.svg')).'" type="image/svg+xml" id="cmp-icon-svg">', false);

        $ico = file_get_contents(public_path('favicon.ico'));
        $this->assertSame("\0\0\1\0", substr($ico, 0, 4), 'an ICO header');
        $this->assertGreaterThan(1000, strlen($ico));
        $this->assertStringContainsString('<svg', file_get_contents(public_path('favicon.svg')));
    }

    public function test_the_tab_has_an_icon_for_each_state_of_the_batch()
    {
        foreach (['run' => '#2563eb', 'fail' => '#dc2626', 'done' => '#16a34a'] as $state => $dot) {
            $svg = file_get_contents(public_path("favicon-$state.svg"));
            $this->assertStringContainsString('stop-color="#ffd37a"', $svg, "$state: the mark's tile");
            $this->assertStringContainsString($dot, $svg, "$state: the dot");
            $this->assertSame([32, 32], array_slice(getimagesize(public_path("favicon-$state.png")), 0, 2));
        }
    }
}
