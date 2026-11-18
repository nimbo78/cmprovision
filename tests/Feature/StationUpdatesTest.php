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
            public array $space = ['sufficient' => true, 'volumes' => [
                ['location' => 'Recovery storage', 'required' => 536870912, 'free' => 1073741824, 'reclaimable' => 0, 'shortfall' => 0],
            ]];
            public ?string $spaceError = null;
            public function __construct(public array $state) {}
            public function available(): bool { return true; }
            public function status(): array { $this->statusCalls++; return $this->state; }
            public function request(array $request): array
            {
                $this->calls[] = $request;
                if ($request['action'] === 'space') {
                    if ($this->spaceError !== null) throw new \RuntimeException($this->spaceError);
                    return $this->space;
                }
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
            ->assertSee('Reviewing disk space keeps that point')
            ->assertSee('before the final space check and the new snapshot');
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
        $this->assertSame(['space', 'apply', 'apply'], array_column($control->calls, 'action'));
        $this->assertSame($control->calls[1], $control->calls[2]);
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
        $this->assertSame([['action' => 'space', 'candidate' => str_repeat('b', 64)]], $control->calls);
        $this->expectException(\RuntimeException::class);
        $updates->confirm($intent['token'], 'web:1:session');
    }

    public function test_application_space_review_displays_each_volume_and_blocks_apply_until_rechecked(): void
    {
        $id = str_repeat('b', 64);
        $release = ['component' => 'application', 'tag' => 'v2.31.0', 'version' => '2.31.0'];
        $control = $this->control(['ready' => [['id' => $id, 'receipt' => str_repeat('c', 64), 'release' => $release]]]);
        $enough = $control->space;
        $control->space = ['sufficient' => false, 'volumes' => [
            ['location' => 'Recovery storage', 'required' => 1073741824, 'free' => 536870912, 'reclaimable' => 268435456, 'shortfall' => 268435456],
            ['location' => 'Package filesystem', 'required' => 67108864, 'free' => 134217728, 'reclaimable' => 0, 'shortfall' => 0],
        ]];
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        $panel = \Livewire\Livewire::test(\App\Http\Livewire\StationUpdatesPanel::class)
            ->assertDontSee('Disk space estimate')
            ->call('begin', 'apply', $id)->assertSet('open', true)->assertSee('Disk space estimate')
            ->assertSee('Recovery storage')->assertSee('Package filesystem')
            ->assertSee('Required')->assertSee('Free now')->assertSee('Potential release')->assertSee('Shortfall')
            ->assertSee('1.00 GiB')->assertSee('512.0 MiB')->assertSee('256.0 MiB')->assertSee('64.0 MiB')->assertSee('128.0 MiB')
            ->assertSee('Recheck space')->assertDontSeeHtml('wire:click="confirm"')
            ->call('confirm')->assertNotDispatched('station-update-started');
        $preview = ['action' => 'space', 'candidate' => $id];
        $this->assertSame([$preview], $control->calls);

        $control->space = $enough;
        $panel->call('recheckSpace')->assertSet('open', true)->assertDontSee('Package filesystem')
            ->assertDontSee('256.0 MiB')->assertSee('Confirm apply')
            ->call('confirm')->assertDispatched('station-update-started')->assertSet('open', false);
        $this->assertSame([$preview, $preview], array_slice($control->calls, 0, 2));
        $this->assertSame('apply', $control->calls[2]['action']);
        $this->assertSame($id, $control->calls[2]['candidate']);
    }

    public function test_application_rollback_rechecks_its_operation_and_invalidates_a_stale_confirmation(): void
    {
        $id = str_repeat('d', 32);
        $control = $this->control(['recoveries' => [['id' => $id, 'component' => 'application', 'previous_version' => '2.30.3', 'state' => 'succeeded']]]);
        $enough = $control->space;
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $panel = \Livewire\Livewire::test(\App\Http\Livewire\StationUpdatesPanel::class)
            ->call('begin', 'rollback', $id)->assertSee('Confirm rollback')->assertSee('Disk space estimate');
        $oldToken = $panel->get('confirmation.token');
        $actor = 'web:'.$user->id.':'.hash('sha256', session()->getId());

        $control->spaceError = 'The updater did not answer.';
        $panel->call('recheckSpace')->assertSet('open', true)->assertSee('Disk space estimate unavailable')
            ->assertDontSee('Recovery storage')->assertDontSeeHtml('wire:click="confirm"')
            ->call('confirm')->assertNotDispatched('station-update-started');
        try {
            app(StationUpdates::class)->confirm($oldToken, $actor);
            $this->fail('The previous estimate authorized a rollback after a failed recheck.');
        } catch (\RuntimeException $expired) {
            $this->assertStringContainsString('expired', $expired->getMessage());
        }
        $control->spaceError = null;
        $control->space = ['id' => $id]; // Older controller without an estimate.
        $panel->call('recheckSpace')->assertSee('Disk space estimate unavailable')
            ->assertDontSeeHtml('wire:click="confirm"')->call('back')->assertSet('open', false);
        $this->assertSame(array_fill(0, 3, ['action' => 'space', 'operation' => $id]), $control->calls);
        $control->space = $enough;
        $panel->call('begin', 'rollback', $id)->call('confirm')->assertDispatched('station-update-started');
        $this->assertSame('rollback', $control->calls[4]['action']);
        $this->assertSame($id, $control->calls[4]['operation']);
    }

    public function test_malformed_space_reports_cannot_issue_a_ticket(): void
    {
        $id = str_repeat('d', 32);
        $control = $this->control(['recoveries' => [['id' => $id, 'component' => 'application', 'state' => 'succeeded']]]);
        $volume = $control->space['volumes'][0];
        foreach ([
            ['sufficient' => true, 'volumes' => ['unexpected' => $volume]],
            ['sufficient' => true, 'volumes' => [array_replace($volume, ['free' => -1])]],
            ['sufficient' => true, 'volumes' => [array_replace($volume, ['shortfall' => 1])]],
        ] as $report) {
            $control->space = $report;
            $review = app(StationUpdates::class)->prepare('rollback', $id, 'web:1:session');
            $this->assertNull($review['token']);
            $this->assertFalse($review['space']['sufficient']);
        }
    }

    public function test_utility_os_apply_rollback_and_activation_require_no_space_preview(): void
    {
        $candidate = str_repeat('b', 64);
        $operation = str_repeat('d', 32);
        $release = ['component' => 'utility-os', 'tag' => 'utility-os-2026.11', 'version' => '2026.11'];
        $control = $this->control(['ready' => [['id' => $candidate, 'receipt' => str_repeat('c', 64), 'release' => $release]],
            'recoveries' => [['id' => $operation, 'component' => 'utility-os', 'state' => 'succeeded']]]);
        $control->spaceError = 'Unsupported request';
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        $panel = \Livewire\Livewire::test(\App\Http\Livewire\StationUpdatesPanel::class);
        foreach (['apply' => $candidate, 'rollback' => $operation, 'activate' => 'legacy'] as $action => $target) {
            $panel->call('begin', $action, $target)->assertSet('open', true)
                ->assertDontSee('Disk space estimate')->assertDontSee('Recheck space')
                ->assertSeeHtml('wire:click="confirm"')->call('confirm')->assertSet('open', false);
        }
        $this->assertSame(['apply', 'rollback', 'activate'], array_column($control->calls, 'action'));
    }

    public function test_status_requires_authentication(): void
    {
        $this->control();
        $this->getJson('/settings/updates/status')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/settings/updates/status')->assertOk();
    }

    public function test_component_selection_is_local_and_does_not_check_or_install_updates(): void
    {
        $control = $this->control(['installed' => ['application' => '2.30.3', 'utility-os' => '2026.11']]);
        $this->actingAs(User::factory()->create());
        \Livewire\Livewire::test(\App\Http\Livewire\StationUpdatesPanel::class)
            ->assertSet('component', 'application')
            ->call('selectComponent', 'utility-os')->assertSet('component', 'utility-os')
            ->assertSee('2026.11')
            ->call('selectComponent', 'application')->assertSet('component', 'application');
        $this->assertSame([], $control->calls);
    }

    public function test_unsupported_component_selection_is_rejected_without_requesting_an_update(): void
    {
        $control = $this->control();
        $this->actingAs(User::factory()->create());
        \Livewire\Livewire::test(\App\Http\Livewire\StationUpdatesPanel::class)
            ->call('selectComponent', 'fpms')->assertStatus(422);
        $this->assertSame([], $control->calls);
    }

    public function test_prepared_updates_do_not_cross_component_or_compatibility_boundaries(): void
    {
        $release = ['component' => 'utility-os', 'version' => '2026.11.1', 'tag' => 'utility-os-2026.11.1', 'notes' => 'Boot files'];
        $control = $this->control(['checks' => ['utility-os' => ['id' => str_repeat('b', 64), 'release' => $release]],
            'ready' => [['id' => str_repeat('b', 64), 'receipt' => str_repeat('c', 64), 'release' => $release, 'plan' => []]]]);
        $this->actingAs(User::factory()->create());
        $component = \Livewire\Livewire::test(\App\Http\Livewire\StationUpdatesPanel::class)
            ->assertDontSee('Review and apply')
            ->call('selectComponent', 'utility-os')->assertSee('Review and apply');
        $control->state['checks']['utility-os']['incompatible'] = 'This station requires another boot-file format.';
        $component->call('$refresh')->assertDontSee('Review and apply')->assertSee('another boot-file format');
        $this->assertSame([], $control->calls);
    }

    public function test_failed_apply_with_a_retained_receipt_can_prepare_the_files_again(): void
    {
        $release = ['component' => 'application', 'version' => '2.30.4', 'tag' => 'v2.30.4', 'notes' => 'Release notes'];
        $control = $this->control(['checks' => ['application' => ['id' => str_repeat('b', 64), 'release' => $release]],
            'operation' => ['id' => str_repeat('a', 32), 'action' => 'apply', 'component' => 'application', 'state' => 'failed',
                'message' => 'Required package changes changed; download and review the plan again.'],
            'ready' => [['id' => str_repeat('b', 64), 'receipt' => str_repeat('c', 64), 'release' => $release, 'plan' => []]]]);
        $this->actingAs(User::factory()->create());
        \Livewire\Livewire::test(\App\Http\Livewire\StationUpdatesPanel::class)
            ->assertSee('Download and verify again')
            ->call('download', 'application', 'v2.30.4');
        $this->assertSame('download', $control->calls[0]['action']);
        $this->assertSame('application', $control->calls[0]['component']);
        $this->assertSame('v2.30.4', $control->calls[0]['tag']);
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
