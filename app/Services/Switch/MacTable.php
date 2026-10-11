<?php

namespace App\Services\Switch;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;

/**
 * The switch's learned MAC address table over SNMP, as [mac, vlan, ifIndex] rows. Switches publish it
 * differently, so several methods are tried (see METHODS):
 *  - Q-BRIDGE-MIB dot1qTpFdbTable: VLAN-aware switches (Huawei, MikroTik, Cisco Business, ...)
 *  - BRIDGE-MIB dot1dTpFdbTable: the classic table
 *  - HUAWEI-L2MAM-MIB hwDynFdbTable: Huawei, gives the interface directly
 *  - Cisco IOS: one BRIDGE-MIB instance per VLAN (community@vlan, or context vlan-<id> for v3),
 *    VLANs from CISCO-VTP-MIB
 * Bridge port numbers are mapped to interfaces with dot1dBasePortIfIndex (or HUAWEI-L2IF-MIB).
 * SwitchPortFinder (the port of one module) and SwitchSnapshot (the Ports page) read it.
 */
class MacTable
{
    const METHODS = [
        'qbridge' => 'Q-BRIDGE-MIB (VLAN-aware MAC table)',
        'bridge' => 'BRIDGE-MIB (MAC table)',
        'huawei' => 'Huawei HUAWEI-L2MAM-MIB',
        'cisco' => 'Cisco IOS: BRIDGE-MIB per VLAN',
    ];

    const OID_QBRIDGE_FDB_PORT = '.1.3.6.1.2.1.17.7.1.2.2.1.2';   // dot1qTpFdbPort.<fdbId>.<mac>
    const OID_BRIDGE_FDB_PORT = '.1.3.6.1.2.1.17.4.3.1.2';        // dot1dTpFdbPort.<mac>
    const OID_BASEPORT_IFINDEX = '.1.3.6.1.2.1.17.1.4.1.2';       // dot1dBasePortIfIndex.<port>
    const OID_HUAWEI_L2IF_IFINDEX = '.1.3.6.1.4.1.2011.5.25.42.1.1.1.3.1.2';   // hwL2IfPortIfIndex.<port>
    const OID_HUAWEI_FDB_PORT = '.1.3.6.1.4.1.2011.5.25.42.2.1.3.1.4';   // hwDynFdbPort.<mac>.<vlan>.<vsi>
    const OID_CISCO_VLAN_STATE = '.1.3.6.1.4.1.9.9.46.1.3.1.1.2';        // vtpVlanState.<domain>.<vlan>
    const CISCO_RESERVED_VLANS = [1002, 1003, 1004, 1005];

    protected $snmp, $method, $preferred;

    /** @param string $method "auto" or one of METHODS; with auto the preferred method goes first */
    public function __construct(SnmpClient $snmp, $method = 'auto', $preferred = null)
    {
        $this->snmp = $snmp;
        $this->method = $method;
        $this->preferred = $preferred;
    }

    /* The methods to try, in order */
    public function methods()
    {
        if ($this->method !== 'auto')
            return [$this->method];
        $methods = array_keys(self::METHODS);
        if ($this->preferred && in_array($this->preferred, $methods, true))
            $methods = array_values(array_unique(array_merge([$this->preferred], $methods)));
        return $methods;
    }

    /**
     * The rows of the first method with entries; later methods add only MACs it lacks, as
     * SwitchPortFinder::scan() does for the settings page. A switch that does not answer throws SnmpError.
     */
    public function entries()
    {
        $result = ['method' => null, 'rows' => []];
        $seenBy = [];
        foreach ($this->methods() as $method)
        {
            $rows = $this->rowsOrNothing($method);
            if (!$rows)
                continue;
            $result['method'] = $result['method'] ?: $method;
            foreach ($rows as $row)
                if (!isset($seenBy[$row['mac']]) || $seenBy[$row['mac']] === $method)
                {
                    $seenBy[$row['mac']] = $method;
                    $result['rows'][] = $row;
                }
        }
        return $result;
    }

    /* rows() that turns "this method does not work here" into no rows, but lets a silent switch through */
    public function rowsOrNothing($method)
    {
        try
        {
            return $this->rows($method);
        }
        catch (SnmpError $e)
        {
            if ($e->timeout)
                throw $e;
            return [];
        }
    }

    /* Learned entries as [['mac' => ..., 'vlan' => ..., 'ifIndex' => ...], ...] */
    public function rows($method)
    {
        switch ($method)
        {
            case 'qbridge':
                $rows = [];
                foreach ($this->snmp->walk(self::OID_QBRIDGE_FDB_PORT) as $oid => $port)
                {
                    $index = self::index($oid, self::OID_QBRIDGE_FDB_PORT);
                    if (count($index) >= 7 && (int) $port > 0)
                        $rows[] = ['mac' => self::mac(array_slice($index, -6)), 'port' => (int) $port, 'vlan' => $index[0]];
                }
                return $this->toIfIndex($rows);

            case 'bridge':
                $rows = [];
                foreach ($this->snmp->walk(self::OID_BRIDGE_FDB_PORT) as $oid => $port)
                {
                    $index = self::index($oid, self::OID_BRIDGE_FDB_PORT);
                    if (count($index) == 6 && (int) $port > 0)
                        $rows[] = ['mac' => self::mac($index), 'port' => (int) $port, 'vlan' => null];
                }
                return $this->toIfIndex($rows);

            case 'huawei':
                $rows = [];
                foreach ($this->snmp->walk(self::OID_HUAWEI_FDB_PORT) as $oid => $ifIndex)
                {
                    $index = self::index($oid, self::OID_HUAWEI_FDB_PORT);
                    if (count($index) >= 7 && (int) $ifIndex > 0)
                        $rows[] = ['mac' => self::mac(array_slice($index, 0, 6)), 'ifIndex' => (int) $ifIndex, 'vlan' => $index[6]];
                }
                return $rows;

            case 'cisco':
                $rows = [];
                foreach ($this->snmp->walk(self::OID_CISCO_VLAN_STATE) as $oid => $state)
                {
                    $vlan = (int) substr(strrchr($oid, '.'), 1);
                    if ((int) $state !== 1 || in_array($vlan, self::CISCO_RESERVED_VLANS, true))
                        continue;   // only operational VLANs; 1002-1005 are the FDDI/Token Ring leftovers
                    $vlanRows = [];
                    foreach ($this->snmp->walk(self::OID_BRIDGE_FDB_PORT, $vlan) as $fdbOid => $port)
                    {
                        $index = self::index($fdbOid, self::OID_BRIDGE_FDB_PORT);
                        if (count($index) == 6 && (int) $port > 0)
                            $vlanRows[] = ['mac' => self::mac($index), 'port' => (int) $port, 'vlan' => $vlan];
                    }
                    if ($vlanRows)
                        $rows = array_merge($rows, $this->toIfIndex($vlanRows, $vlan));
                }
                return $rows;
        }
        throw new \InvalidArgumentException("Unknown method $method");
    }

    /* Bridge port numbers to interface indexes (per VLAN instance on Cisco), from BRIDGE-MIB or, where
       that is missing (some Huawei YunShan models), HUAWEI-L2IF-MIB. An agent with neither is assumed
       to report interface indexes already (Cisco Business does). */
    protected function toIfIndex(array $rows, $vlan = null)
    {
        if (!$rows)
            return [];
        $map = [];
        foreach ([self::OID_BASEPORT_IFINDEX, self::OID_HUAWEI_L2IF_IFINDEX] as $column)
        {
            if ($map || ($column === self::OID_HUAWEI_L2IF_IFINDEX && $vlan !== null))
                break;
            try
            {
                $values = $this->snmp->walk($column, $vlan);
            }
            catch (SnmpError $e)
            {
                if ($e->timeout)
                    throw $e;
                continue;
            }
            foreach ($values as $oid => $ifIndex)
                $map[(int) substr(strrchr($oid, '.'), 1)] = (int) $ifIndex;
        }
        foreach ($rows as &$row)
        {
            $row['ifIndex'] = $map[$row['port']] ?? $row['port'];
            unset($row['port']);
        }
        return $rows;
    }

    /* [mac => ifIndex, or false when it is equally present on several ports] */
    public static function pick(array $rows)
    {
        $macsPerPort = [];
        $portsPerMac = [];
        foreach ($rows as $row)
        {
            $macsPerPort[$row['ifIndex']][$row['mac']] = true;
            $portsPerMac[$row['mac']][$row['ifIndex']] = true;
        }

        $result = [];
        foreach ($portsPerMac as $mac => $ports)
        {
            $ports = array_keys($ports);
            if (count($ports) == 1)
            {
                $result[$mac] = $ports[0];
                continue;
            }
            usort($ports, function ($a, $b) use ($macsPerPort) {
                return count($macsPerPort[$a]) - count($macsPerPort[$b]);
            });
            $result[$mac] = count($macsPerPort[$ports[0]]) < count($macsPerPort[$ports[1]]) ? $ports[0] : false;
        }
        return $result;
    }

    public static function vlanOf(array $rows, $mac, $ifIndex)
    {
        foreach ($rows as $row)
            if ($row['mac'] === $mac && ($ifIndex === false || $row['ifIndex'] === $ifIndex))
                return $row['vlan'] === null ? null : (int) $row['vlan'];
        return null;
    }

    public static function index($oid, $column)
    {
        return array_map('intval', explode('.', substr($oid, strlen($column) + 1)));
    }

    public static function mac(array $octets)
    {
        return implode(':', array_map(function ($o) { return sprintf('%02x', $o); }, $octets));
    }
}
