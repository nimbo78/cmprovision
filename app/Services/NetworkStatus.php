<?php

namespace App\Services;

use App\Support\WifiLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * How the provisioner reaches the network, for the indicator in the top bar. Everything is read the way
 * the web server user can: default routes from /proc/net/route, interfaces from /sys/class/net, Wi-Fi
 * details from `iw`, addresses from PHP, and NetworkManager's profiles (nmcli) to notice that the
 * preferred uplink is down. Open tabs share one cached snapshot.
 */
class NetworkStatus
{
    /** the modules' network is the interface holding the server address (etc/dnsmasq.conf, scriptexecute/cmdline.txt) */
    const SERVER_ADDRESS = '172.20.0.1';
    const CACHE_KEY = 'network-status';
    /** the indicator refreshes on its own this often */
    const POLL_SECONDS = 300;
    /** such a refresh may reuse a snapshot this old: below the poll interval, so what is shown stays under 5 minutes old */
    const PASSIVE_MAX_AGE = 240;
    /** pointing at the icon shows a snapshot at most this old */
    const FRESH_MAX_AGE = 10;

    private $net;
    private $routes;
    private $run;
    private $addresses;

    /**
     * @param callable|null $run       runs a shell command, returns its output or null when it failed
     * @param callable|null $addresses returns the interfaces with their addresses, as net_get_interfaces() does
     */
    public function __construct(string $net = '/sys/class/net', string $routes = '/proc/net/route',
                                ?callable $run = null, ?callable $addresses = null)
    {
        $this->net = $net;
        $this->routes = $routes;
        $this->run = $run ?: function ($command) {
            $output = [];
            $code = 1;
            @exec($command.' 2>/dev/null', $output, $code);
            return $code === 0 ? implode("\n", $output)."\n" : null;
        };
        $this->addresses = $addresses ?: function () {
            return function_exists('net_get_interfaces') ? (@net_get_interfaces() ?: []) : [];
        };
    }

    /** the snapshot, read again when the cached one is older than $maxAge seconds; null where nothing can be read */
    public function current(int $maxAge): ?array
    {
        $now = Carbon::now()->getTimestamp();
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && $now - $cached['at'] <= $maxAge) {
            return $cached['status'];
        }
        $status = $this->read();
        Cache::put(self::CACHE_KEY, ['at' => $now, 'status' => $status], 2 * self::POLL_SECONDS);

        return $status;
    }

    /**
     * @return array|null uplink (the default route with the lowest metric), backups (the other default routes),
     *                    modules (the interface holding the server address), preferred_down (the name of a
     *                    NetworkManager profile that should carry the traffic but is not connected), checked_at;
     *                    null off Linux
     */
    public function read(): ?array
    {
        $routes = $this->defaultRoutes();
        if ($routes === null) {
            return null;
        }
        $addresses = $this->ipv4(call_user_func($this->addresses));

        $uplinks = [];
        foreach ($routes as $name => $metric) {
            $uplinks[] = $this->describe($name, $addresses, $metric);
        }
        $modules = null;
        foreach ($addresses as $name => $list) {
            foreach ($list as $cidr) {
                if (strpos($cidr, self::SERVER_ADDRESS.'/') === 0) {
                    $modules = $this->describe($name, $addresses, null);
                }
            }
        }
        $uplink = array_shift($uplinks);

        return [
            'checked_at' => Carbon::now()->toIso8601String(),
            'uplink' => $uplink,
            'backups' => $uplinks,
            'modules' => $modules,
            'preferred_down' => $uplink === null ? null : $this->preferredDown($uplink['metric']),
        ];
    }

    /** interface => metric of its default route, best first; null when the routing table cannot be read */
    private function defaultRoutes(): ?array
    {
        if (!is_readable($this->routes)) {
            return null;
        }
        $routes = [];
        foreach (array_slice(file($this->routes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], 1) as $line) {
            $f = preg_split('/\s+/', trim($line));
            // Iface Destination Gateway Flags RefCnt Use Metric Mask ...; flag 1 is RTF_UP
            if (count($f) < 8 || $f[1] !== '00000000' || $f[7] !== '00000000' || !(hexdec($f[3]) & 1)) {
                continue;
            }
            $metric = (int) $f[6];
            if (!isset($routes[$f[0]]) || $metric < $routes[$f[0]]) {
                $routes[$f[0]] = $metric;
            }
        }
        asort($routes);

        return $routes;
    }

    private function describe(string $name, array $addresses, ?int $metric): array
    {
        $dir = $this->net.'/'.$name;
        $wireless = is_dir($dir.'/wireless') || file_exists($dir.'/phy80211');
        $iface = [
            'name' => $name,
            'kind' => $wireless ? 'wifi' : ($this->value($dir.'/type') === '1' ? 'ethernet' : 'other'),
            'bus' => $this->linkName($dir.'/device/subsystem'),
            'driver' => $this->linkName($dir.'/device/driver'),
            'metric' => $metric,
            'up' => $this->value($dir.'/carrier') === '1',
            'addresses' => isset($addresses[$name]) ? $addresses[$name] : [],
        ];
        if ($wireless) {
            $iw = $this->iw();
            $link = $this->run($iw.' dev '.escapeshellarg($name).' link');
            $info = $this->run($iw.' dev '.escapeshellarg($name).' info');
            $iface['wifi'] = WifiLink::parse((string) $link, (string) $info);
        } elseif ($iface['kind'] === 'ethernet') {
            // reading speed fails while there is no link; -1 means the driver does not know
            $speed = $this->value($dir.'/speed');
            $duplex = $this->value($dir.'/duplex');
            $iface['speed'] = $speed !== null && ctype_digit($speed) && (int) $speed > 0 ? (int) $speed : null;
            $iface['duplex'] = in_array($duplex, ['full', 'half'], true) ? $duplex : null;
        }

        return $iface;
    }

    /** a NetworkManager profile that would carry the default route better than the current uplink, but is not connected */
    private function preferredDown(int $uplinkMetric): ?string
    {
        $list = $this->run('timeout 3 nmcli -t -f UUID,TYPE,DEVICE,ACTIVE connection show');
        if ($list === null) {
            return null;
        }
        foreach (explode("\n", trim($list)) as $line) {
            $f = explode(':', $line);
            if (count($f) < 4 || $f[3] === 'yes' || !in_array($f[1], ['802-11-wireless', '802-3-ethernet'], true)) {
                continue;
            }
            $values = $this->run('timeout 3 nmcli -g connection.id,connection.autoconnect,ipv4.route-metric,ipv4.never-default'
                .' connection show '.escapeshellarg($f[0]));
            $v = $values === null ? [] : explode("\n", rtrim($values, "\n"));
            if (count($v) < 4 || $v[1] !== 'yes' || $v[3] === 'yes') {
                continue;
            }
            // -1: NetworkManager's default, 100 for Ethernet and 600 for Wi-Fi
            $metric = (int) $v[2] >= 0 ? (int) $v[2] : ($f[1] === '802-3-ethernet' ? 100 : 600);
            if ($metric < $uplinkMetric) {
                return $v[0];
            }
        }

        return null;
    }

    /** interface => ['a.b.c.d/prefix', ...] from net_get_interfaces() */
    private function ipv4(array $interfaces): array
    {
        $result = [];
        foreach ($interfaces as $name => $interface) {
            foreach (isset($interface['unicast']) ? $interface['unicast'] : [] as $address) {
                if ((isset($address['family']) ? $address['family'] : null) !== 2 || empty($address['address'])) {
                    continue;
                }
                $prefix = '';
                if (!empty($address['netmask'])) {
                    $bits = 0;
                    foreach (explode('.', $address['netmask']) as $octet) {
                        $bits += substr_count(decbin((int) $octet), '1');
                    }
                    $prefix = '/'.$bits;
                }
                $result[$name][] = $address['address'].$prefix;
            }
        }

        return $result;
    }

    private function iw(): string
    {
        foreach (['/usr/sbin/iw', '/sbin/iw'] as $path) {
            if (is_executable($path)) {
                return 'timeout 3 '.$path;
            }
        }

        return 'timeout 3 iw';
    }

    private function run(string $command): ?string
    {
        return call_user_func($this->run, $command);
    }

    private function value(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path);

        return $content === false ? null : trim($content);
    }

    private function linkName(string $path): ?string
    {
        $target = @readlink($path);

        return $target === false ? null : basename($target);
    }
}
