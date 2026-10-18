<?php

namespace App\Services;

use App\Models\Cm;
use Symfony\Component\HttpFoundation\IpUtils;

class SensorAddress
{
    public function __construct(private ?string $leases = null, private ?array $localAddresses = null) {}

    public function find(Cm $cm): ?array
    {
        $mac = strtolower((string) $cm->mac);
        if (!preg_match('/^(?:[a-f0-9]{2}:){5}[a-f0-9]{2}$/D', $mac)) return null;
        $local = array_merge([NetworkStatus::SERVER_ADDRESS], $this->localAddresses ?? $this->locals());
        $found = []; $all = [];
        foreach (@file($this->leases ?? base_path('etc/dnsmasq.leases'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) < 4 || !ctype_digit($f[0]) || ((int) $f[0] !== 0 && (int) $f[0] <= now()->timestamp)) continue;
            [$expiry, $leaseMac, $ip, $host] = $f;
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                || !IpUtils::checkIp($ip, NetworkStatus::STAND_NETWORK)
                || in_array($ip, array_merge($local, ['172.20.0.0', '172.20.255.255']), true)) continue;
            $all[$ip][strtolower($leaseMac)] = true;
            if (strtolower($leaseMac) === $mac)
                $found[$ip] = ['address' => $ip, 'hostname' => $host === '*' ? null : substr($host, 0, 255), 'mac' => $mac];
        }
        if (count($found) !== 1) return null;
        $target = reset($found);
        return count($all[$target['address']]) === 1 ? $target : null;
    }

    private function locals(): array
    {
        $ips = [];
        foreach (net_get_interfaces() ?: [] as $interface)
            foreach ($interface['unicast'] ?? [] as $entry)
                if (isset($entry['address'])) $ips[] = $entry['address'];
        return $ips;
    }
}
