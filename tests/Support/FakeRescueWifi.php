<?php

namespace Tests\Support;

use App\Services\RescueWifi;

class FakeRescueWifi extends RescueWifi
{
    public $calls = [];
    public $data = ['settings' => ['enabled' => false, 'delay' => 600, 'ssid' => 'cmprovision-test-rescue',
        'ap_mac' => '02:00:00:00:00:01', 'client_mac' => '02:00:00:00:00:02', 'subnet' => '192.168.240.0/24',
        'channel' => 6, 'management_profiles' => [], 'password_configured' => true],
        'radios' => [['mac' => '02:00:00:00:00:01', 'device' => 'wlan0', 'ap' => true],
            ['mac' => '02:00:00:00:00:02', 'device' => 'wlan1', 'ap' => true]], 'management_profiles' => [], 'readiness_error' => null];
    public $state = ['enabled' => false, 'active' => false, 'state' => 'off', 'reason' => 'disabled', 'clients' => 0,
        'ssid' => 'cmprovision-test-rescue', 'address' => '192.168.240.1', 'remaining' => null, 'pending_settings' => false];

    public function available(): bool { return true; }
    public function metadata(): array { return $this->data; }
    public function status(): array { return $this->state; }
    public function save(array $settings): void
    {
        $this->calls[] = ['configure', $settings];
        foreach ($settings as $key => $value) if ($key !== 'password') $this->data['settings'][$key] = $value;
    }
    public function action(string $action): void { $this->calls[] = [$action]; }
    public function reveal(): array { $this->calls[] = ['reveal']; return ['ssid' => 'cmprovision-test-rescue', 'password' => 'fixture-ap-password-123', 'active' => null]; }
}
