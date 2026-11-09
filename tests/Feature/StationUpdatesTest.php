<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Setting;
use App\Services\StationUpdates;
use App\Services\StationUpdatesControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private function control(array $status = []): StationUpdatesControl
    {
        $fake = new class($status) extends StationUpdatesControl {
            public array $calls = [];
            public function __construct(public array $state) {}
            public function available(): bool { return true; }
            public function status(): array { return $this->state; }
            public function request(array $request): array { $this->calls[] = $request; return ['id' => str_repeat('a', 32)]; }
            public function pending(): bool { return false; }
        };
        $this->app->instance(StationUpdatesControl::class, $fake);
        return $fake;
    }

    public function test_checks_default_off_manual_checks_both_and_render_does_not_contact_publisher(): void
    {
        $control = $this->control();
        $updates = app(StationUpdates::class);
        $this->assertSame(['application' => false, 'utility-os' => false], $updates->preferences());
        $updates->scheduled();
        $this->assertSame([], $control->calls);
        $updates->check();
        $this->assertSame(['application', 'utility-os'], $control->calls[0]['components']);
        $this->actingAs(User::factory()->create())->get('/settings?tab=updates')->assertOk()->assertSee('Updates');
        $this->assertCount(1, $control->calls);
    }

    public function test_scheduled_checks_only_enabled_due_components_and_do_not_repeat_daily(): void
    {
        $control = $this->control();
        $updates = app(StationUpdates::class);
        $updates->savePreferences(true, false);
        $updates->scheduled();
        $updates->scheduled();
        $this->assertCount(1, $control->calls);
        $this->assertSame(['application'], $control->calls[0]['components']);
    }

    public function test_confirmation_binds_actor_and_receipt_expires_and_replays_one_nonce(): void
    {
        $control = $this->control(['ready' => [['id' => str_repeat('b', 64), 'receipt' => str_repeat('c', 64),
            'release' => ['component' => 'application', 'version' => '2.28.0'], 'plan' => []]]]);
        $updates = app(StationUpdates::class);
        $intent = $updates->prepare('apply', str_repeat('b', 64), 'web:1:session');
        try { $updates->confirm($intent['token'], 'web:2:session'); $this->fail('Other actor accepted'); }
        catch (\RuntimeException $expected) {}
        $updates->confirm($intent['token'], 'web:1:session');
        $updates->confirm($intent['token'], 'web:1:session');
        $this->assertSame($control->calls[0], $control->calls[1]);
        $this->travel(601)->seconds();
        $this->expectException(\RuntimeException::class);
        $updates->confirm($intent['token'], 'web:1:session');
    }

    public function test_status_requires_authentication(): void
    {
        $this->control();
        $this->getJson('/settings/updates/status')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/settings/updates/status')->assertOk();
    }

    public function test_maintenance_blocks_module_starts_and_mutations_but_keeps_status_readable(): void
    {
        $control = $this->control();
        $this->app->instance(StationUpdatesControl::class, new class extends StationUpdatesControl {
            public function available(): bool { return true; }
            public function pending(): bool { return true; }
            public function request(array $request): array { return ['schema' => 1, 'pending' => ['id' => str_repeat('a', 32)]]; }
        });
        $this->get('/scriptexecute?serial=test')->assertStatus(503)->assertHeader('Retry-After', '10');
        $this->post('/livewire/update')->assertStatus(503);
        $this->actingAs(User::factory()->create())->getJson('/settings/updates/status')->assertOk()->assertJsonPath('schema', 1);
    }
}
