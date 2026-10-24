<?php

namespace Tests\Feature;

use App\Http\Livewire\FpmsSettings;
use App\Models\User;
use App\Services\Fpms;
use App\Services\NetworkStatus;
use App\Support\DisplayStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FpmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_require_login_and_a_fixed_boolean_schema()
    {
        Livewire::test(FpmsSettings::class)->call('save')->assertForbidden();
        $service = $this->mock(Fpms::class);
        $service->shouldReceive('settings')->andReturn(Fpms::DEFAULTS);
        $service->shouldReceive('status')->andReturn(['state' => 'off']);
        $service->shouldReceive('save')->once()->with(['enabled' => true, 'open_on_start' => false]);
        Livewire::actingAs(User::factory()->create())->test(FpmsSettings::class)
            ->assertSet('enabled', false)->set('enabled', 'shell')->call('save')->assertHasErrors('enabled')
            ->set('enabled', true)->set('open_on_start', [true])->call('save')->assertHasErrors('open_on_start')
            ->set('open_on_start', false)->call('save')->assertHasNoErrors()->assertSee('Saved.');
    }

    public function test_helper_failure_is_visible_without_a_success_notice()
    {
        $service = $this->mock(Fpms::class);
        $service->shouldReceive('settings')->andReturn(Fpms::DEFAULTS);
        $service->shouldReceive('status')->andReturn(['state' => 'off']);
        $service->shouldReceive('save')->andThrow(new \RuntimeException('Could not save FPMS settings.'));
        Livewire::actingAs(User::factory()->create())->test(FpmsSettings::class)->call('save')
            ->assertHasErrors('enabled')->assertDontSee('Saved.');
    }

    public function test_station_data_is_cached_and_does_not_publish_wifi_credentials_or_profiles()
    {
        $service = $this->mock(NetworkStatus::class);
        $service->shouldReceive('current')->once()->with(30)->andReturn([
            'uplink' => ['addresses' => ['192.0.2.4/24'], 'wifi' => ['ssid' => 'private-ssid']],
            'modules' => ['name' => 'eth0.42', 'up' => true], 'preferred_down' => 'private-profile',
        ]);
        $data = DisplayStation::current();
        $this->assertSame($data, DisplayStation::current());
        $this->assertSame('192.0.2.4', $data['management']);
        $this->assertSame('eth0.42 up', $data['provisioning']);
        $this->assertStringNotContainsString('private-', json_encode($data));
    }
}
