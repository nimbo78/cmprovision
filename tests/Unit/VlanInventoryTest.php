<?php

namespace Tests\Unit;

use App\Services\VlanInventory;
use PHPUnit\Framework\TestCase;

class VlanInventoryTest extends TestCase
{
    public function test_it_reports_vlan_parent_addresses_and_the_provisioning_role(): void
    {
        $links = [
            ['ifindex' => 2, 'ifname' => 'eth0'],
            ['ifindex' => 3, 'ifname' => 'eth0.42', 'link_index' => 2, 'operstate' => 'UP',
                'linkinfo' => ['info_kind' => 'vlan', 'info_data' => ['id' => 42]]],
            ['ifindex' => 4, 'ifname' => 'eth0.99', 'link' => 'eth0', 'operstate' => 'DOWN',
                'linkinfo' => ['info_kind' => 'vlan', 'info_data' => ['id' => 99]]],
        ];
        $addresses = [['ifname' => 'eth0.42', 'addr_info' => [
            ['family' => 'inet', 'local' => '172.20.0.1', 'prefixlen' => 16],
        ]]];
        $inventory = new VlanInventory(fn ($cmd) => json_encode(str_contains($cmd, 'link show') ? $links : $addresses));
        $result = $inventory->read();
        $this->assertCount(2, $result);
        $this->assertSame(['name' => 'eth0.42', 'parent' => 'eth0', 'id' => 42, 'state' => 'UP',
            'addresses' => ['172.20.0.1/16'], 'stand' => true], $result[0]);
        $this->assertFalse($result[1]['stand']);
    }

    public function test_failure_is_distinct_from_an_empty_inventory(): void
    {
        $this->assertNull((new VlanInventory(fn () => null))->read());
        $this->assertNull((new VlanInventory(fn () => '{bad'))->read());
        $this->assertSame([], (new VlanInventory(fn () => '[]'))->read());
    }
}
