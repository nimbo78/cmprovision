<?php

namespace Tests\Unit;

use App\Services\NetworkChanges;
use PHPUnit\Framework\TestCase;

class NetworkChangesTest extends TestCase
{
    public function test_profile_and_confirmation_are_passed_as_json_not_shell_arguments(): void
    {
        $calls = [];
        $service = new NetworkChanges(function ($command, $input) use (&$calls) {
            $calls[] = [$command, json_decode($input, true)];
            return [0, '{"ok":true,"result":{}}'];
        }, '/test/network-transaction');
        $this->assertTrue($service->begin('eth0.42', 'uuid', ['ipv4' => ['method' => 'auto']])['ok']);
        $this->assertSame(['timeout', '55', 'sudo', '-n', '/test/network-transaction', 'begin'], $calls[0][0]);
        $this->assertSame(['device' => 'eth0.42', 'uuid' => 'uuid', 'profile' => ['ipv4' => ['method' => 'auto']]], $calls[0][1]);
        $service->confirm('secret');
        $this->assertSame(['token' => 'secret'], $calls[1][1]);
        $service->moveStand(['device' => 'eth0', 'uuid' => 'source'], ['device' => 'eth0.42', 'uuid' => 'target']);
        $this->assertSame(['kind' => 'stand', 'source_device' => 'eth0', 'source_uuid' => 'source',
            'target_device' => 'eth0.42', 'target_uuid' => 'target'], $calls[2][1]);
        $service->connection('eth0.42', 'target', true);
        $this->assertSame(['kind' => 'connection', 'action' => 'activate', 'device' => 'eth0.42', 'uuid' => 'target'], $calls[3][1]);
        $service->configureStand(['device' => 'eth0.42', 'uuid' => 'target'], 'primary', false, true);
        $this->assertSame(['kind' => 'stand-role', 'device' => 'eth0.42', 'uuid' => 'target', 'role' => 'primary',
            'usb' => false, 'isolated' => true], $calls[4][1]);
    }

    public function test_helper_failure_and_timeout_are_reported(): void
    {
        $timeout = new NetworkChanges(fn () => [124, '']);
        $this->assertStringContainsString('timed out', $timeout->status()['error']);
        $failed = new NetworkChanges(fn () => [1, '{"ok":false,"error":"busy"}']);
        $this->assertSame('busy', $failed->status()['error']);
    }
}
