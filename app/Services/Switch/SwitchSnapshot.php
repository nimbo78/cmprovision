<?php

namespace App\Services\Switch;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;
use App\Support\ArpTable;
use Illuminate\Support\Carbon;

/**
 * One look at the switch for the Ports page: the physical ports with link and speed, the MAC
 * addresses learned on each (MacTable) and PoE where the switch tells it (Poe). Read only.
 */
class SwitchSnapshot
{
    const OID_SYSDESCR = '.1.3.6.1.2.1.1.1';
    const OID_SYSNAME = '.1.3.6.1.2.1.1.5';
    const OID_SYSUPTIME = '.1.3.6.1.2.1.1.3';             // hundredths of a second
    const OID_IFLASTCHANGE = '.1.3.6.1.2.1.2.2.1.9';      // sysUpTime when the interface entered its current state
    /* MAC addresses kept per port: enough for modules and a few devices; an uplink keeps only its count */
    const MAC_KEEP = 64;
    const OID_IFDESCR = '.1.3.6.1.2.1.2.2.1.2';
    const OID_IFTYPE = '.1.3.6.1.2.1.2.2.1.3';
    const OID_IFSPEED = '.1.3.6.1.2.1.2.2.1.5';           // bit/s
    const OID_IFOPERSTATUS = '.1.3.6.1.2.1.2.2.1.8';      // 1 up, 2 down
    const OID_IFNAME = '.1.3.6.1.2.1.31.1.1.1.1';
    const OID_IFHIGHSPEED = '.1.3.6.1.2.1.31.1.1.1.15';   // Mbit/s
    const OID_IFCONNECTOR = '.1.3.6.1.2.1.31.1.1.1.17';   // 1 a physical connector, 2 none
    const OID_IFALIAS = '.1.3.6.1.2.1.31.1.1.1.18';
    /* ethernetCsmacd; old agents report fastEther(62) and fastEtherFX(69), some gigabitEthernet(117) */
    const ETHERNET_TYPES = [6, 62, 69, 117];
    /* What the switch is (identity()): standard objects that Cisco and Huawei both answer */
    const OID_BRIDGE_ADDRESS = '.1.3.6.1.2.1.17.1.1';      // dot1dBaseBridgeAddress: the base MAC
    const OID_IFPHYSADDRESS = '.1.3.6.1.2.1.2.2.1.6';      // ifPhysAddress: the MAC of each interface, SVIs too
    const OID_ENT_CLASS = '.1.3.6.1.2.1.47.1.1.1.1.5';     // entPhysicalClass: chassis(3), module(9)
    const OID_ENT_HW_REV = '.1.3.6.1.2.1.47.1.1.1.1.8';    // entPhysicalHardwareRev
    const OID_ENT_SERIAL = '.1.3.6.1.2.1.47.1.1.1.1.11';   // entPhysicalSerialNum
    const OID_ENT_MODEL = '.1.3.6.1.2.1.47.1.1.1.1.13';    // entPhysicalModelName
    /* What each port's connector is (media()) */
    const OID_ENT_NAME = '.1.3.6.1.2.1.47.1.1.1.1.7';      // entPhysicalName
    const OID_ENT_CONTAINED_IN = '.1.3.6.1.2.1.47.1.1.1.1.4';   // entPhysicalContainedIn

    protected $snmp, $macs, $poe, $id, $lastError;

    public function __construct(SnmpClient $snmp, MacTable $macs, ?Poe $poe = null, string $id = 'switch')
    {
        $this->snmp = $snmp;
        $this->macs = $macs;
        $this->poe = $poe;
        $this->id = $id;
    }

    /* The switch of these settings (SwitchPortFinder::config()), named by its address */
    public static function forConfig(array $config): self
    {
        $snmp = app(SnmpClient::class, ['config' => $config]);
        return new self($snmp, new MacTable($snmp, $config['method'] ?? 'auto', $config['detected'] ?? null), new Poe($snmp), (string) $config['host']);
    }

    /**
     * ['id', 'model', 'identity' => ['revision', 'serial', 'name', 'mac'] (null when the switch does not say),
     *  'taken_at' (unix time), 'duration' (seconds), 'mac_method', 'poe' (any PoE data),
     *  'ports' => [ifIndex => ['ifIndex', 'name', 'alias', 'up', 'speed' (Mbit/s as the switch reports
     *  it, also without a link; null when unknown), 'changed' (seconds in the current link state, null
     *  when unknown), 'macs' => the first MAC_KEEP, 'mac_count', 'poe' => Poe::read() entry or null]]].
     * Throws SnmpError when the switch does not answer or lists no interfaces or no Ethernet ports.
     */
    public function take(): array
    {
        $started = microtime(true);
        $this->lastError = null;
        $c = ['type' => $this->column(self::OID_IFTYPE)];
        if (!$c['type'])
            throw new SnmpError($this->lastError ?: 'The switch lists no interfaces (IF-MIB ifType)');
        $ethernet = array_filter($c['type'], function ($type) { return in_array((int) $type, self::ETHERNET_TYPES, true); });
        $c['name'] = $this->column(self::OID_IFNAME);
        // ifDescr and ifSpeed only stand in for what an old agent without ifXTable lacks
        $c['descr'] = $this->lacks($ethernet, $c['name']) ? $this->column(self::OID_IFDESCR) : [];
        foreach (['alias' => self::OID_IFALIAS, 'connector' => self::OID_IFCONNECTOR, 'oper' => self::OID_IFOPERSTATUS,
                  'high' => self::OID_IFHIGHSPEED, 'changed' => self::OID_IFLASTCHANGE] as $key => $oid)
            $c[$key] = $this->column($oid);
        $c['speed'] = $this->lacks($ethernet, $c['high'], true) ? $this->column(self::OID_IFSPEED) : [];
        $uptime = $c['changed'] ? (int) (array_values($this->column(self::OID_SYSUPTIME))[0] ?? 0) : 0;
        // some agents say "no connector" for every interface: then the flag tells nothing
        $connectors = in_array(1, array_map('intval', $c['connector']), true);

        $ports = [];
        foreach ($c['type'] as $ifIndex => $type)
        {
            if (!in_array((int) $type, self::ETHERNET_TYPES, true))
                continue;
            if ($connectors && (int) ($c['connector'][$ifIndex] ?? 2) !== 1)
                continue;   // no connector: a virtual interface
            $name = $this->text($c['name'][$ifIndex] ?? null);
            $ports[$ifIndex] = [
                'ifIndex' => $ifIndex,
                'name' => $name !== '' ? $name : ($this->text($c['descr'][$ifIndex] ?? null) ?: 'port '.$ifIndex),
                'alias' => $this->text($c['alias'][$ifIndex] ?? null),
                'up' => (int) ($c['oper'][$ifIndex] ?? 2) === 1,
                'speed' => $this->speed($c['high'][$ifIndex] ?? null, $c['speed'][$ifIndex] ?? null),
                'changed' => $this->age($uptime, $c['changed'][$ifIndex] ?? null),
                'macs' => [],
                'mac_count' => 0,
                'poe' => null,
            ];
        }
        if (!$ports)
            throw new SnmpError('The switch lists no Ethernet ports (IF-MIB ifType 6, 62, 69 or 117)');

        $table = $this->macs->entries();
        $seen = [];
        foreach ($table['rows'] as $row)
        {
            $i = $row['ifIndex'];
            if (!isset($ports[$i]) || isset($seen[$i][$row['mac']]))
                continue;
            $seen[$i][$row['mac']] = true;
            if (++$ports[$i]['mac_count'] <= self::MAC_KEEP)
                $ports[$i]['macs'][] = $row['mac'];
        }

        $poe = $this->poe ? $this->poe->read(array_map(function ($port) { return $port['name']; }, $ports)) : [];
        foreach ($poe as $ifIndex => $entry)
            if (isset($ports[$ifIndex]))
                $ports[$ifIndex]['poe'] = $entry;

        $classes = $this->column(self::OID_ENT_CLASS);
        $media = $this->media($ports, $classes);
        foreach (array_keys($ports) as $ifIndex)
            $ports[$ifIndex] += $media[$ifIndex] ?? ['media' => null, 'transceiver' => null];

        $identity = $this->identity($classes);
        return [
            'id' => $this->id,
            'model' => $identity['model'],
            'identity' => ['revision' => $identity['revision'], 'serial' => $identity['serial'], 'name' => $identity['name'], 'mac' => $identity['mac']],
            'own_macs' => $this->ownMacs($identity['mac']),
            'taken_at' => Carbon::now()->getTimestamp(),
            'duration' => round(microtime(true) - $started, 3),
            'mac_method' => $table['method'],
            'poe' => (bool) $poe,
            'ports' => $ports,
        ];
    }

    /* The addresses the switch itself answers from: base MAC, its interfaces' (SVI and ports) and the ARP entry of its
       address. A neighbour's port towards it shows one of them, while the switch never lists them on its own ports */
    protected function ownMacs(?string $base): array
    {
        $macs = $base ? [$base] : [];
        foreach ($this->column(self::OID_IFPHYSADDRESS) as $value)
            if (is_string($value) && strlen($value) === 6 && trim($value, "\0") !== '')
                $macs[] = implode(':', str_split(bin2hex($value), 2));
        $arp = ArpTable::macOf($this->id);
        if ($arp)
            $macs[] = $arp;
        return array_values(array_unique($macs));
    }

    /* [index => value] of a one-index column; [] when the switch does not have it; a silent switch throws */
    protected function column(string $oid): array
    {
        try
        {
            $values = $this->snmp->walk($oid);
        }
        catch (SnmpError $e)
        {
            if ($e->timeout)
                throw $e;
            $this->lastError = $e->getMessage();
            return [];
        }
        $column = [];
        foreach ($values as $key => $value)
            $column[(int) substr(strrchr($key, '.'), 1)] = $value;
        return $column;
    }

    /* Whether some Ethernet interface has no value in this column: empty text, or no positive number */
    protected function lacks(array $ethernet, array $column, bool $numeric = false): bool
    {
        foreach (array_keys($ethernet) as $ifIndex)
        {
            $value = $column[$ifIndex] ?? null;
            if ($numeric ? (int) $value <= 0 : trim((string) $value) === '')
                return true;
        }
        return false;
    }

    /* Seconds since the interface entered its current state: sysUpTime minus ifLastChange, both in hundredths */
    protected function age(int $uptime, $changed): ?int
    {
        if ($changed === null || $uptime <= 0 || (int) $changed > $uptime)
            return null;
        return intdiv($uptime - (int) $changed, 100);
    }

    protected function speed($high, $speed): ?int
    {
        if ((int) $high > 0)
            return (int) $high;
        if ((int) $speed > 0 && (int) $speed < 4294967295)
            return (int) round((int) $speed / 1000000);
        return null;
    }

    protected function text($value): string
    {
        return trim((string) $value);
    }

    /* What the switch says it is: the chassis in ENTITY-MIB (model, hardware revision, serial; Huawei may keep the
       serial on its main board instead), the base MAC in BRIDGE-MIB and sysName. What it does not say stays null;
       without a chassis model the first line of sysDescr names it (model()) */
    protected function identity(array $classes): array
    {
        $chassis = array_search(3, array_map('intval', $classes), true);
        $model = $revision = $serial = null;
        if ($chassis !== false)
        {
            $model = $this->value(self::OID_ENT_MODEL.'.'.$chassis);
            $revision = $this->value(self::OID_ENT_HW_REV.'.'.$chassis);
            $serial = $this->value(self::OID_ENT_SERIAL.'.'.$chassis);
            if ($serial === null)
            {
                $serials = $this->column(self::OID_ENT_SERIAL);
                foreach ($classes as $index => $class)
                    if ((int) $class === 9 && $this->text($serials[$index] ?? '') !== '')
                    {
                        $serial = $this->text($serials[$index]);
                        break;
                    }
            }
        }
        $mac = $this->value(self::OID_BRIDGE_ADDRESS.'.0', false);   // six octets as they are (SNMP_VALUE_PLAIN)
        return [
            'model' => $model ?? $this->model(),
            'revision' => $revision,
            'serial' => $serial,
            'name' => $this->value(self::OID_SYSNAME.'.0'),
            'mac' => is_string($mac) && strlen($mac) === 6 && trim($mac, "\0") !== '' ? implode(':', str_split(bin2hex($mac), 2)) : null,
        ];
    }

    /* [ifIndex => ['media' => copper|sfp|combo, 'transceiver' => bool|null]] as Cisco lists its connectors in ENTITY-MIB:
       an SFP cage is a container named after its port ("GigabitEthernet0/11 Container"), a combo port has its RJ45
       port entity beside such a container, and whatever sits in a container is a transceiver. Only a switch that names
       some cage this way tells copper from cages; for any other the connectors stay unknown ([]) */
    protected function media(array $ports, array $classes): array
    {
        if (!in_array(5, array_map('intval', $classes), true))
            return [];
        $names = $this->column(self::OID_ENT_NAME);
        $cages = [];
        foreach ($classes as $entity => $class)
            if ((int) $class === 5 && preg_match('/^(.*\S)\s+Container$/i', $this->text($names[$entity] ?? ''), $m))
            {
                $of = array_keys(array_filter($ports, function ($port) use ($m) { return self::names($m[1], $port['name']); }));
                if (count($of) === 1)
                    $cages[$of[0]] = $entity;
            }
        if (!$cages)
            return [];

        $parents = array_map('intval', $this->column(self::OID_ENT_CONTAINED_IN));
        $stands = [];   // ifIndex => the entities that stand for it
        foreach ($this->aliases() as $entity => $ifIndex)
            $stands[$ifIndex][] = $entity;
        $media = [];
        foreach (array_keys($ports) as $ifIndex)
        {
            $cage = $cages[$ifIndex] ?? null;
            $own = $stands[$ifIndex] ?? [];
            if ($cage === null)
            {
                $media[$ifIndex] = ['media' => $own ? 'copper' : null, 'transceiver' => null];
                continue;
            }
            $outside = array_filter($own, function ($entity) use ($parents, $cage) { return ($parents[$entity] ?? 0) !== $cage; });
            $media[$ifIndex] = ['media' => $outside ? 'combo' : 'sfp', 'transceiver' => in_array($cage, $parents, true)];
        }
        return $media;
    }

    /* [entity => ifIndex] from entAliasMappingIdentifier; a silent switch throws */
    protected function aliases(): array
    {
        try
        {
            $rows = $this->snmp->walk(Poe::OID_ENT_ALIAS);
        }
        catch (SnmpError $e)
        {
            if ($e->timeout)
                throw $e;
            return [];
        }
        $aliases = [];
        foreach ($rows as $key => $target)
            if (preg_match('/\.2\.2\.1\.1\.(\d+)$/', (string) $target, $m))   // ifIndex of IF-MIB
                $aliases[(int) explode('.', substr($key, strlen(Poe::OID_ENT_ALIAS) + 1))[0]] = (int) $m[1];
        return $aliases;
    }

    /* "GigabitEthernet0/11" names the port "Gi0/11": the same name, or one that starts with the port's letters and ends
       with the same numbers */
    protected static function names(string $entity, string $port): bool
    {
        if (strcasecmp($entity, $port) === 0)
            return true;
        if (!preg_match('/^[a-z]+/i', $port, $letters) || stripos($entity, $letters[0]) !== 0)
            return false;
        return preg_match('#(\d+(?:/\d+)*)$#', $entity, $a) === 1 && preg_match('#(\d+(?:/\d+)*)$#', $port, $b) === 1 && $a[1] === $b[1];
    }

    /* One object's value; null when the switch does not have it or it is empty text. A silent switch throws */
    protected function value(string $oid, bool $text = true)
    {
        try
        {
            $value = $this->snmp->get($oid);
        }
        catch (SnmpError $e)
        {
            if ($e->timeout)
                throw $e;
            return null;
        }
        if (!$text)
            return $value;
        $value = $this->text($value);
        return $value !== '' ? $value : null;
    }

    /* The first line of sysDescr, otherwise sysName, otherwise the address */
    protected function model(): string
    {
        foreach ([self::OID_SYSDESCR, self::OID_SYSNAME] as $oid)
            foreach ($this->column($oid) as $value)
            {
                $lines = preg_split('/[\r\n]+/', trim((string) $value));
                if ($lines[0] !== '')
                    return mb_substr($lines[0], 0, 80);
            }
        return $this->id;
    }
}
