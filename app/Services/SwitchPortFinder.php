<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;
use App\Services\Switch\MacTable;

/**
 * Which switch port a MAC address is behind, from the switch's learned MAC table over SNMP (MacTable,
 * which also lists the methods tried). The port is named with ifAlias (the port description, when
 * set) or ifName.
 */
class SwitchPortFinder
{
    const METHODS = MacTable::METHODS;

    const OID_QBRIDGE_FDB_PORT = MacTable::OID_QBRIDGE_FDB_PORT;
    const OID_BRIDGE_FDB_PORT = MacTable::OID_BRIDGE_FDB_PORT;
    const OID_BASEPORT_IFINDEX = MacTable::OID_BASEPORT_IFINDEX;
    const OID_HUAWEI_L2IF_IFINDEX = MacTable::OID_HUAWEI_L2IF_IFINDEX;
    const OID_HUAWEI_FDB_PORT = MacTable::OID_HUAWEI_FDB_PORT;
    const OID_CISCO_VLAN_STATE = MacTable::OID_CISCO_VLAN_STATE;
    const OID_IFNAME = '.1.3.6.1.2.1.31.1.1.1.1';
    const OID_IFALIAS = '.1.3.6.1.2.1.31.1.1.1.18';
    const OID_IFDESCR = '.1.3.6.1.2.1.2.2.1.2';
    const CISCO_RESERVED_VLANS = MacTable::CISCO_RESERVED_VLANS;

    protected $snmp, $table, $lastMethod, $names;

    /** @param string $method "auto" or one of METHODS; with auto the preferred method goes first */
    public function __construct(SnmpClient $snmp, $method = 'auto', $preferred = null)
    {
        $this->snmp = $snmp;
        $this->table = new MacTable($snmp, $method, $preferred);
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
            // PoE restart (PoeControl): its own community (v2c) or user (v3), kept like the read ones
            'write' => [
                'community' => $get('snmp_write_community'),
                'user' => $get('snmp_write_user'),
                'auth_protocol' => $get('snmp_write_auth_protocol'),
                'auth_password' => $get('snmp_write_auth_password'),
                'priv_protocol' => $get('snmp_write_priv_protocol'),
                'priv_password' => $get('snmp_write_priv_password'),
            ],
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
        foreach ($this->table->methods() as $method)
        {
            $rows = $this->table->rowsOrNothing($method);
            if (!$rows)
                continue;
            $ports = MacTable::pick($rows);
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
        $result = ['method' => null, 'ports' => [], 'vlans' => [], 'tried' => [], 'answered' => false];
        foreach ($this->table->methods() as $method)
        {
            try
            {
                $rows = $this->table->rows($method);
            }
            catch (SnmpError $e)
            {
                $result['tried'][$method] = $e->getMessage();
                if ($e->timeout)
                    break;
                $result['answered'] = true;
                continue;
            }
            $result['answered'] = true;
            $result['tried'][$method] = count($rows).' entries';
            if (!$rows)
                continue;
            $result['method'] = $result['method'] ?: $method;
            foreach (MacTable::pick($rows) as $mac => $ifIndex)
            {
                if (array_key_exists($mac, $result['ports']))
                    continue;
                $result['ports'][$mac] = $ifIndex === false ? '[multiple ports]' : $this->label($ifIndex);
                $result['vlans'][$mac] = MacTable::vlanOf($rows, $mac, $ifIndex);
            }
        }
        return $result;
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
}
