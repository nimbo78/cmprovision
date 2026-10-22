<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 2.6.2: below 640 px the menu lists every page of the top bar, in its order, the current one marked */
class PhoneMenuTest extends TestCase
{
    use RefreshDatabase;

    /** The links of one block of the top bar, the one whose opening tag has $marker: href => is it the current page */
    protected function links(string $html, string $marker): array
    {
        $this->assertSame(1, preg_match('#<div [^>]*'.preg_quote($marker, '#').'[^>]*>(.*?)</div>#s', $html, $block), 'no block with '.$marker);
        preg_match_all('#<a ([^>]*)>#', $block[1], $tags);
        $links = [];
        foreach ($tags[1] as $attributes)
        {
            preg_match('#href="([^"]*)"#', $attributes, $href);
            $links[html_entity_decode($href[1])] = str_contains($attributes, 'aria-current="page"');
        }
        return $links;
    }

    public function test_the_phone_menu_lists_every_page_of_the_bar_in_its_order()
    {
        $html = $this->actingAs(User::factory()->create())->get('/firmware')->assertOk()->getContent();

        $bar = $this->links($html, 'class="cmp-nav');
        $phone = $this->links($html, 'data-menu="phone"');

        $this->assertCount(10, $bar);
        $this->assertArrayHasKey(route('diagnostics'), $bar);
        $this->assertSame(array_keys($bar), array_keys($phone), 'the same pages in the same order');
        $this->assertSame([route('firmware')], array_keys(array_filter($bar)), 'the bar marks the current page');
        $this->assertSame([route('firmware')], array_keys(array_filter($phone)), 'and so does the phone menu, only it');
    }

    public function test_the_phone_menu_keeps_the_account()
    {
        $html = $this->actingAs(User::factory()->create(['name' => 'igor']))->get(route('dashboard'))->assertOk()->getContent();

        $at = strpos($html, 'data-menu="phone"');
        $this->assertNotFalse($at, 'the phone menu is there');
        $phone = substr($html, $at);   // the bar's own account menu comes before it
        $this->assertStringContainsString(route('profile.show'), $phone);
        $this->assertStringContainsString(route('logout'), $phone);
        $this->assertStringContainsString('igor', $phone);
    }
}
