<?php

namespace Tests\Feature;

use App\Http\Livewire\StationTimeSettings;
use App\Models\Cm;
use App\Models\User;
use App\Services\NetworkChangeGate;
use App\Services\StationTimeControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeStationTimeControl;
use Tests\TestCase;

class StationTimeSettingsTest extends TestCase
{
    use RefreshDatabase;
    private FakeStationTimeControl $control;
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(StationTimeControl::class, $this->control = new FakeStationTimeControl);
        $this->app->instance(NetworkChangeGate::class, new NetworkChangeGate(false));
    }
    private function login(bool $confirmed = true): void
    {
        $this->actingAs(User::factory()->create());
        if ($confirmed) $this->withSession(['auth.password_confirmed_at' => time()]);
    }
    public function test_guests_and_unconfirmed_calls_cannot_change_time(): void
    {
        Livewire::test(StationTimeSettings::class)->assertForbidden();
        $this->login(false);
        Livewire::test(StationTimeSettings::class)->set('timezone', 'Asia/Jerusalem')->call('saveTimezone')->assertForbidden();
        Livewire::test(StationTimeSettings::class)->call('saveNtp')->assertForbidden();
        $this->assertSame([], $this->control->calls);
    }
    public function test_status_is_read_only_and_refresh_preserves_unsaved_fields(): void
    {
        $this->login();
        $page = Livewire::test(StationTimeSettings::class)->assertSee('Time and synchronization')
            ->assertSee('Synchronized')->assertSee('time.example.test')
            ->set('timezone', 'Asia/Jerusalem')->set('ntpEnabled', false)->set('ntpServers', 'new.example.test');
        $this->control->state['server_name'] = 'changed.example.test';
        $page->call('refreshStatus')->assertSee('changed.example.test')->assertSet('timezone', 'Asia/Jerusalem')
            ->assertSet('ntpEnabled', false)->assertSet('ntpServers', 'new.example.test');
        $this->assertSame([], $this->control->calls);
    }
    public function test_timezone_save_preserves_utc_storage_and_other_unsaved_form(): void
    {
        $this->login();
        Livewire::test(StationTimeSettings::class)->set('ntpServers', 'unsaved.test')->set('timezone', 'Asia/Jerusalem')
            ->call('saveTimezone')->assertHasNoErrors()->assertSee('Time zone saved')->assertSet('ntpServers', 'unsaved.test');
        $this->assertSame([['timezone', 'Asia/Jerusalem']], $this->control->calls);
        $this->assertSame('UTC', config('app.timezone'));
    }
    public function test_ntp_accepts_several_server_types_and_empty_list_restores_defaults(): void
    {
        $this->login();
        Livewire::test(StationTimeSettings::class)->set('ntpServers', "time.example.test, 172.20.0.2\n2001:db8::123")
            ->call('saveNtp')->assertHasNoErrors()->assertSee('NTP settings saved')->assertSee('Not synchronized');
        $this->assertSame([['ntp', true, ['time.example.test', '172.20.0.2', '2001:db8::123']]], $this->control->calls);
        Livewire::test(StationTimeSettings::class)->set('ntpServers', '')->set('ntpEnabled', false)->call('saveNtp')->assertHasNoErrors();
        $this->assertSame(['ntp', false, []], $this->control->calls[1]);
    }
    public function test_invalid_zone_and_ntp_values_never_reach_root(): void
    {
        $this->login();
        Livewire::test(StationTimeSettings::class)->set('timezone', '../UTC')->call('saveTimezone')->assertHasErrors('timezone');
        foreach (['host;id', 'https://ntp.test', '1.2.3.999', '_bad.test'] as $server) {
            Livewire::test(StationTimeSettings::class)->set('ntpServers', $server)->call('saveNtp')->assertHasErrors('ntpServers');
        }
        Livewire::test(StationTimeSettings::class)->set('ntpServers', implode(' ', array_fill(0, 9, 'a.test')))->call('saveNtp')->assertHasErrors('ntpServers');
        $this->assertSame([], $this->control->calls);
    }
    public function test_active_modules_block_time_changes(): void
    {
        $this->login();
        Cm::create(['serial' => '1000000000000001', 'mac' => 'e4:5f:01:00:00:01', 'phase' => 'write']);
        Livewire::test(StationTimeSettings::class)->set('timezone', 'Asia/Jerusalem')->call('saveTimezone')->assertSee('Wait until provisioning');
        Livewire::test(StationTimeSettings::class)->call('saveNtp')->assertSee('Wait until provisioning');
        $this->assertSame([], $this->control->calls);
    }
    public function test_missing_or_unsupported_provider_does_not_pretend_a_save_succeeded(): void
    {
        $this->login();
        $this->control->state['ntp_available'] = false;
        $this->control->state['ntp_reason'] = 'Another synchronization service is installed.';
        Livewire::test(StationTimeSettings::class)->call('saveNtp')->assertSee('Another synchronization service is installed.');
        Livewire::test(StationTimeSettings::class)->set('timezone', 'Asia/Jerusalem')->call('saveTimezone')->assertSee('Time zone saved');
        $this->assertSame([['timezone', 'Asia/Jerusalem']], $this->control->calls);
    }
    public function test_explicit_display_override_is_visible(): void
    {
        $this->login();
        config(['app.display_timezone_override' => 'Europe/Berlin']);
        Livewire::test(StationTimeSettings::class)->assertSee('APP_DISPLAY_TIMEZONE')->assertSee('Europe/Berlin');
    }
    public function test_an_invalid_override_is_described_as_ignored(): void
    {
        $this->login();
        config(['app.display_timezone_override' => 'Mars/Olympus']);
        Livewire::test(StationTimeSettings::class)->assertSee('invalid and is ignored')->assertDontSee('overrides the display zone');
    }
    public function test_unavailable_controller_does_not_claim_ntp_is_inactive(): void
    {
        $this->login();
        $this->control->state = array_merge($this->control->state, ['available' => false, 'reason' => 'Unavailable controller.',
            'ntp_available' => false, 'ntp_reason' => 'Unavailable controller.', 'ntp_active' => false, 'synchronized' => null]);
        Livewire::test(StationTimeSettings::class)->assertSee('NTP unknown')->assertDontSee('NTP inactive');
    }
    public function test_system_groups_station_settings_and_status_remains_monitoring_only(): void
    {
        $this->login();
        $this->get('/settings?tab=system')->assertOk()->assertSee('Time and synchronization')->assertSee('Station power')
            ->assertSee('HDMI display')->assertSee('FPMS display')->assertSee('Download backup')->assertSee('Selective import / export')
            ->assertDontSee('id="services"', false);
        $this->get('/settings?tab=status')->assertOk()->assertSee('id="services"', false)->assertDontSee('Download backup')
            ->assertDontSee('Time and synchronization')->assertDontSee('Station power');
        $this->get('/settings?tab=provisioning')->assertOk()->assertDontSee('HDMI display')->assertDontSee('FPMS display');
    }
}
