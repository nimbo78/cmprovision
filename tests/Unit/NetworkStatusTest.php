<?php

namespace Tests\Unit;

use App\Services\NetworkStatus;
use PHPUnit\Framework\TestCase;

/**
 * How the provisioner reaches the network, read the way the web server user can: default routes from
 * /proc/net/route, interfaces from /sys/class/net, Wi-Fi details from `iw`, addresses from PHP, and
 * NetworkManager's profiles to notice that the preferred uplink is down.
 */
class NetworkStatusTest extends TestCase
{
    private $root;
    private $calls = [];
    private $nmcli = [];

    /** /proc/net/route of the provisioner on 2026-10-10: M.2 card first, the onboard radio as a fallback */
    const ROUTES = "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\t\tMTU\tWindow\tIRTT\n"
        ."wlan1\t00000000\t0119A8C0\t0003\t0\t0\t100\t00000000\t0\t0\t0\n"
        ."wlan0\t00000000\t0119A8C0\t0003\t0\t0\t600\t00000000\t0\t0\t0\n"
        ."eth0\t000014AC\t00000000\t0001\t0\t0\t100\t0000FFFF\t0\t0\t0\n"
        ."wlan1\t0019A8C0\t00000000\t0001\t0\t0\t100\t00FFFFFF\t0\t0\t0\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/netstatus-'.uniqid();
        mkdir($this->root.'/net', 0777, true);
        file_put_contents($this->root.'/route', self::ROUTES);
        $this->iface('wlan1', 'pci', 'mt7921e', '4c:d5:77:f7:ad:fb', true);
        $this->iface('wlan0', 'sdio', 'brcmfmac', '2c:cf:67:30:7d:a3', true);
        $this->iface('eth0', 'platform', 'bcmgenet', '2c:cf:67:30:7d:a2', false, ['speed' => "1000\n", 'duplex' => "full\n"]);
        $this->nmcli = [
            'list' => "ab18b939:802-11-wireless:wlan1:yes\n74a0d3da:802-11-wireless:wlan0:yes\n"
                ."d7fe05dc:802-3-ethernet:eth0:yes\na9da3f84:loopback:lo:yes\n",
        ];
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    /** a /sys/class/net/<name> with its device, driver and bus links */
    private function iface($name, $bus, $driver, $mac, $wireless, array $files = [])
    {
        $dir = $this->root.'/net/'.$name;
        $device = $this->root.'/devices/'.$name;
        @mkdir($this->root.'/drivers/'.$driver, 0777, true);
        @mkdir($this->root.'/bus/'.$bus, 0777, true);
        mkdir($device, 0777, true);
        mkdir($dir);
        if (!@symlink($this->root.'/drivers/'.$driver, $device.'/driver')) {
            $this->markTestSkipped('symlinks are not available here');
        }
        symlink($this->root.'/bus/'.$bus, $device.'/subsystem');
        symlink($device, $dir.'/device');
        if ($wireless) {
            mkdir($dir.'/wireless');
        }
        $files += ['address' => $mac."\n", 'type' => "1\n", 'operstate' => "up\n", 'carrier' => "1\n"];
        foreach ($files as $file => $content) {
            file_put_contents($dir.'/'.$file, $content);
        }
    }

    private function status(?array $addresses = null)
    {
        $run = function ($command) {
            $this->calls[] = $command;
            if (preg_match("/iw dev '(\\w+)' (link|info)/", $command, $m)) {
                $out = [
                    'wlan1 link' => WifiLinkTest::HE_LINK, 'wlan1 info' => WifiLinkTest::HE_INFO,
                    'wlan0 link' => WifiLinkTest::ONBOARD_LINK, 'wlan0 info' => WifiLinkTest::ONBOARD_INFO,
                ];
                return isset($out[$m[1].' '.$m[2]]) ? $out[$m[1].' '.$m[2]] : "Not connected.\n";
            }
            if (strpos($command, 'nmcli -t -f UUID,TYPE,DEVICE,ACTIVE connection show') !== false) {
                return isset($this->nmcli['list']) ? $this->nmcli['list'] : null;
            }
            if (preg_match("/nmcli -g [a-z0-9.,-]+ connection show '([\\w-]+)'/", $command, $m)) {
                return isset($this->nmcli[$m[1]]) ? $this->nmcli[$m[1]] : null;
            }
            return null;
        };
        $addresses = $addresses !== null ? $addresses : [
            'lo' => ['unicast' => [['family' => 2, 'address' => '127.0.0.1', 'netmask' => '255.0.0.0']]],
            'eth0' => ['unicast' => [['family' => 2, 'address' => '172.20.0.1', 'netmask' => '255.255.0.0'],
                                     ['family' => 10, 'address' => 'fe80::2ecf:67ff:fe30:7da2']]],
            'wlan0' => ['unicast' => [['family' => 2, 'address' => '192.168.25.30', 'netmask' => '255.255.255.0']]],
            'wlan1' => ['unicast' => [['family' => 2, 'address' => '192.168.25.29', 'netmask' => '255.255.255.0']]],
        ];

        return (new NetworkStatus($this->root.'/net', $this->root.'/route', $run, function () use ($addresses) {
            return $addresses;
        }))->read();
    }

    public function test_the_uplink_is_the_default_route_with_the_lowest_metric()
    {
        $status = $this->status();

        $uplink = $status['uplink'];
        $this->assertSame('wlan1', $uplink['name']);
        $this->assertSame('wifi', $uplink['kind']);
        $this->assertSame('pci', $uplink['bus']);
        $this->assertSame('mt7921e', $uplink['driver']);
        $this->assertSame(100, $uplink['metric']);
        $this->assertSame(['192.168.25.29/24'], $uplink['addresses']);
        $this->assertSame('78', $uplink['wifi']['ssid']);
        $this->assertSame('6', $uplink['wifi']['generation']);
        $this->assertNull($status['preferred_down']);
        $this->assertNotEmpty($status['checked_at']);
    }

    public function test_other_default_routes_are_backups()
    {
        $backups = $this->status()['backups'];

        $this->assertCount(1, $backups);
        $this->assertSame('wlan0', $backups[0]['name']);
        $this->assertSame('sdio', $backups[0]['bus']);
        $this->assertSame(-54, $backups[0]['wifi']['signal']);
        $this->assertSame(['192.168.25.30/24'], $backups[0]['addresses']);
    }

    public function test_the_modules_network_is_the_interface_holding_the_server_address()
    {
        $modules = $this->status()['modules'];

        $this->assertSame('eth0', $modules['name']);
        $this->assertSame('ethernet', $modules['kind']);
        $this->assertTrue($modules['up']);
        $this->assertSame(1000, $modules['speed']);
        $this->assertSame('full', $modules['duplex']);
        $this->assertSame(['172.20.0.1/16'], $modules['addresses']);
        $this->assertArrayNotHasKey('wifi', $modules);
    }

    public function test_an_unplugged_cable_has_no_speed()
    {
        file_put_contents($this->root.'/net/eth0/carrier', "0\n");
        file_put_contents($this->root.'/net/eth0/operstate', "down\n");
        unlink($this->root.'/net/eth0/speed');

        $modules = $this->status()['modules'];

        $this->assertFalse($modules['up']);
        $this->assertNull($modules['speed']);
    }

    public function test_a_preferred_profile_that_is_not_connected_is_reported()
    {
        file_put_contents($this->root.'/route', "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\t\tMTU\tWindow\tIRTT\n"
            ."wlan0\t00000000\t0119A8C0\t0003\t0\t0\t600\t00000000\t0\t0\t0\n");
        $this->nmcli['list'] = "ab18b939:802-11-wireless::no\n74a0d3da:802-11-wireless:wlan0:yes\nd7fe05dc:802-3-ethernet:eth0:yes\n"
            ."0c1d2e3f:802-3-ethernet::no\n";
        $this->nmcli['ab18b939'] = "uplink-m2\nyes\n100\nno\n";
        $this->nmcli['0c1d2e3f'] = "spare\nno\n50\nno\n";   // not started on its own: not a preference

        $status = $this->status();

        $this->assertSame('wlan0', $status['uplink']['name']);
        $this->assertSame('uplink-m2', $status['preferred_down']);
    }

    public function test_a_profile_with_a_worse_metric_or_without_a_default_route_is_no_preference()
    {
        $this->nmcli['list'] = "ab18b939:802-11-wireless:wlan1:yes\n74a0d3da:802-11-wireless:wlan0:yes\n"
            ."11111111:802-11-wireless::no\n22222222:802-3-ethernet::no\n33333333:802-3-ethernet::no\n";
        $this->nmcli['11111111'] = "guest\nyes\n700\nno\n";
        $this->nmcli['22222222'] = "lab\nyes\n-1\nyes\n";    // never carries a default route
        $this->nmcli['33333333'] = "dock\nyes\n-1\nno\n";    // NetworkManager's default for Ethernet is 100, not below 100

        $this->assertNull($this->status()['preferred_down']);
    }

    public function test_without_networkmanager_nothing_is_reported_as_down()
    {
        unset($this->nmcli['list']);

        $status = $this->status();

        $this->assertSame('wlan1', $status['uplink']['name']);
        $this->assertNull($status['preferred_down']);
    }

    public function test_no_default_route_means_no_uplink()
    {
        file_put_contents($this->root.'/route', "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\t\tMTU\tWindow\tIRTT\n"
            ."eth0\t000014AC\t00000000\t0001\t0\t0\t100\t0000FFFF\t0\t0\t0\n");

        $status = $this->status();

        $this->assertNull($status['uplink']);
        $this->assertSame([], $status['backups']);
        $this->assertSame('eth0', $status['modules']['name']);
    }

    public function test_iw_is_asked_only_about_wireless_interfaces()
    {
        $this->status();

        $iw = array_values(array_filter($this->calls, function ($c) { return strpos($c, 'iw dev') !== false; }));
        $this->assertCount(4, $iw);
        $this->assertStringNotContainsString("'eth0'", implode(' ', $iw));
    }

    public function test_nothing_to_report_off_linux()
    {
        unlink($this->root.'/route');

        $this->assertNull($this->status());
    }
}
