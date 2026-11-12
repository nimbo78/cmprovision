<?php

namespace Tests\Feature;

use App\Http\Livewire\RescueWifiSettings;
use App\Models\User;
use App\Services\RescueWifi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeRescueWifi;
use Tests\TestCase;

class RescueWifiSettingsTest extends TestCase
{
    use RefreshDatabase;
    private $wifi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(class_exists(RescueWifiSettings::class), 'The rescue Wi-Fi settings component is missing.');
        $this->wifi = new FakeRescueWifi;
        $this->app->instance(RescueWifi::class, $this->wifi);
        $this->actingAs(User::factory()->create());
    }

    public function test_network_tab_contains_rescue_controls_with_ten_minute_default(): void
    {
        $this->get('/settings?tab=network')->assertOk()->assertSee('Rescue Wi-Fi access point');
        Livewire::test(RescueWifiSettings::class)->assertSet('delayMinutes', 10);
    }

    public function test_changes_require_account_password_confirmation(): void
    {
        Livewire::test(RescueWifiSettings::class)->set('enabled', true)->call('save')->assertForbidden();
        $this->assertSame([], $this->wifi->calls);
    }

    public function test_valid_form_sends_seconds_and_no_password_property(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $component = Livewire::test(RescueWifiSettings::class)->set('enabled', true)->call('save')->assertHasNoErrors();
        $this->assertSame(600, $this->wifi->calls[0][1]['delay']);
        $this->assertArrayNotHasKey('password', $component->snapshot['data']);
    }

    public function test_invalid_timeout_is_not_sent_to_root(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);
        Livewire::test(RescueWifiSettings::class)->set('delayMinutes', 0)->call('save')->assertHasErrors('delayMinutes');
        $this->assertSame([], $this->wifi->calls);
    }

    public function test_active_clients_get_a_separate_disconnect_warning(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->wifi->state = array_merge($this->wifi->state, ['active' => true, 'state' => 'active', 'clients' => 1]);
        $component = Livewire::test(RescueWifiSettings::class)->call('begin', 'pause')->assertSet('confirmingDisconnect', true);
        $this->assertSame([], $this->wifi->calls);
        $component->call('confirmDisconnect')->assertSet('confirmingDisconnect', false);
        $this->assertSame([['pause']], $this->wifi->calls);
    }

    public function test_disabling_active_ap_cannot_skip_disconnect_confirmation(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->wifi->data['settings']['enabled'] = true;
        $this->wifi->state = array_merge($this->wifi->state, ['active' => true, 'state' => 'active', 'clients' => 1]);
        $component = Livewire::test(RescueWifiSettings::class)->set('enabled', false)->call('save')->assertSet('confirmingDisconnect', true);
        $this->assertSame([], $this->wifi->calls);
        $component->call('confirmDisconnect');
        $this->assertFalse($this->wifi->calls[0][1]['enabled']);
        $component->assertSee('Rescue access is being disabled');
    }

    public function test_back_clears_the_queued_disconnect_action(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->wifi->state['active'] = true;
        Livewire::test(RescueWifiSettings::class)->call('begin', 'pause')->call('cancelDisconnect')
            ->assertSet('pendingAction', null)->assertSet('pendingSettings', [])->assertSet('confirmingDisconnect', false)
            ->call('confirmDisconnect');
        $this->assertSame([], $this->wifi->calls);
    }

    public function test_explicit_secret_reveal_requires_confirmation_and_never_enters_livewire_state(): void
    {
        Livewire::test(RescueWifiSettings::class)->call('showCredentials')->assertForbidden();
        $this->assertSame([], $this->wifi->calls);
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $component = Livewire::test(RescueWifiSettings::class)->call('showCredentials')->assertDispatched('rescue-wifi-details');
        $this->assertStringNotContainsString('fixture-ap-password-123', json_encode($component->snapshot));
        $this->assertStringNotContainsString('fixture-ap-password-123', $component->html());
    }

    public function test_new_ap_password_is_transient_and_not_returned_in_normal_state(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $component = Livewire::test(RescueWifiSettings::class)->call('changePassword', 'new-ap-password-123')->assertHasNoErrors();
        $this->assertSame('new-ap-password-123', $this->wifi->calls[0][1]['password']);
        $this->assertStringNotContainsString('new-ap-password-123', json_encode($component->snapshot));
    }
}
