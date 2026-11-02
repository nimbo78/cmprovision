<?php

namespace Tests\Unit;

use App\Services\VlanNetworks;
use PHPUnit\Framework\TestCase;

class VlanNetworksTest extends TestCase
{
    public function test_request_uses_fixed_command_and_json_on_stdin(): void
    {
        $calls = [];
        $networks = new VlanNetworks(function ($command, $input) use (&$calls) {
            $calls[] = [$command, $input];
            return [0, '{"ok":true,"result":{}}'];
        }, '/test/vlan-networks');
        $this->assertTrue($networks->add('eth0', 42, '192.168.50.2/24')['ok']);
        $this->assertSame(['timeout', '45', 'sudo', '-n', '/test/vlan-networks', 'add'], $calls[0][0]);
        $this->assertSame(['parent' => 'eth0', 'vlan_id' => 42, 'address' => '192.168.50.2/24'], json_decode($calls[0][1], true));
    }

    public function test_failures_and_invalid_output_are_reported(): void
    {
        $failure = new VlanNetworks(fn () => [1, '{"ok":false,"error":"refused"}']);
        $this->assertSame('refused', $failure->remove('id')['error']);
        $timeout = new VlanNetworks(fn () => [124, '']);
        $this->assertSame('The VLAN helper timed out.', $timeout->list()['error']);
        $bad = new VlanNetworks(fn () => [0, 'not json']);
        $this->assertFalse($bad->list()['ok']);
    }

    public function test_controls_are_available_only_with_networkmanager_managed_ethernet(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vlan-helper-');
        try {
            $available = new VlanNetworks(fn () => [0, "wifi:connected\nethernet:disconnected\n"], $file);
            $unmanaged = new VlanNetworks(fn () => [0, "wifi:connected\nethernet:unmanaged\n"], $file);
            $this->assertTrue($available->available());
            $this->assertFalse($unmanaged->available());
        } finally {
            unlink($file);
        }
    }
}
