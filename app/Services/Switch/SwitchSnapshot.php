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
    const OID_IFDESCR = '.1.3.6.1.2.1.2.2.1.2';
    const OID_IFTYPE = '.1.3.6.1.2.1.2.2.1.3';
    const OID_IFSPEED = '.1.3.6.1.2.1.2.2.1.5';           // bit/s
    const OID_IFOPERSTATUS = '.1.3.6.1.2.1.2.2.1.8';      // 1 up, 2 down
    const OID_IFNAME = '.1.3.6.1.2.1.31.1.1.1.1';
    const OID_IFHIGHSPEED = '.1.3.6.1.2.1.31.1.1.1.15';   // Mbit/s
    const OID_IFCONNECTOR = '.1.3.6.1.2.1.31.1.1.1.17';   // 1 a physical connector, 2 none
    const OID_IFALIAS = '.1.3.6.1.2.1.31.1.1.1.18';
    /* ethernetCsmacd; some switches report gigabitEthernet(117) */
    const ETHERNET_TYPES = [6, 117];

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
     *  it, also without a link; null when unknown), 'macs' => [...], 'poe' => Poe::read() entry or null]]].
     * Throws SnmpError when the switch does not answer or lists no interfaces.
     */
    public function take(): array
    {
        $started = microtime(true);
        $this->lastError = null;
        $c = ['type' => $this->column(self::OID_IFTYPE)];
        if (!$c['type'])
            throw new SnmpError($this->lastError ?: 'The switch lists no interfaces (IF-MIB ifType)');
        foreach (['name' => self::OID_IFNAME, 'descr' => self::OID_IFDESCR, 'alias' => self::OID_IFALIAS,
                  'connector' => self::OID_IFCONNECTOR, 'oper' => self::OID_IFOPERSTATUS,
                  'high' => self::OID_IFHIGHSPEED, 'speed' => self::OID_IFSPEED] as $key => $oid)
            $c[$key] = $this->column($oid);
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
                'macs' => [],
                'poe' => null,
            ];
        }

        $table = $this->macs->entries();
        foreach ($table['rows'] as $row)
            if (isset($ports[$row['ifIndex']]) && !in_array($row['mac'], $ports[$row['ifIndex']]['macs'], true))
                $ports[$row['ifIndex']]['macs'][] = $row['mac'];

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
