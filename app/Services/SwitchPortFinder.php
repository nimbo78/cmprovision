<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;

/**
 * Which switch port a MAC address is behind, from the switch's learned MAC table over SNMP.
 * Switches publish that table differently, so several methods are tried (see METHODS):
 *  - Q-BRIDGE-MIB dot1qTpFdbTable: VLAN-aware switches (Huawei, MikroTik, Cisco Business, ...)
 *  - BRIDGE-MIB dot1dTpFdbTable: the classic table
 *  - HUAWEI-L2MAM-MIB hwDynFdbTable: Huawei, gives the interface directly
 *  - Cisco IOS: one BRIDGE-MIB instance per VLAN (community@vlan, or context vlan-<id> for v3),
 *    VLANs from CISCO-VTP-MIB
 * Bridge port numbers are mapped to interfaces with dot1dBasePortIfIndex, interfaces to names
 * with ifAlias (the port description, when set) or ifName.
 */
class SwitchPortFinder
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
    const OID_IFNAME = '.1.3.6.1.2.1.31.1.1.1.1';
    const OID_IFALIAS = '.1.3.6.1.2.1.31.1.1.1.18';
    const OID_IFDESCR = '.1.3.6.1.2.1.2.2.1.2';
    const CISCO_RESERVED_VLANS = [1002, 1003, 1004, 1005];

    protected $snmp, $method, $preferred, $lastMethod, $names;

    /** @param string $method "auto" or one of METHODS; with auto the preferred method goes first */
    public function __construct(SnmpClient $snmp, $method = 'auto', $preferred = null)
    {
        $this->snmp = $snmp;
        $this->method = $method;
        $this->preferred = $preferred;
    }

    /* The finder configured on the settings page, or null when no switch is set up */
    public static function fromSettings()
    {
        $config = self::config();
        return $config['host'] ? self::forConfig($config) : null;
    }

    /* A finder for these settings (see config()), e.g. to test them before saving */
    public static function forConfig(array $config)
    {
        return new self(app(SnmpClient::class, ['config' => $config]), $config['method'] ?? 'auto', $config['detected'] ?? null);
    }

    /* Switch settings as stored (keys ethernetswitch_*) */
    public static function config()
    {
        $get = function ($key, $default = '') {
            $s = Setting::find('ethernetswitch_'.$key);
            return $s && $s->value !== null ? $s->value : $default;
        };
        return [
            'host' => $get('ip'),
            'version' => $get('snmp_version', '2c'),
            'community' => $get('snmp_community'),
            'user' => $get('snmp_user'),
            'auth_protocol' => $get('snmp_auth_protocol'),
            'auth_password' => $get('snmp_auth_password'),
            'priv_protocol' => $get('snmp_priv_protocol'),
            'priv_password' => $get('snmp_priv_password'),
            'method' => $get('method', 'auto'),
            'detected' => $get('method_detected', null),
        ];
    }

    public function lastMethod()
    {
        return $this->lastMethod;
    }

    /**
     * Port of a MAC address; null when the switch does not know it. A port where the MAC shows up
     * among many others (an uplink) loses to one where it is alone; a tie gives "[multiple ports]".
     * Throws SnmpError when the switch does not answer.
     */
    public function portOf($mac)
    {
        $mac = strtolower($mac);
        foreach ($this->methods() as $method)
        {
            $rows = $this->rowsOrNothing($method);
            if (!$rows)
                continue;
            $ports = $this->pick($rows);
            if (array_key_exists($mac, $ports))
            {
                $this->lastMethod = $method;
                return $ports[$mac] === false ? '[multiple ports]' : $this->label($ports[$mac]);
            }
        }
        return null;
    }

    /**
     * Everything the switch knows, for the settings page: the first method with entries, every
     * MAC with its port (later methods fill in what earlier ones lack), their VLANs, and what
     * each method answered.
     */
    public function scan()
    {
        $result = ['method' => null, 'ports' => [], 'vlans' => [], 'tried' => []];
        foreach ($this->methods() as $method)
        {
            try
            {
                $rows = $this->rows($method);
            }
            catch (SnmpError $e)
            {
                $result['tried'][$method] = $e->getMessage();
                if ($e->timeout)
                    break;
                continue;
            }
            $result['tried'][$method] = count($rows).' entries';
            if (!$rows)
                continue;
            $result['method'] = $result['method'] ?: $method;
            foreach ($this->pick($rows) as $mac => $ifIndex)
            {
                if (array_key_exists($mac, $result['ports']))
                    continue;
                $result['ports'][$mac] = $ifIndex === false ? '[multiple ports]' : $this->label($ifIndex);
                $result['vlans'][$mac] = $this->vlanOf($rows, $mac, $ifIndex);
            }
        }
        return $result;
    }

    protected function methods()
    {
        if ($this->method !== 'auto')
            return [$this->method];
        $methods = array_keys(self::METHODS);
        if ($this->preferred && in_array($this->preferred, $methods, true))
            $methods = array_values(array_unique(array_merge([$this->preferred], $methods)));
        return $methods;
    }

    /* rows() that turns "this method does not work here" into no rows, but lets a silent switch through */
    protected function rowsOrNothing($method)
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

    /* Learned entries as [['mac' => ..., 'ifIndex' => ..., 'vlan' => ...], ...] */
    protected function rows($method)
    {
        switch ($method)
        {
            case 'qbridge':
                $rows = [];
                foreach ($this->snmp->walk(self::OID_QBRIDGE_FDB_PORT) as $oid => $port)
                {
                    $index = $this->index($oid, self::OID_QBRIDGE_FDB_PORT);
                    if (count($index) >= 7 && (int) $port > 0)
                        $rows[] = ['mac' => $this->mac(array_slice($index, -6)), 'port' => (int) $port, 'vlan' => $index[0]];
                }
                return $this->toIfIndex($rows);

            case 'bridge':
                $rows = [];
                foreach ($this->snmp->walk(self::OID_BRIDGE_FDB_PORT) as $oid => $port)
                {
                    $index = $this->index($oid, self::OID_BRIDGE_FDB_PORT);
                    if (count($index) == 6 && (int) $port > 0)
                        $rows[] = ['mac' => $this->mac($index), 'port' => (int) $port, 'vlan' => null];
                }
                return $this->toIfIndex($rows);

            case 'huawei':
                $rows = [];
                foreach ($this->snmp->walk(self::OID_HUAWEI_FDB_PORT) as $oid => $ifIndex)
                {
                    $index = $this->index($oid, self::OID_HUAWEI_FDB_PORT);
                    if (count($index) >= 7 && (int) $ifIndex > 0)
                        $rows[] = ['mac' => $this->mac(array_slice($index, 0, 6)), 'ifIndex' => (int) $ifIndex, 'vlan' => $index[6]];
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
                        $index = $this->index($fdbOid, self::OID_BRIDGE_FDB_PORT);
                        if (count($index) == 6 && (int) $port > 0)
                            $vlanRows[] = ['mac' => $this->mac($index), 'port' => (int) $port, 'vlan' => $vlan];
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
    protected function pick(array $rows)
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

    protected function vlanOf(array $rows, $mac, $ifIndex)
    {
        foreach ($rows as $row)
            if ($row['mac'] === $mac && ($ifIndex === false || $row['ifIndex'] === $ifIndex))
                return $row['vlan'] === null ? null : (int) $row['vlan'];
        return null;
    }

    /* Port description (ifAlias) when set, otherwise the interface name */
    protected function label($ifIndex)
    {
        if ($this->names === null)
        {
            $this->names = [];
            foreach ([self::OID_IFDESCR, self::OID_IFNAME, self::OID_IFALIAS] as $column)
            {
                try
                {
                    $values = $this->snmp->walk($column);
                }
                catch (SnmpError $e)
                {
                    continue;
                }
                foreach ($values as $oid => $value)
                    if (trim((string) $value) !== '')
                        $this->names[(int) substr(strrchr($oid, '.'), 1)] = trim((string) $value);
            }
        }
        return $this->names[$ifIndex] ?? 'port '.$ifIndex;
    }

    protected function index($oid, $column)
    {
        return array_map('intval', explode('.', substr($oid, strlen($column) + 1)));
    }

    protected function mac(array $octets)
    {
        return implode(':', array_map(function ($o) { return sprintf('%02x', $o); }, $octets));
    }
}
