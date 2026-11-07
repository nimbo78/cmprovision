<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Configuration\WizardSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WizardSecretsTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_state_is_encrypted_and_cannot_follow_another_signed_in_user(): void
    {
        $this->actingAs(User::factory()->create());
        $secrets = app(WizardSecrets::class);
        $token = $secrets->start();
        $secrets->put($token, ['password' => 'wizard-only-private-secret']);
        $this->assertStringNotContainsString('wizard-only-private-secret', session('configuration_wizards.'.$token));
        $this->assertSame('wizard-only-private-secret', $secrets->get($token)['password']);
        $this->actingAs(User::factory()->create());
        $this->expectException(\RuntimeException::class);
        $secrets->get($token);
    }

    public function test_a_wizard_expires_after_twenty_four_hours(): void
    {
        $this->actingAs(User::factory()->create());
        $secrets = app(WizardSecrets::class);
        $token = $secrets->start();
        $this->travel(25)->hours();
        $this->expectException(\RuntimeException::class);
        $secrets->get($token);
    }
}
