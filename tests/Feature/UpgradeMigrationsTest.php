<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Upgrading from 1.6 adds what Sanctum 4 and Fortify's confirmed two-factor setup read. */
class UpgradeMigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_tokens_can_expire()
    {
        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'expires_at'));
    }

    public function test_two_factor_setup_can_be_confirmed()
    {
        $this->assertTrue(Schema::hasColumn('users', 'two_factor_confirmed_at'));
    }

    public function test_two_factor_login_set_up_before_the_upgrade_stays_on()
    {
        // a 1.6 database: two-factor secrets, but no confirmation column yet
        $this->artisan('migrate:rollback', ['--step' => 1])->assertExitCode(0);
        $this->assertFalse(Schema::hasColumn('users', 'two_factor_confirmed_at'));
        $withTwoFactor = User::factory()->create(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')]);
        $withoutTwoFactor = User::factory()->create();

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertNotNull($withTwoFactor->fresh()->two_factor_confirmed_at);
        $this->assertNull($withoutTwoFactor->fresh()->two_factor_confirmed_at);
    }
}
