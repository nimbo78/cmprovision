<?php

namespace Tests\Feature;

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
}
