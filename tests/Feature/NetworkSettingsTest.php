<?php

namespace Tests\Feature;

use App\Http\Livewire\NetworkSettings;
use App\Models\User;
use App\Services\NetworkChanges;
use App\Services\NetworkProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NetworkSettingsTest extends TestCase
{
    use RefreshDatabase;
    const UUID = 'd6e8fe66-09dc-40a6-9b80-358dff6da460';
    const TOKEN = 'a-secret-network-confirmation-token';

    private function details(): array
    {
        $base = ['addresses' => '', 'gateway' => '', 'dns' => '', 'routes' => '', 'route-metric' => '-1',
            'never-default' => 'no', 'ignore-auto-dns' => 'no'];
        return ['ipv4' => ['method' => 'manual'] + array_merge($base, ['addresses' => '192.0.2.10/24']),
            'ipv6' => ['method' => 'ignore'] + $base];
    }

    private function reader(): NetworkProfiles
    {
        $reader = $this->createMock(NetworkProfiles::class);
        $reader->method('active')->willReturn([['device' => 'eth0.42', 'uuid' => self::UUID, 'type' => 'vlan']]);
        $reader->method('detail')->with('eth0.42', self::UUID)->willReturn($this->details());
        return $reader;
    }

    public function test_applying_requires_the_account_password(): void
    {
        $changes = $this->createMock(NetworkChanges::class);
        $changes->method('available')->willReturn(true);
        $changes->method('status')->willReturn(['ok' => true, 'result' => ['pending' => false]]);
        $changes->expects($this->never())->method('begin');
        $this->app->instance(NetworkChanges::class, $changes);
        $this->app->instance(NetworkProfiles::class, $this->reader());
        $this->actingAs(User::factory()->create());
        Livewire::test(NetworkSettings::class)->call('edit', 'eth0.42', self::UUID)->call('apply')->assertForbidden();
    }

    public function test_confirmed_edit_sends_addresses_and_routes_to_the_controller(): void
    {
        $changes = $this->createMock(NetworkChanges::class);
        $changes->method('available')->willReturn(true);
        $changes->method('status')->willReturn(['ok' => true, 'result' => ['pending' => false]]);
        $changes->expects($this->once())->method('begin')->with('eth0.42', self::UUID,
            $this->callback(fn ($profile) => $profile['ipv4']['addresses'] === ['192.0.2.10/24'] &&
                $profile['ipv4']['routes'] === [['destination' => '198.51.100.0/24', 'via' => '192.0.2.1', 'metric' => 25],
                    ['destination' => '203.0.113.0/24', 'metric' => 35]] &&
                $profile['ipv6']['method'] === 'ignore'))
            ->willReturn(['ok' => true, 'result' => ['token' => self::TOKEN]]);
        $this->app->instance(NetworkChanges::class, $changes);
        $this->app->instance(NetworkProfiles::class, $this->reader());
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        Livewire::test(NetworkSettings::class)->call('edit', 'eth0.42', self::UUID)
            ->set('ipv4.routes', "198.51.100.0/24 via 192.0.2.1 metric 25\n203.0.113.0/24 35")->call('apply')
            ->assertHasNoErrors()->assertSee('The network change is temporary.');
    }

    public function test_confirmation_token_is_not_sent_to_the_browser(): void
    {
        $changes = $this->createMock(NetworkChanges::class);
        $changes->method('available')->willReturn(true);
        $changes->method('status')->willReturn(['ok' => true, 'result' => ['pending' => true, 'token' => self::TOKEN,
            'device' => 'eth0.42', 'uuid' => self::UUID, 'expires_at' => time() + 60]]);
        $changes->expects($this->once())->method('confirm')->with(self::TOKEN)->willReturn(['ok' => true]);
        $this->app->instance(NetworkChanges::class, $changes);
        $this->app->instance(NetworkProfiles::class, $this->reader());
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        $component = Livewire::test(NetworkSettings::class)->assertSee('Confirm network');
        $this->assertStringNotContainsString(self::TOKEN, $component->html());
        $this->assertStringNotContainsString(self::TOKEN, json_encode($component->snapshot));
        $component->call('confirm')->assertSee('Network settings confirmed.');
    }

    public function test_stand_move_uses_current_source_and_a_selected_active_target(): void
    {
        $source = ['device' => 'eth0', 'uuid' => '793d7b9d-04aa-4de0-9f2c-cc793f8a45af', 'type' => 'ethernet', 'address' => '172.20.0.1'];
        $target = ['device' => 'eth0.42', 'uuid' => self::UUID, 'type' => 'vlan', 'vlan_id' => 42];
        $reader = $this->createMock(NetworkProfiles::class);
        $reader->method('active')->willReturn([$source, $target]);
        $reader->method('stand')->willReturn($source);
        $changes = $this->createMock(NetworkChanges::class);
        $changes->method('available')->willReturn(true);
        $changes->method('status')->willReturn(['ok' => true, 'result' => ['pending' => false]]);
        $changes->expects($this->once())->method('moveStand')->with($source, $target)->willReturn(['ok' => true]);
        $this->app->instance(NetworkChanges::class, $changes);
        $this->app->instance(NetworkProfiles::class, $reader);
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        Livewire::test(NetworkSettings::class)->assertSee('172.20.0.1/16')->assertSee('VLAN 42')
            ->set('standTarget', self::UUID)->call('moveStand')->assertSee('The stand network moved temporarily.');
    }

    public function test_stand_move_requires_password_confirmation(): void
    {
        $changes = $this->createMock(NetworkChanges::class);
        $changes->method('available')->willReturn(true);
        $changes->method('status')->willReturn(['ok' => true, 'result' => ['pending' => false]]);
        $changes->expects($this->never())->method('moveStand');
        $this->app->instance(NetworkChanges::class, $changes);
        $this->app->instance(NetworkProfiles::class, $this->reader());
        $this->actingAs(User::factory()->create());
        Livewire::test(NetworkSettings::class)->set('standTarget', self::UUID)->call('moveStand')->assertForbidden();
    }
}
