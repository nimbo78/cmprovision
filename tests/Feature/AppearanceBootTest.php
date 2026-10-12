<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every page applies the stored appearance in <head>, before it paints, and knows where Auto takes its sun from */
class AppearanceBootTest extends TestCase
{
    use RefreshDatabase;

    protected function assertAppliedInHead(string $html)
    {
        $script = strpos($html, '<script src="'.asset('js/appearance.js'));
        $this->assertNotFalse($script, 'appearance.js is on the page');
        $this->assertLessThan(strpos($html, '<body'), $script, 'appearance.js runs in <head>, before the page paints');
    }

    public function test_the_sign_in_page_applies_it()
    {
        config(['app.display_timezone' => 'Europe/Moscow']);
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertAppliedInHead($html);
        $this->assertStringContainsString('data-sun="55.7558,37.6178" data-sun-city="Moscow" data-sun-zone="Europe/Moscow"', $html);
    }

    public function test_the_app_pages_apply_it()
    {
        config(['app.display_timezone' => 'Europe/Moscow']);
        $html = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->getContent();
        $this->assertAppliedInHead($html);
        $this->assertStringContainsString('data-sun-city="Moscow"', $html);
    }

    public function test_a_server_on_utc_leaves_auto_to_the_system_setting()
    {
        config(['app.display_timezone' => 'UTC']);
        $this->get('/login')->assertOk()->assertDontSee('data-sun=', false);
    }
}
