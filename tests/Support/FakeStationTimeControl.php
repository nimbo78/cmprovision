<?php

namespace Tests\Support;

use App\Services\StationTimeControl;

class FakeStationTimeControl extends StationTimeControl
{
    public array $calls = [];
    public array $state = ['available' => true, 'timezone' => 'UTC', 'timezones' => ['UTC', 'Asia/Jerusalem', 'Europe/Berlin'],
        'timestamp' => 1794312000, 'ntp_available' => true, 'ntp_reason' => null, 'ntp_enabled' => true,
        'ntp_active' => true, 'synchronized' => true, 'server_name' => 'time.example.test', 'server_address' => '192.0.2.1',
        'system_servers' => ['time.example.test'], 'link_servers' => [], 'fallback_servers' => ['0.debian.pool.ntp.org'],
        'managed_servers' => [], 'provider' => 'systemd-timesyncd'];
    public function status(): array { return $this->state; }
    public function timezone(string $zone): array
    {
        $this->calls[] = ['timezone', $zone];
        $this->state['timezone'] = $zone;
        return $this->state;
    }
    public function ntp(bool $enabled, array $servers): array
    {
        $this->calls[] = ['ntp', $enabled, $servers];
        $this->state['ntp_enabled'] = $this->state['ntp_active'] = $enabled;
        $this->state['managed_servers'] = $servers;
        $this->state['synchronized'] = false;
        return $this->state;
    }
}
