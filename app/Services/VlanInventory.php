<?php

namespace App\Services;

/** Read active kernel VLANs without requiring privileges or exposing NetworkManager secrets. */
class VlanInventory
{
    private $run;

    public function __construct(?callable $run = null)
    {
        $this->run = $run ?: function (string $command): ?string {
            $output = [];
            $code = 1;
            @exec($command.' 2>/dev/null', $output, $code);

            return $code === 0 ? implode("\n", $output) : null;
        };
    }

    /** @return array|null null means that the kernel inventory could not be read */
    public function read(): ?array
    {
        $links = $this->json('ip -j -d link show');
        $addresses = $this->json('ip -j -4 address show');
        if ($links === null || $addresses === null) {
            return null;
        }
        $names = [];
        foreach ($links as $link) {
            if (isset($link['ifindex'], $link['ifname'])) {
                $names[$link['ifindex']] = $link['ifname'];
            }
        }
        $ips = [];
        foreach ($addresses as $device) {
            if (!isset($device['ifname']) || !is_string($device['ifname'])) {
                continue;
            }
            foreach ($device['addr_info'] ?? [] as $address) {
                if (($address['family'] ?? null) === 'inet' && isset($address['local'], $address['prefixlen'])) {
                    $ips[$device['ifname']][] = $address['local'].'/'.$address['prefixlen'];
                }
            }
        }
        $vlans = [];
        foreach ($links as $link) {
            $info = $link['linkinfo'] ?? [];
            $id = $info['info_data']['id'] ?? null;
            if (($info['info_kind'] ?? '') !== 'vlan' || !isset($link['ifname']) || !is_int($id) || $id < 1 || $id > 4094) {
                continue;
            }
            $name = $link['ifname'];
            $vlans[] = [
                'name' => $name,
                'parent' => $link['link'] ?? ($names[$link['link_index'] ?? 0] ?? 'unknown'),
                'id' => $id,
                'state' => $link['operstate'] ?? 'UNKNOWN',
                'addresses' => $ips[$name] ?? [],
                'stand' => in_array(NetworkStatus::SERVER_ADDRESS.'/16', $ips[$name] ?? [], true),
            ];
        }
        usort($vlans, fn ($a, $b) => strcmp($a['parent'], $b['parent']) ?: ($a['id'] <=> $b['id']));

        return $vlans;
    }

    private function json(string $command): ?array
    {
        $out = call_user_func($this->run, $command);
        if ($out === null) {
            return null;
        }
        $data = json_decode($out, true);

        return is_array($data) && array_is_list($data) ? $data : null;
    }
}
