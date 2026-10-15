<?php

namespace Tests\Support;

use App\Services\WifiNetworks;

/** WifiNetworks for the settings page tests: the list is set by the test, every change is recorded */
class FakeWifiNetworks extends WifiNetworks
{
    public $available = true;
    public $networks = [];
    public $others = [];
    public $blocker = null;
    public $scanned = [];
    public $scanError = null;
    /** [method, arguments...] of every change */
    public $calls = [];
    /** method => what it returns instead of success */
    public $answers = [];

    public function __construct()
    {
        parent::__construct(function () {
            return [1, ''];
        });
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function list(): array
    {
        return ['networks' => $this->networks, 'others' => $this->others];
    }

    public function checkBlocker(): ?string
    {
        return $this->blocker;
    }

    public function save(array $network, string $by): array
    {
        $this->calls[] = ['save', $network, $by];

        return $this->answers['save'] ?? ['uuid' => $network['uuid'] ?: 'new-uuid', 'error' => null];
    }

    public function delete(string $uuid, string $by): ?string
    {
        $this->calls[] = ['delete', $uuid, $by];

        return $this->answers['delete'] ?? null;
    }

    public function order(array $uuids, string $by): ?string
    {
        $this->calls[] = ['order', $uuids, $by];

        return $this->answers['order'] ?? null;
    }

    public function check(string $uuid, string $by): array
    {
        $this->calls[] = ['check', $uuid, $by];

        return $this->answers['check'] ?? ['result' => 'connected', 'address' => '192.168.68.236/24', 'detail' => ''];
    }

    public function scan(): array
    {
        return ['networks' => $this->scanned, 'error' => $this->scanError];
    }

    public function log(): string
    {
        return "Oct 15 12:00:00 wlanpi-da2 cmprovision-wifi[1]: anna saved '78' (WPA2)\n";
    }
}
