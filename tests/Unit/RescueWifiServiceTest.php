<?php

namespace Tests\Unit;

use App\Services\RescueWifi;
use Tests\TestCase;

class RescueWifiServiceTest extends TestCase
{
    public function test_root_status_discards_secret_and_unknown_settings(): void
    {
        $this->assertTrue(class_exists(RescueWifi::class), 'Rescue Wi-Fi service is missing.');
        $service = new RescueWifi(fn () => [0, json_encode(['settings' => ['enabled' => true, 'delay' => 600,
            'ssid' => 'rescue', 'password' => 'must-not-escape'], 'radios' => []])]);
        $data = $service->metadata();
        $this->assertSame(600, $data['settings']['delay']);
        $this->assertArrayNotHasKey('password', $data['settings']);
        $this->assertStringNotContainsString('must-not-escape', json_encode($data));
    }

    public function test_configuration_goes_over_stdin_with_a_fixed_command(): void
    {
        $this->assertTrue(class_exists(RescueWifi::class), 'Rescue Wi-Fi service is missing.');
        $calls = [];
        $service = new RescueWifi(function ($command, $input) use (&$calls) { $calls[] = [$command, $input]; return [0, '{}']; });
        $service->save(['enabled' => true, 'password' => 'private-password-123']);
        $this->assertSame(['sudo', '-n', RescueWifi::HELPER, 'configure'], $calls[0][0]);
        $this->assertSame('private-password-123', json_decode($calls[0][1], true)['password']);
    }

    public function test_public_projection_rejects_credentials_and_unbounded_input(): void
    {
        $this->assertTrue(class_exists(RescueWifi::class), 'Rescue Wi-Fi service is missing.');
        $status = RescueWifi::sanitizeStatus(['schema' => 1, 'state' => 'active', 'active' => true,
            'ssid' => 'rescue', 'address' => '192.168.240.1', 'clients' => 2, 'password' => 'must-not-escape', 'raw' => 'anything']);
        $this->assertSame('active', $status['state']);
        $this->assertSame(2, $status['clients']);
        $this->assertArrayNotHasKey('password', $status);
        $this->assertArrayNotHasKey('raw', $status);
        $this->assertSame([], RescueWifi::sanitizeStatus(['schema' => 22, 'state' => 'active']));
    }
}
