<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The browser tab shows the application mark (public/favicon.ico used to be an empty file). */
class FaviconTest extends TestCase
{
    public function test_pages_link_a_real_icon()
    {
        $this->get('/login')->assertOk()->assertSee('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', false);

        $ico = file_get_contents(public_path('favicon.ico'));
        $this->assertSame("\0\0\1\0", substr($ico, 0, 4), 'an ICO header');
        $this->assertGreaterThan(1000, strlen($ico));
        $this->assertStringContainsString('<svg', file_get_contents(public_path('favicon.svg')));
    }
}
