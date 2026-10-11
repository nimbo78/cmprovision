<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A session whose password changed elsewhere (another browser, "Log out other browser sessions",
 * auth:create-user) is ended by AuthenticateSession; the browser must land on the login page,
 * not on an empty 401 (Laravel 11+ renders one when no guest redirect is configured).
 */
class SignedOutSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_session_whose_password_changed_elsewhere_goes_to_the_login_page()
    {
        $user = User::factory()->create();
        // the first request stores the password hash in the session
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->forceFill(['password' => bcrypt('changed-elsewhere')])->save();

        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_an_api_client_still_gets_json()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->forceFill(['password' => bcrypt('changed-elsewhere')])->save();

        $this->getJson('/dashboard')->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
    }
}
