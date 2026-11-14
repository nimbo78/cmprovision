<?php

namespace App\Services\Sensor;

use App\Models\EthernetSwitch;
use App\Services\{NetworkStatus,SensorAddress};
use App\Services\Switch\Stand;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\Process\Process;

/** Resolve one stand-only target. Hints are never authority to send SSH or erase a disk. */
class ReprovisionTarget
{
    private $run;
    public function __construct(private SensorAddress $leases, private ReprovisionStation $station,
                                ?callable $run = null, private ?array $localAddresses = null)
    {
        $this->run = $run ?? function (array $argv): ?string {
            try {
                $p = new Process($argv); $p->setTimeout(4)->run();
                return $p->isSuccessful() && strlen($p->getOutput()) < 8192 ? $p->getOutput() : null;
            } catch (\Throwable $e) { return null; }
        };
    }

    public function resolve(?string $mac, ?string $address): array
    {
        $mac = $this->normalizeMac($mac);
        $address = $address === '' ? null : $address;
        if ($address !== null) $this->validateAddress($address);
        $stand = $this->station->requireReady();
        $claims = $this->leases->currentLeases();
        if ($lease = $this->lease($mac,$address,$claims)) {
            $mac = $this->normalizeMac($lease['mac']);
            $this->validateAddress($lease['address']); $this->protectMac($mac);
            return ['address'=>$lease['address'],'mac'=>$mac,'proof'=>'lease','stand'=>$stand];
        }
        if ($address === null) throw new RuntimeException('No unambiguous current DHCP lease. Enter the module IPv4 address to verify its stand binding.');
        // Refresh only this destination on this interface; never scan or let routing choose an uplink.
        ($this->run)(['ping','-n','-c','1','-W','1','-I',$stand['device'],$address]);
        $out = ($this->run)(['ip','-j','neigh','show','to',$address,'dev',$stand['device']]);
        $rows = $out === null ? null : json_decode($out,true);
        $found = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $m = strtolower((string) ($row['lladdr'] ?? ''));
            if (($row['dst'] ?? '') === $address && ($row['dev'] ?? '') === $stand['device']
                && ($row['state'] ?? []) === ['REACHABLE'] && preg_match('/^(?:[a-f0-9]{2}:){5}[a-f0-9]{2}$/D',$m)
                && !(hexdec(substr($m,0,2)) & 1) && $m !== '00:00:00:00:00:00') $found[$m] = true;
        }
        if (count($found) !== 1 || ($mac !== null && !isset($found[$mac])))
            throw new RuntimeException('A fresh matching IP/MAC binding on the provisioning interface could not be verified. Check the cable and address.');
        $mac = array_key_first($found);
        $this->lease($mac,$address,$claims); // A learned MAC may have contradictory leases at another address.
        $this->protectMac($mac);
        return ['address'=>$address,'mac'=>$mac,'proof'=>'neighbor','stand'=>$stand];
    }

    private function normalizeMac(?string $mac): ?string
    {
        if ($mac === null || $mac === '') return null;
        $mac = strtolower($mac);
        if (!preg_match('/^(?:[a-f0-9]{2}:){5}[a-f0-9]{2}$/D',$mac)
            || (hexdec(substr($mac,0,2)) & 1) || $mac === '00:00:00:00:00:00')
            throw new RuntimeException('Enter a valid unicast MAC address.');
        return $mac;
    }

    /** Validate the complete relevant lease graph before either DHCP or L2 can authorize a candidate. */
    private function lease(?string $mac, ?string $address, array $claims): ?array
    {
        $atAddress = array_values(array_filter($claims,fn ($l) => $address !== null && $l['address'] === $address));
        $owners = array_values(array_unique(array_column($atAddress,'mac')));
        if (count($owners) > 1 || ($mac !== null && $owners && $owners[0] !== $mac))
            throw new RuntimeException('The current DHCP identity is ambiguous: this address is claimed by another MAC.');
        $mac ??= $owners[0] ?? null;
        if ($mac === null) return null;
        $forMac = array_values(array_filter($claims,fn ($l) => $l['mac'] === $mac));
        if (count(array_unique(array_column($forMac,'address'))) > 1)
            throw new RuntimeException('The current DHCP identity is ambiguous: this MAC has multiple addresses.');
        if (!$forMac) return null;
        $lease = $forMac[0];
        $owners = array_unique(array_column(array_filter($claims,fn ($l) => $l['address'] === $lease['address']),'mac'));
        if (count($owners) !== 1) throw new RuntimeException('The current DHCP identity is ambiguous. Resolve conflicting leases before checking access.');
        if ($address !== null && $address !== $lease['address'])
            throw new RuntimeException('The address changed or conflicts with its current DHCP lease. Select the device again.');
        return $lease;
    }

    private function validateAddress(string $ip): void
    {
        $locals = $this->localAddresses;
        if ($locals === null) {
            $locals = [];
            foreach (net_get_interfaces() ?: [] as $iface)
                foreach ($iface['unicast'] ?? [] as $item) if (isset($item['address'])) $locals[] = $item['address'];
        }
        $reserved = ['172.20.0.0','172.20.255.255'];
        for ($i=1; $i<=9; $i++) $reserved[] = '172.20.0.'.$i;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !IpUtils::checkIp($ip, NetworkStatus::STAND_NETWORK)
            || in_array($ip, array_merge($locals, $reserved, EthernetSwitch::pluck('host')->all()), true))
            throw new RuntimeException('This address is outside the stand or belongs to protected station/network infrastructure.');
    }

    private function protectMac(string $mac): void
    {
        if (strtolower((string) app(NetworkStatus::class)->serverMac()) === $mac)
            throw new RuntimeException('This MAC belongs to the local provisioner.');
        $stand = EthernetSwitch::exists() ? Stand::current(maxAge: 5) : null;
        // Refreshing the stand may discover switch identities that were absent before this poll.
        foreach (EthernetSwitch::all() as $switch)
            if (in_array($mac,array_merge([$switch->base_mac],$switch->own_macs ?? []),true))
                throw new RuntimeException('This MAC belongs to a protected switch.');
        $seen = false; $ambiguous = false; $edges = [];
        foreach ($stand['switches'] ?? [] as $id => $state) {
            if (!Stand::answering($state) || ($state['busy'] ?? false) || !empty($state['same_as'])
                || $state['snapshot']['taken_at'] < now()->subSeconds(5)->timestamp) continue;
            foreach ($state['snapshot']['ports'] as $port) {
                if (!$port['up'] || !in_array($mac,$port['macs'],true)) continue;
                $seen = true;
                $key = 's'.$id.'p'.$port['ifIndex'];
                $tile = $stand['map']['tiles'][$key] ?? null;
                $count = $port['mac_count'] ?? count($port['macs']);
                if (!$tile) { $ambiguous = true; continue; }
                if ($count === 1 && (!empty($tile['server']) || !empty($tile['station'])))
                    throw new RuntimeException('This MAC belongs to a protected provisioning station.');
                // Upstream forwarding carries the same device MAC. It is not another access edge.
                if (!empty($tile['link']) || ($tile['state'] === 'uplink' && $count > 1)) continue;
                if ($tile['state'] === 'uplink' || $count !== 1) { $ambiguous = true; continue; }
                $edges[$key] = true;
            }
        }
        if ($seen && ($ambiguous || count($edges) !== 1))
            throw new RuntimeException('The network location is ambiguous or protected. Select one compatible module on a single access port.');
    }
}
