<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two-factor login is on once the setup is confirmed with a code (Fortify's 'confirm' option); the
 * upgrade migration confirms setups made under 1.6, so those users keep being asked for the code.
 */
class TwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithTwoFactor(array $attributes = [])
    {
        return User::factory()->create(array_merge([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['rehearsal-code'])),
            'two_factor_confirmed_at' => now(),
        ], $attributes));
    }

    public function test_a_confirmed_setup_asks_for_the_code()
    {
        $user = $this->userWithTwoFactor();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
    }

    public function test_an_unconfirmed_setup_does_not_lock_the_user_out()
    {
        $user = $this->userWithTwoFactor(['two_factor_confirmed_at' => null]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }
}
