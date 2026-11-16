<?php

namespace App\Services\Sensor;

use App\Models\Setting;
use App\Services\{NetworkChanges,NetworkProfiles};
use RuntimeException;

/** Current boot-server readiness, independent of a module's historical provisioner. */
class ReprovisionStation
{
    public function __construct(private NetworkProfiles $profiles, private NetworkChanges $changes,
                                private ?string $bindingPath = null) {}

    public function current(): array
    {
        $stand = $this->profiles->stand($this->profiles->active());
        $state = $this->changes->roleState();
        if (!$stand || !($state['ok'] ?? false))
            throw new RuntimeException('The current provisioning interface and service role could not be verified. Check Settings → Network.');
        $binding = trim((string) @file_get_contents($this->bindingPath ?? '/etc/default/cmprovision-stand-interface'));
        return $stand + ['binding' => $binding, 'dhcp' => $state['result']['dhcp'] ?? []];
    }

    public function requireReady(): array
    {
        $state = $this->current();
        if ($state['address'] !== '172.20.0.1')
            throw new RuntimeException('This is a secondary station. Open the current primary provisioner to select its project and re-provision this module.');
        if ($state['binding'] !== $state['device'])
            throw new RuntimeException('The DHCP/TFTP interface binding does not match the current provisioning interface.');
        if (($state['dhcp']['active'] ?? false) !== true || ($state['dhcp']['enabled'] ?? '') !== 'enabled'
            || ($state['dhcp']['boot_enabled'] ?? false) !== true)
            throw new RuntimeException('The current primary DHCP/TFTP service must be running and enabled before re-provisioning.');
        return $state;
    }

    public static function validateOrigin(string $url): ?string
    {
        if (strlen($url) > 512 || preg_match('/[\x00-\x20\x7f]/', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return null;
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http','https'], true) || empty($p['host'])
            || array_intersect(['user','pass','query','fragment'], array_keys($p)) || !in_array($p['path'] ?? '', ['', '/'], true)
            || (isset($p['port']) && ($p['port'] < 1 || $p['port'] > 65535))) return null;
        return strtolower($p['scheme']).'://'.strtolower($p['host']).(isset($p['port']) ? ':'.$p['port'] : '');
    }

    public function handoff(?string $mac, ?string $address): ?string
    {
        $origin = self::validateOrigin((string) Setting::find('reprovision_primary_url')?->value);
        if (!$origin) return null;
        $hints = [];
        if ($mac && preg_match('/^(?:[a-f0-9]{2}:){5}[a-f0-9]{2}$/D', strtolower($mac))) $hints['mac'] = strtolower($mac);
        if ($address && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $hints['address'] = $address;
        return $origin.'/reprovision'.($hints ? '?'.http_build_query($hints, '', '&', PHP_QUERY_RFC3986) : '');
    }
}
