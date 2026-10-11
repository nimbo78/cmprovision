<?php

namespace App\Services\Switch;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;
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
     * ['id', 'model', 'taken_at' (unix time), 'duration' (seconds), 'mac_method', 'poe' (any PoE data),
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

        return [
            'id' => $this->id,
            'model' => $this->model(),
            'taken_at' => Carbon::now()->getTimestamp(),
            'duration' => round(microtime(true) - $started, 3),
            'mac_method' => $table['method'],
            'poe' => (bool) $poe,
            'ports' => $ports,
        ];
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
