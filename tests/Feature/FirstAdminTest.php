<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FirstAdmin;
use App\Services\FirstAdminReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FirstAdminTest extends TestCase
{
    use RefreshDatabase;

    private function form(): array
    {
        return ['setup_code' => 'ABCD EFGH IJKL MNOP', 'email' => 'operator@example.org',
            'name' => 'Operator', 'password' => 'valid-password123', 'password_confirmation' => 'valid-password123'];
    }

    public function test_first_visit_requires_code_then_authenticates_and_opens_wizard(): void
    {
        $this->mock(FirstAdmin::class, function ($mock) {
            $mock->shouldReceive('state')->andReturn('armed');
            $mock->shouldReceive('claim')->once()->andReturnUsing(function ($code, $name, $email, $hash) {
                $this->assertTrue(Hash::check($this->form()['password'], $hash));
                return ['state' => 'claimed', 'id' => User::create(compact('name', 'email') + ['password' => $hash])->id];
            });
        });
        $this->get('/login')->assertRedirect('/setup');
        $this->get('/setup')->assertOk()->assertSee('Create administrator');
        $this->assertGuest();
        $this->post('/setup', $this->form())->assertRedirect(route('settings.setup'));
        $this->assertAuthenticatedAs(User::sole());
    }

    public function test_validation_does_not_flash_secrets_or_create_a_user(): void
    {
        $this->mock(FirstAdmin::class)->shouldReceive('state')->andReturn('armed');
        $form = $this->form();
        $form['password_confirmation'] = 'different';
        $this->from('/setup')->post('/setup', $form)->assertSessionHasErrors('password');
        foreach (['password', 'password_confirmation', 'setup_code'] as $field)
            $this->assertNull(session()->getOldInput($field));
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_established_station_keeps_login_and_public_registration_closed(): void
    {
        $this->mock(FirstAdmin::class)->shouldReceive('state')->andReturn('closed');
        $this->get('/login')->assertOk();
        $this->get('/setup')->assertRedirect(route('login'));
        $this->post('/setup', $this->form())->assertRedirect(route('login'));
        $this->get('/register')->assertNotFound();
        $this->assertGuest();
    }

    public function test_transaction_replays_only_its_internal_receipt_and_refuses_after_deletion(): void
    {
        $service = app(FirstAdminReceipt::class);
        $claim = ['claim' => str_repeat('a', 32), 'name' => 'Operator', 'email' => 'first@example.org',
            'password_hash' => Hash::make('new-password')];
        $this->assertSame(['result' => 'empty'], $service->handle([]));
        $receipt = $service->handle($claim);
        $this->assertSame('committed', $receipt['result']);
        $this->assertSame($receipt, $service->handle($claim));
        $this->assertSame(['result' => 'occupied'], $service->handle(array_replace($claim, ['claim' => str_repeat('b', 32)])));
        User::sole()->delete();
        $this->assertSame(['result' => 'occupied'], $service->handle([]));
        $this->assertSame(['result' => 'occupied'], $service->handle($claim));
    }
}
