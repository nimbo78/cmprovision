<?php

namespace Tests\Unit;

use App\Services\NetworkProfiles;
use PHPUnit\Framework\TestCase;

class NetworkProfilesTest extends TestCase
{
    const UUID = 'd6e8fe66-09dc-40a6-9b80-358dff6da460';

    public function test_lists_only_active_wired_or_tagged_profiles_and_reads_no_secrets(): void
    {
        $calls = [];
        $reader = new NetworkProfiles(function ($command) use (&$calls) {
            $calls[] = $command;
            if (in_array('status', $command, true))
                return "eth0:ethernet:connected\neth0.42:vlan:connected\nwlan0:wifi:connected\neth1:ethernet:disconnected\n";
            if (in_array('GENERAL.CON-UUID', $command, true)) return self::UUID;
            return 'auto';
        });
        $list = $reader->active();
        $this->assertCount(2, $list);
        $this->assertSame('eth0.42', $list[1]['device']);
        $this->assertNotNull($reader->detail('eth0', self::UUID));
        $this->assertNull($reader->detail('wlan0', self::UUID));
        foreach ($calls as $call) $this->assertNotContains('--show-secrets', $call);
    }

    public function test_unavailable_networkmanager_is_distinct_from_no_active_profiles(): void
    {
        $this->assertNull((new NetworkProfiles(fn () => null))->active());
        $this->assertSame([], (new NetworkProfiles(fn () => ''))->active());
    }

    public function test_finds_only_a_unique_active_primary_or_secondary_stand_address(): void
    {
        $profiles = [['device' => 'eth0.42', 'type' => 'vlan', 'uuid' => self::UUID]];
        $reader = new NetworkProfiles(fn () => '[{"ifname":"eth0.42","addr_info":[{"local":"172.20.0.2","prefixlen":16}]}]');
        $this->assertSame('172.20.0.2', $reader->stand($profiles)['address']);
        $ambiguous = new NetworkProfiles(fn () => '[{"ifname":"eth0.42","addr_info":[{"local":"172.20.0.1","prefixlen":16},{"local":"172.20.0.2","prefixlen":16}]}]');
        $this->assertNull($ambiguous->stand($profiles));
        $this->assertNull($reader->stand([]));
    }

    public function test_saved_inventory_includes_inactive_profiles_without_wifi_or_secrets(): void
    {
        $calls = [];
        $reader = new NetworkProfiles(function ($command) use (&$calls) {
            $calls[] = $command;
            if (in_array('UUID,TYPE', $command, true))
                return self::UUID.":vlan\n97d071a5-5fc9-47ba-90ec-904f105cc7b2:802-11-wireless";
            if (in_array('connection.id', $command, true)) return 'Lab: management';
            if (in_array('connection.interface-name', $command, true)) return 'eth0.42';
            if (in_array('DEVICE,TYPE', $command, true)) return "eth0:ethernet\nwlan0:wifi\neth0.42:vlan";
            return null;
        });
        $this->assertSame([['uuid' => self::UUID, 'name' => 'Lab: management', 'device' => 'eth0.42', 'type' => 'vlan']], $reader->saved());
        $this->assertSame(['eth0'], $reader->ethernetDevices());
        foreach ($calls as $call) $this->assertNotContains('--show-secrets', $call);
    }
}
