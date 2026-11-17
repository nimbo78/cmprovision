<?php

namespace Tests\Feature;

use App\Http\Livewire\StationAccessSettings;
use App\Models\User;
use App\Services\StationAccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class StationAccessSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function controller(): StationAccessControl
    {
        $control = $this->createMock(StationAccessControl::class);
        $control->method('available')->willReturn(true);
        $control->method('status')->willReturn(['ok' => true, 'result' => ['users' => [['user' => 'operator', 'uid' => 1000]], 'ssh_active' => true, 'pending' => null]]);
        $this->app->instance(StationAccessControl::class, $control);
        return $control;
    }

    public function test_preparing_ssh_requires_recent_web_password_confirmation(): void
    {
        $control = $this->controller();
        $control->expects($this->never())->method('prepare');
        $this->actingAs(User::factory()->create());
        Livewire::test(StationAccessSettings::class)->set('linuxUser', 'operator')->call('prepareSsh', 'new-password12', '')->assertForbidden();
    }

    public function test_wizard_access_sections_are_independent_and_share_a_visible_password_prompt(): void
    {
        $this->controller();
        $this->actingAs(User::factory()->create());
        $html = Livewire::test(StationAccessSettings::class, ['compact' => true])->html();
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        foreach (['station-web-access-form', 'station-ssh-access-form'] as $id) {
            $this->assertCount(1, $xpath->query("//*[@id='$id']/ancestor::details[not(@open)]"));
        }
        $this->assertCount(1, $xpath->query('//*[starts-with(@id,"confirm-password-") and not(ancestor::details)]'));
        $full = Livewire::test(StationAccessSettings::class)->html();
        $document->loadHTML($full, LIBXML_NOERROR | LIBXML_NOWARNING);
        $this->assertCount(0, (new \DOMXPath($document))->query('//*[@id="station-web-access-form"]/ancestor::details'));
    }

    public function test_secrets_are_action_arguments_and_never_livewire_properties(): void
    {
        $control = $this->controller();
        $control->expects($this->once())->method('prepare')->with(['user' => 'operator', 'password' => 'new-password12', 'keys' => 'ssh-ed25519 TEST', 'enable_ssh' => false])
            ->willReturn(['ok' => true, 'result' => ['id' => str_repeat('a', 32), 'user' => 'operator', 'changes' => ['Change Linux password'], 'state' => 'awaiting_approval', 'expires_at' => time()+300]]);
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        $component = Livewire::test(StationAccessSettings::class)->set('linuxUser', 'operator')->call('prepareSsh', 'new-password12', 'ssh-ed25519 TEST')
            ->assertDispatched('station-access-clear-secrets')->assertSee('cmprovision-access approve');
        $snapshot = json_encode($component->snapshot);
        $this->assertStringNotContainsString('new-password12', $snapshot);
        $this->assertStringNotContainsString('ssh-ed25519 TEST', $snapshot);
    }

    public function test_ssh_apply_uses_server_owned_request_reference_and_one_time_code(): void
    {
        $control = $this->controller();
        $control->method('prepare')->willReturn(['ok' => true, 'result' => ['id' => str_repeat('a', 32), 'user' => 'operator', 'changes' => ['Enable SSH service'], 'state' => 'awaiting_approval', 'expires_at' => time()+300]]);
        $control->expects($this->once())->method('apply')->with(str_repeat('a', 32), 'ABCD-EFGH-IJKL')->willReturn(['ok' => true, 'result' => ['state' => 'applied', 'applied' => ['ssh service']]]);
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        $component = Livewire::test(StationAccessSettings::class)->set('linuxUser', 'operator')->set('enableSsh', true)->call('prepareSsh', '', '')
            ->call('applySsh', 'ABCD-EFGH-IJKL')->assertSee('SSH access updated.');
        $this->assertStringNotContainsString('ABCD-EFGH-IJKL', json_encode($component->snapshot));
    }

    public function test_web_password_is_checked_and_profile_password_update_uses_fortify(): void
    {
        $this->controller();
        $user = User::factory()->create(['password' => Hash::make('old-password12')]);
        $this->actingAs($user);
        Livewire::test(StationAccessSettings::class)->set('name', 'Operator')->call('saveWeb', 'wrong', '', '')->assertHasErrors('current_password');
        Livewire::test(StationAccessSettings::class)->set('name', 'New operator')->set('email', 'operator@example.test')
            ->call('saveWeb', 'old-password12', 'new-password12', 'new-password12')->assertHasNoErrors()->assertSee('Web account saved.');
        $this->assertSame('New operator', $user->fresh()->name);
        $this->assertTrue(Hash::check('new-password12', $user->fresh()->password));
        $this->assertAuthenticatedAs($user);
        $this->get('/settings?tab=system')->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(auth()->guard('web')->hashPasswordForCookie($user->fresh()->password), session('password_hash_web'));
    }

    public function test_ssh_controller_failure_keeps_the_editor_and_does_not_claim_success(): void
    {
        $control = $this->controller();
        $control->method('prepare')->willReturn(['ok' => false, 'error' => 'The local Linux password record is unavailable.']);
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        Livewire::test(StationAccessSettings::class)->set('linuxUser', 'operator')->call('prepareSsh', 'new-password12', '')
            ->assertSee('The local Linux password record is unavailable.')->assertDontSee('SSH access updated.');
    }

    public function test_discarding_unsaved_fields_restores_the_account_without_changing_it(): void
    {
        $this->controller();
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test(StationAccessSettings::class)->set('name', 'Unsaved name')->set('enableSsh', true)
            ->call('discardEdits')->assertSet('name', $user->name)->assertSet('enableSsh', false)->assertDispatched('station-wizard-saved');
        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_web_save_and_validation_failure_clear_only_web_secrets(): void
    {
        $this->controller();
        $this->actingAs(User::factory()->create(['password' => Hash::make('current-web-password')]));
        Livewire::test(StationAccessSettings::class)->call('saveWeb', 'wrong', '', '')
            ->assertHasErrors('current_password')->assertDispatched('station-access-clear-secrets', scope: 'web')
            ->assertNotDispatched('station-wizard-saved');
        Livewire::test(StationAccessSettings::class)->call('saveWeb', 'current-web-password', '', '')
            ->assertHasNoErrors()->assertDispatched('station-access-clear-secrets', scope: 'web')
            ->assertDispatched('station-wizard-saved', scope: 'station-web-access-form');
    }

    public function test_ssh_prepare_success_and_failure_clear_only_ssh_secrets(): void
    {
        $control = $this->controller();
        $control->method('prepare')->willReturnOnConsecutiveCalls(
            ['ok' => false, 'error' => 'Invalid keys'],
            ['ok' => true, 'result' => ['id' => str_repeat('a',32), 'user' => 'operator', 'changes' => ['Enable SSH service'], 'state' => 'awaiting_approval', 'expires_at' => time()+300]]
        );
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        $page = Livewire::test(StationAccessSettings::class)->set('linuxUser','operator');
        $page->call('prepareSsh','secret-password','')->assertDispatched('station-access-clear-secrets', scope: 'ssh');
        $page->call('prepareSsh','secret-password','')->assertDispatched('station-access-clear-secrets', scope: 'ssh');
    }
}
