<?php

namespace App\Services\Sensor;

use App\Models\{Cm,EthernetSwitch};
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
        $mac = $mac === null || $mac === '' ? null : strtolower($mac);
        $address = $address === '' ? null : $address;
        if ($mac !== null && (!preg_match('/^(?:[a-f0-9]{2}:){5}[a-f0-9]{2}$/D', $mac)
            || (hexdec(substr($mac, 0, 2)) & 1) || $mac === '00:00:00:00:00:00'))
            throw new RuntimeException('Enter a valid unicast MAC address.');
        if ($address !== null) $this->validateAddress($address);
        $stand = $this->station->requireReady();
        $claims = $this->leases->currentLeases();
        $matches = array_values(array_filter($claims, fn ($l) => $mac !== null ? $l['mac'] === $mac : $l['address'] === $address));
        if ($matches) {
            $ips = array_unique(array_column($matches, 'address'));
            $macs = array_unique(array_column($matches, 'mac'));
            if (count($ips) !== 1 || count($macs) !== 1
                || count(array_unique(array_column(array_filter($claims, fn ($l) => $l['address'] === $matches[0]['address']), 'mac'))) !== 1)
                throw new RuntimeException('The current DHCP identity is ambiguous. Resolve conflicting leases before checking access.');
            $lease = $matches[0];
            if ($address !== null && $address !== $lease['address'])
                throw new RuntimeException('The address changed or conflicts with its current DHCP lease. Select the device again.');
            $this->validateAddress($lease['address']);
            $this->protectMac($lease['mac']);
            return ['address'=>$lease['address'], 'mac'=>$lease['mac'], 'proof'=>'lease', 'stand'=>$stand];
        }
        if ($address === null) throw new RuntimeException('No unambiguous current DHCP lease. Enter the module IPv4 address to verify its stand binding.');
        // Refresh only this destination on this interface; never scan or let routing choose an uplink.
        ($this->run)(['ping','-n','-c','1','-W','1','-I',$stand['device'],$address]);
        $out = ($this->run)(['ip','-j','neigh','show','to',$address,'dev',$stand['device']]);
        $rows = $out === null ? null : json_decode($out, true);
        $found = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $m = strtolower((string) ($row['lladdr'] ?? ''));
            if (($row['dst'] ?? '') === $address && ($row['dev'] ?? '') === $stand['device']
                && ($row['state'] ?? []) === ['REACHABLE'] && preg_match('/^(?:[a-f0-9]{2}:){5}[a-f0-9]{2}$/D', $m)
                && !(hexdec(substr($m,0,2)) & 1) && $m !== '00:00:00:00:00:00') $found[$m] = true;
        }
        if (count($found) !== 1 || ($mac !== null && !isset($found[$mac])))
            throw new RuntimeException('A fresh matching IP/MAC binding on the provisioning interface could not be verified. Check the cable and address.');
        $mac = array_key_first($found); $this->protectMac($mac);
        return ['address'=>$address, 'mac'=>$mac, 'proof'=>'neighbor', 'stand'=>$stand];
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
        foreach (EthernetSwitch::all() as $switch)
            if (in_array($mac, array_merge([$switch->base_mac], $switch->own_macs ?? []), true))
                throw new RuntimeException('This MAC belongs to a protected switch.');
        $stand = EthernetSwitch::exists() ? Stand::current() : null;
        foreach ($stand['map']['tiles'] ?? [] as $tile)
            if (in_array($mac, $tile['macs'], true) && ($tile['state'] === 'uplink' || !empty($tile['link'])
                || !empty($tile['server']) || !empty($tile['station']) || $tile['mac_count'] > 1))
                throw new RuntimeException('The target is a station, uplink or a port with multiple devices. Select a single compatible module.');
    }
}
