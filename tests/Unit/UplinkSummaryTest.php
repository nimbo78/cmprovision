<?php

namespace Tests\Unit;

use App\Support\UplinkSummary;
use Tests\TestCase;

/** What the uplink icon and its tooltip say for each situation of the provisioner's network. */
class UplinkSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'Europe/Moscow']);
    }

    public static function card()
    {
        return [
            'name' => 'wlan1', 'kind' => 'wifi', 'bus' => 'pci', 'driver' => 'mt7921e', 'metric' => 100, 'up' => true,
            'addresses' => ['192.168.25.29/24'],
            'wifi' => ['ssid' => '78', 'freq' => 5765, 'band' => '5 GHz', 'channel' => 153, 'width' => 80, 'signal' => -45,
                       'rx' => 1200.9, 'tx' => 1200.9, 'generation' => '6', 'streams' => 2],
        ];
    }

    public static function onboard()
    {
        return [
            'name' => 'wlan0', 'kind' => 'wifi', 'bus' => 'sdio', 'driver' => 'brcmfmac', 'metric' => 600, 'up' => true,
            'addresses' => ['192.168.25.30/24'],
            'wifi' => ['ssid' => '78', 'freq' => 5765, 'band' => '5 GHz', 'channel' => 153, 'width' => 80, 'signal' => -54,
                       'rx' => 97.5, 'tx' => 24.0, 'generation' => null, 'streams' => null],
        ];
    }

    public static function modulesPort()
    {
        return [
            'name' => 'eth0', 'kind' => 'ethernet', 'bus' => 'platform', 'driver' => 'bcmgenet', 'metric' => null, 'up' => true,
            'addresses' => ['172.20.0.1/16'], 'speed' => 1000, 'duplex' => 'full',
        ];
    }

    public static function snapshot(array $changes = [])
    {
        return array_merge([
            'checked_at' => '2026-10-10T20:20:14+00:00',
            'uplink' => self::card(),
            'backups' => [self::onboard()],
            'modules' => self::modulesPort(),
            'preferred_down' => null,
        ], $changes);
    }

    public function test_wifi_6_card_with_a_backup_and_the_modules_network()
    {
        $v = UplinkSummary::from(self::snapshot());

        $this->assertSame('wifi', $v['icon']);
        $this->assertSame(3, $v['arcs']);
        $this->assertSame('6', $v['badge']);
        $this->assertSame('normal', $v['tone']);
        $this->assertSame('Internet via Wi-Fi 6, excellent signal, 1.2 Gbit/s', $v['label']);
        $this->assertSame('Internet via Wi-Fi', $v['heading']);
        $this->assertSame('Wi-Fi 6', $v['note']);
        $this->assertNull($v['warning']);
        $this->assertSame([
            ['Network', '78'],
            ['Speed', '1.2 Gbit/s both ways, 2 streams'],
            ['Signal', 'Excellent, −45 dBm'],
            ['Band', '5 GHz, channel 153, 80 MHz wide'],
            ['Adapter', 'M.2 card (wlan1)'],
            ['Address', '192.168.25.29'],
        ], $v['rows']);
        $this->assertSame(4, $v['bars']);
        $this->assertSame([
            ['Backup: built-in Wi-Fi (wlan0)', 'Connected, −54 dBm, 98 Mbit/s down, 24 Mbit/s up, 192.168.25.30'],
            ['Modules network: Ethernet (eth0)', '1 Gbit/s full duplex, 172.20.0.1/16'],
        ], $v['sections']);
        $this->assertSame('Checked at 23:20:14', $v['checked']);
    }

    public function test_running_on_the_backup_is_a_warning()
    {
        $v = UplinkSummary::from(self::snapshot([
            'uplink' => self::onboard(), 'backups' => [], 'preferred_down' => 'uplink-m2',
        ]));

        $this->assertSame('warn', $v['tone']);
        $this->assertNull($v['badge']);
        $this->assertNull($v['note']);
        $this->assertSame('Preferred connection uplink-m2 is not connected, the backup carries the traffic.', $v['warning']);
        $this->assertSame('Internet via Wi-Fi, excellent signal, 98 Mbit/s. Preferred connection uplink-m2 is not connected', $v['label']);
        $this->assertContains(['Speed', '98 Mbit/s down, 24 Mbit/s up'], $v['rows']);
        $this->assertContains(['Adapter', 'Built-in (wlan0)'], $v['rows']);
        $this->assertSame([['Modules network: Ethernet (eth0)', '1 Gbit/s full duplex, 172.20.0.1/16']], $v['sections']);
    }

    public function test_wired_uplink_shows_its_speed_on_the_icon()
    {
        $v = UplinkSummary::from(self::snapshot([
            'uplink' => ['name' => 'eth1', 'kind' => 'ethernet', 'bus' => 'usb', 'driver' => 'r8152', 'metric' => 100, 'up' => true,
                         'addresses' => ['10.0.0.5/24'], 'speed' => 2500, 'duplex' => 'full'],
            'backups' => [],
        ]));

        $this->assertSame('wired', $v['icon']);
        $this->assertSame('2.5G', $v['badge']);
        $this->assertSame('Internet via Ethernet, 2.5 Gbit/s', $v['label']);
        $this->assertSame('Internet via Ethernet', $v['heading']);
        $this->assertSame('2.5 Gbit/s', $v['note']);
        $this->assertSame([['Speed', '2.5 Gbit/s full duplex'], ['Adapter', 'USB adapter (eth1)'], ['Address', '10.0.0.5']], $v['rows']);
    }

    public function test_ethernet_badges()
    {
        foreach ([10 => '10M', 100 => '100M', 1000 => '1G', 2500 => '2.5G', 10000 => '10G'] as $speed => $badge) {
            $uplink = ['name' => 'eth1', 'kind' => 'ethernet', 'bus' => 'usb', 'driver' => 'r8152', 'metric' => 100, 'up' => true,
                       'addresses' => [], 'speed' => $speed, 'duplex' => 'full'];
            $this->assertSame($badge, UplinkSummary::from(self::snapshot(['uplink' => $uplink]))['badge']);
        }
    }

    public function test_no_default_route()
    {
        $v = UplinkSummary::from(self::snapshot(['uplink' => null, 'backups' => []]));

        $this->assertSame('offline', $v['icon']);
        $this->assertSame('bad', $v['tone']);
        $this->assertSame(0, $v['arcs']);
        $this->assertNull($v['badge']);
        $this->assertSame('No internet connection', $v['label']);
        $this->assertSame('No internet connection', $v['heading']);
        $this->assertSame('The server has no default route. Modules still provision: their network is separate.', $v['warning']);
        $this->assertSame([], $v['rows']);
        $this->assertSame([['Modules network: Ethernet (eth0)', '1 Gbit/s full duplex, 172.20.0.1/16']], $v['sections']);
    }

    public function test_modules_port_without_a_cable()
    {
        $port = array_merge(self::modulesPort(), ['up' => false, 'speed' => null, 'duplex' => null]);

        $v = UplinkSummary::from(self::snapshot(['modules' => $port]));

        $this->assertContains(['Modules network: Ethernet (eth0)', 'No link, 172.20.0.1/16'], $v['sections']);
    }

    public function test_weaker_signal_lights_fewer_arcs()
    {
        $card = self::card();
        $card['wifi']['signal'] = -72;

        $v = UplinkSummary::from(self::snapshot(['uplink' => $card]));

        $this->assertSame(2, $v['arcs']);
        $this->assertSame(2, $v['bars']);
        $this->assertContains(['Signal', 'Fair, −72 dBm'], $v['rows']);
        $this->assertSame('Internet via Wi-Fi 6, fair signal, 1.2 Gbit/s', $v['label']);
    }
}
