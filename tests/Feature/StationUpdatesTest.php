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
    use \Tests\Support\UtilityOsTree;

    private function control(array $status = []): StationUpdatesControl
    {
        $fake = new class($status) extends StationUpdatesControl {
            public array $calls = [];
            public int $statusCalls = 0;
            public function __construct(public array $state) {}
            public function available(): bool { return true; }
            public function status(): array { $this->statusCalls++; return $this->state; }
            public function request(array $request): array
            {
                $this->calls[] = $request;
                return $request['action'] === 'status' ? $this->state : ['id' => str_repeat('a', 32)];
            }
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
        $this->assertSame([['action' => 'status']], $control->calls);
        $updates->check();
        $this->assertSame(['application', 'utility-os'], $control->calls[1]['components']);
        $this->actingAs(User::factory()->create())->get('/settings?tab=updates')->assertOk()->assertSee('Updates');
        $this->assertCount(2, $control->calls);
    }

    public function test_scheduled_checks_only_enabled_due_components_and_do_not_repeat_daily(): void
    {
        $control = $this->control();
        $updates = app(StationUpdates::class);
        $updates->savePreferences(true, false);
        $updates->scheduled();
        $updates->scheduled();
        $this->assertSame(['status', 'check', 'status'], array_column($control->calls, 'action'));
        $this->assertSame(['application'], $control->calls[1]['components']);
    }

    public function test_scheduled_housekeeping_runs_with_publisher_checks_disabled_or_not_due(): void
    {
        $control = $this->control();
        $updates = app(StationUpdates::class);
        $updates->scheduled();
        $this->assertSame([['action' => 'status']], $control->calls, 'hourly root request triggers actual expiry');
        $this->assertSame(0, $control->statusCalls, 'cached public projection cannot perform cleanup');
        $updates->savePreferences(true, false);
        Setting::updateOrCreate(['key' => 'updates_last_attempt_application'], ['value' => (string) now()->timestamp]);
        $updates->scheduled();
        $this->assertSame([['action' => 'status'], ['action' => 'status']], $control->calls);
        $this->assertSame([], array_filter($control->calls, fn ($call) => $call['action'] === 'check'), 'no publisher traffic');
    }

    public function test_scheduled_housekeeping_observes_a_busy_broker_before_starting_a_due_check(): void
    {
        $control = $this->control(['operation' => ['state' => 'checking']]);
        $updates = app(StationUpdates::class);
        $updates->savePreferences(true, false);
        $updates->scheduled();
        $this->assertSame([['action' => 'status']], $control->calls);
    }

    public function test_updates_explain_expiry_and_retirement_before_new_snapshot(): void
    {
        $this->control();
        $this->actingAs(User::factory()->create())->get('/settings?tab=updates')
            ->assertOk()->assertSee('7 days')
            ->assertSee('removed before checking free space and creating the next snapshot');
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

    public function test_abandoned_confirmation_cannot_authorize_a_replayed_request(): void
    {
        $control = $this->control(['ready' => [['id' => str_repeat('b', 64), 'receipt' => str_repeat('c', 64),
            'release' => ['component' => 'application', 'version' => '2.28.0'], 'plan' => []]]]);
        $updates = app(StationUpdates::class);
        $intent = $updates->prepare('apply', str_repeat('b', 64), 'web:1:session');
        $updates->abandon($intent['token'], 'web:1:session');
        $this->assertSame([], $control->calls);
        $this->expectException(\RuntimeException::class);
        $updates->confirm($intent['token'], 'web:1:session');
    }

    public function test_status_requires_authentication(): void
    {
        $this->control();
        $this->getJson('/settings/updates/status')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/settings/updates/status')->assertOk();
    }

    public function test_old_utility_os_selector_cannot_bypass_the_packaged_update_confirmation(): void
    {
        $this->makeTree();
        $this->control();
        $this->actingAs(User::factory()->create());
        \Livewire\Livewire::test(\App\Http\Livewire\UtilityOsSettings::class)
            ->assertDontSeeHtml('wire:click="serve(')
            ->call('serve', 'scriptexecute64')->assertSet('noticeIsError', true);
        $this->assertSame('scriptexecute', app(\App\Services\UtilityOs::class)->current());
    }

    public function test_installed_versions_and_prepared_release_render_with_escaped_notes(): void
    {
        $release = ['component' => 'application', 'version' => '2.28.0', 'tag' => 'v2.28.0', 'notes' => '<script>alert(1)</script>'];
        $this->control(['installed' => ['application' => '2.27.0~dev2', 'fpms' => null, 'utility-os' => '2026.11'],
            'checks' => ['application' => ['id' => str_repeat('b', 64), 'checked_at' => time(), 'release' => $release]],
            'ready' => [['id' => str_repeat('b', 64), 'receipt' => str_repeat('c', 64), 'release' => $release, 'plan' => []]]]);
        $this->actingAs(User::factory()->create())->get('/settings?tab=updates')->assertOk()
            ->assertSee('2.27.0~dev2')->assertSee('2.28.0')->assertSee('Review and apply')
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_status_changes_preserve_the_browser_controller_scope_and_escape_initial_data(): void
    {
        $control = $this->control(['operation' => ['id' => str_repeat('a', 32), 'state' => 'queued',
            'message' => 'Queued \'"<img src=x onerror=alert(1)>&', 'log' => ['First operation']]]);
        $this->actingAs(User::factory()->create());
        $read = function () {
            $response = $this->get('/settings?tab=updates')->assertOk()
                ->assertDontSee('<img src=x onerror=alert(1)>', false);
            $document = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try { $document->loadHTML($response->getContent()); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            foreach ((new \DOMXPath($document))->query('//*[@id="updates"]//*[@x-data]') as $element) {
                if (str_starts_with($element->getAttribute('x-data'), 'StationUpdatesStatus.create(')) {
                    return [$element->getAttribute('x-data'), $element->getAttribute('data-initial-status')];
                }
            }
            $this->fail('The browser update controller was not rendered.');
        };
        [$firstBinding, $firstData] = $read();
        $initial = $control->state;
        $control->state['operation']['state'] = 'succeeded';
        $control->state['operation']['log'] = ['Different operation log'];
        [$nextBinding, $nextData] = $read();
        $this->assertSame($firstBinding, $nextBinding, 'Livewire must not replace Alpine state and leave stale log scopes.');
        $this->assertSame($initial, json_decode($firstData, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($control->state, json_decode($nextData, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_fpm_health_is_loopback_only_and_does_not_need_a_session(): void
    {
        $this->control();
        $this->getJson('/updates/health')->assertOk()->assertJsonPath('protocol', 1)->assertHeaderMissing('Set-Cookie');
        $this->withServerVariables(['REMOTE_ADDR' => '172.20.10.10'])->getJson('/updates/health')->assertForbidden();
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
        $this->post('/livewire/update')->assertStatus(503)
            ->assertHeader('X-Cmprovision-Maintenance', 'station-update');
        $this->artisan('diagnostics:usb')->expectsOutput('{"action":"error","reason":"Station update maintenance; retry after it finishes."}')->assertExitCode(1);
        $this->actingAs(User::factory()->create())->getJson('/settings/updates/status')->assertOk()->assertJsonPath('schema', 1);
    }

    public function test_maintenance_handler_is_loaded_once_in_the_authenticated_shell(): void
    {
        $this->control();
        $this->actingAs(User::factory()->create());
        foreach (['status', 'updates'] as $tab) {
            $html = $this->get(route('settings', ['tab' => $tab]))->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, '/js/station-updates.js?v='));
            $this->assertLessThan(strpos($html, 'data-csrf='), strpos($html, '/js/station-updates.js?v='));
        }
    }
}
