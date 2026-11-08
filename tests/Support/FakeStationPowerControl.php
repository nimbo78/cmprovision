<?php

namespace Tests\Support;

use App\Services\StationPowerControl;

class FakeStationPowerControl extends StationPowerControl
{
    public array $calls = [];
    public bool $installed = true;
    public ?array $scheduled = null;
    public bool $refuse = false;
    public function available(): bool { return $this->installed; }
    public function bootId(): string { return '11111111-1111-4111-8111-111111111111'; }
    public function pending(): ?array { return $this->scheduled; }
    public function schedule(string $action): array
    {
        if ($this->refuse) throw new \RuntimeException('Power controller refused the request.');
        $this->calls[] = $action;
        return $this->scheduled = ['action' => $action, 'boot_id' => $this->bootId(), 'scheduled_at' => time(), 'delay' => 5];
    }
}
