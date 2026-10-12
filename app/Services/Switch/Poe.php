<?php

namespace App\Services\Switch;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;

/**
 * PoE of the switch's physical ports. The plain POWER-ETHERNET-MIB (RFC 3621) numbers PoE ports by
 * group.port, leaves the mapping to interfaces to the vendor and has no power per port:
 *  - Huawei: HUAWEI-POE-MIB hwPoePortTable, indexed by interface, power in mW
 *  - Cisco IOS: CISCO-POWER-ETHERNET-EXT-MIB gives the port's physical entity, ENTITY-MIB its
 *    interface (entAliasMappingIdentifier); power in mW
 *  - the rest: the PoE port number is the interface index when such a port exists (on a stack only
 *    for the first member, or for a number no other member has), otherwise the last number in a
 *    port's name when that number is unique on the switch; no power
 * Checked against the user's switches when they arrive (ROADMAP item 1).
 */
class Poe
{
    const OID_PETH_DETECTION = '.1.3.6.1.2.1.105.1.1.1.6';            // pethPsePortDetectionStatus.<group>.<port>
    const OID_PETH_CLASS = '.1.3.6.1.2.1.105.1.1.1.10';               // pethPsePortPowerClassifications
    const OID_HW_POWER_STATUS = '.1.3.6.1.4.1.2011.5.25.195.3.1.7';   // hwPoePortPowerStatus.<ifIndex> (text)
    const OID_HW_CONSUMING = '.1.3.6.1.4.1.2011.5.25.195.3.1.10';     // hwPoePortConsumingPower.<ifIndex>, mW
    const OID_CPE_POWER = '.1.3.6.1.4.1.9.9.402.1.2.1.9';             // cpeExtPsePortPwrConsumption.<group>.<port>, mW
    const OID_CPE_ENTPHY = '.1.3.6.1.4.1.9.9.402.1.2.1.11';           // cpeExtPsePortEntPhyIndex.<group>.<port>
    const OID_ENT_ALIAS = '.1.3.6.1.2.1.47.1.3.2.1.2';                // entAliasMappingIdentifier.<entPhy>.<logical>
    const OID_HW_ENABLE = '.1.3.6.1.4.1.2011.5.25.195.3.1.3';         // hwPoePortEnable.<ifIndex>: enabled(1), disabled(2), read-write
    const OID_PETH_ADMIN = '.1.3.6.1.2.1.105.1.1.1.3';                 // pethPsePortAdminEnable.<group>.<port>: true(1), false(2), read-write
    const DETECTION = [1 => 'disabled', 2 => 'searching', 3 => 'delivering', 4 => 'fault', 5 => 'test', 6 => 'fault'];
    const CLASSES = [1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 4];

    protected $snmp;

    public function __construct(SnmpClient $snmp)
    {
        $this->snmp = $snmp;
    }

    /**
     * @param array $ports [ifIndex => name] of the physical ports
     * @return array [ifIndex => ['state' => delivering|searching|fault|disabled|test|other, 'class' => 0..4|null, 'watts' => float|null]]
     */
    public function read(array $ports): array
    {
        $huawei = $this->huawei($ports);
        if ($huawei)
            return $huawei;

        $detection = $this->table(self::OID_PETH_DETECTION);
        if (!$detection)
            return [];
        $classes = $this->table(self::OID_PETH_CLASS);
        $map = $this->cisco($detection) ?: $this->byNumber(array_keys($detection), $ports);
        $watts = $this->table(self::OID_CPE_POWER);

        $result = [];
        foreach ($map as $key => $ifIndex)
        {
            if (!isset($ports[$ifIndex]))
                continue;
            $result[$ifIndex] = [
                'state' => self::DETECTION[(int) $detection[$key]] ?? 'other',
                'class' => isset($classes[$key]) ? (self::CLASSES[(int) $classes[$key]] ?? null) : null,
                'watts' => isset($watts[$key]) ? round((int) $watts[$key] / 1000, 1) : null,
            ];
        }
        ksort($result);
        return $result;
    }

    /**
     * The object that switches each PoE port on (1) or off (2), found the way read() finds the ports: Huawei's
     * hwPoePortEnable by interface, otherwise the POWER-ETHERNET-MIB's pethPsePortAdminEnable by group.port.
     * Asked only for a restart or a write check (PoeControl), not for the Ports page's snapshot.
     * @param array $ports [ifIndex => name] of the physical ports
     * @return array [ifIndex => oid]; [] for a switch without PoE tables
     */
    public function admin(array $ports): array
    {
        $admin = [];
        foreach (array_keys($this->huawei($ports)) as $ifIndex)
            $admin[$ifIndex] = self::OID_HW_ENABLE.'.'.$ifIndex;
        if ($admin)
            return $admin;

        $detection = $this->table(self::OID_PETH_DETECTION);
        if (!$detection)
            return [];
        foreach ($this->cisco($detection) ?: $this->byNumber(array_keys($detection), $ports) as $key => $ifIndex)
            if (isset($ports[$ifIndex]))
                $admin[$ifIndex] = self::OID_PETH_ADMIN.'.'.$key;
        ksort($admin);
        return $admin;
    }

    protected function huawei(array $ports): array
    {
        $power = $this->table(self::OID_HW_CONSUMING);
        $status = $this->table(self::OID_HW_POWER_STATUS);
        $result = [];
        foreach ($ports as $ifIndex => $name)
        {
            $key = (string) $ifIndex;
            if (!isset($power[$key]) && !isset($status[$key]))
                continue;
            $watts = isset($power[$key]) ? round((int) $power[$key] / 1000, 1) : null;
            $result[$ifIndex] = ['state' => $this->huaweiState($status[$key] ?? null, $watts), 'class' => null, 'watts' => $watts];
        }
        return $result;
    }

    /* hwPoePortPowerStatus is text; its words are checked on a real S5731 (ROADMAP item 1) */
    protected function huaweiState($text, ?float $watts): string
    {
        $text = strtolower(trim((string) $text));
        if (strpos($text, 'fault') !== false || strpos($text, 'overload') !== false)
            return 'fault';
        if (strpos($text, 'disable') !== false || $text === 'off')
            return 'disabled';
        if ($watts > 0 || (strpos($text, 'power') !== false && strpos($text, 'off') === false))
            return 'delivering';
        return 'searching';
    }

    /* Cisco IOS: PoE port -> physical entity -> interface */
    protected function cisco(array $detection): array
    {
        $entities = $this->table(self::OID_CPE_ENTPHY);
        if (!$entities)
            return [];
        $interfaces = [];
        foreach ($this->table(self::OID_ENT_ALIAS) as $key => $target)
            if (preg_match('/\.(\d+)$/', (string) $target, $m))
                $interfaces[(int) explode('.', $key)[0]] = (int) $m[1];
        $map = [];
        foreach ($entities as $key => $entity)
            if (isset($detection[$key], $interfaces[(int) $entity]))
                $map[$key] = $interfaces[(int) $entity];
        return $map;
    }

    /* The rest: the port number is the interface index, or the last number in a port's name when unique.
       On a stack every member (group) numbers its ports from 1: only group 1, or a number no other group
       has, may be taken as an interface index. */
    protected function byNumber(array $keys, array $ports): array
    {
        $named = [];
        foreach ($ports as $ifIndex => $name)
            if (preg_match('/(\d+)\D*$/', $name, $m))
                $named[(int) $m[1]][] = $ifIndex;
        $groups = [];
        foreach ($keys as $key)
            $groups[(int) substr(strrchr('.'.$key, '.'), 1)][(int) $key] = true;
        $map = [];
        foreach ($keys as $key)
        {
            $port = (int) substr(strrchr('.'.$key, '.'), 1);
            $alone = count($groups[$port]) === 1;
            if (isset($ports[$port]) && ((int) $key === 1 || $alone))
                $map[$key] = $port;
            elseif ($alone && isset($named[$port]) && count($named[$port]) === 1)
                $map[$key] = $named[$port][0];
        }
        return $map;
    }

    /* [index after the column, e.g. "1.3" or "8" => value]; [] when the switch has no such table */
    protected function table(string $oid): array
    {
        try
        {
            $values = $this->snmp->walk($oid);
        }
        catch (SnmpError $e)
        {
            if ($e->timeout)
                throw $e;
            return [];
        }
        $table = [];
        foreach ($values as $key => $value)
            $table[substr($key, strlen($oid) + 1)] = $value;
        return $table;
    }
}
