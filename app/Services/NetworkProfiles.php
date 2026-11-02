<?php

namespace App\Services;

/** Read active Ethernet and VLAN profile settings without asking NetworkManager for secrets. */
class NetworkProfiles
{
    const PROPERTIES = ['method', 'addresses', 'gateway', 'dns', 'routes', 'route-metric', 'never-default', 'ignore-auto-dns'];
    private $run;

    public function __construct(?callable $run = null)
    {
        $this->run = $run ?: function (array $command): ?string {
            $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($process)) {
                return null;
            }
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            return proc_close($process) === 0 ? trim($output) : null;
        };
    }

    public function active(): ?array
    {
        $out = $this->call(['timeout', '3', 'nmcli', '--escape', 'no', '-t', '-f', 'DEVICE,TYPE,STATE', 'device', 'status']);
        if ($out === null) {
            return null;
        }
        $profiles = [];
        foreach (explode("\n", $out) as $line) {
            $fields = explode(':', $line, 3);
            if (count($fields) !== 3 || !preg_match('/^[A-Za-z0-9_.-]{1,15}$/D', $fields[0]) ||
                !in_array($fields[1], ['ethernet', 'vlan'], true) || !str_starts_with($fields[2], 'connected')) {
                continue;
            }
            $uuid = $this->call(['timeout', '3', 'nmcli', '-g', 'GENERAL.CON-UUID', 'device', 'show', $fields[0]]);
            if ($uuid !== null && preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $uuid)) {
                $tag = $fields[1] === 'vlan' ? $this->call(['timeout', '3', 'nmcli', '-g', 'vlan.id',
                    'connection', 'show', 'uuid', $uuid]) : null;
                $profiles[] = ['device' => $fields[0], 'type' => $fields[1], 'uuid' => $uuid,
                    'vlan_id' => $tag !== null && ctype_digit($tag) ? (int) $tag : null];
            }
        }

        return $profiles;
    }

    /** The local primary (.1) or secondary (.2) stand address, when unique and on an active profile. */
    public function stand(?array $profiles): ?array
    {
        if ($profiles === null) return null;
        $output = $this->call(['timeout', '3', 'ip', '-j', '-4', 'address', 'show']);
        $devices = $output === null ? null : json_decode($output, true);
        if (!is_array($devices)) return null;
        $holders = [];
        foreach ($devices as $device) {
            foreach ($device['addr_info'] ?? [] as $address) {
                if (in_array($address['local'] ?? '', ['172.20.0.1', '172.20.0.2'], true) && ($address['prefixlen'] ?? null) === 16) {
                    $holders[] = ['device' => $device['ifname'] ?? '', 'address' => $address['local']];
                }
            }
        }
        if (count($holders) !== 1) return null;
        foreach ($profiles as $profile) {
            if ($profile['device'] === $holders[0]['device']) return $profile + ['address' => $holders[0]['address']];
        }
        return null;
    }

    public function detail(string $device, string $uuid): ?array
    {
        $list = $this->active();
        if ($list === null || !array_filter($list, fn ($profile) => $profile['device'] === $device && $profile['uuid'] === $uuid)) {
            return null;
        }
        $profile = [];
        foreach (['ipv4', 'ipv6'] as $family) {
            foreach (self::PROPERTIES as $property) {
                $value = $this->call(['timeout', '3', 'nmcli', '--escape', 'no', '-g', $family.'.'.$property,
                    'connection', 'show', 'uuid', $uuid]);
                if ($value === null) {
                    return null;
                }
                $profile[$family][$property] = $value;
            }
        }

        return $profile;
    }

    private function call(array $command): ?string
    {
        return call_user_func($this->run, $command);
    }
}
