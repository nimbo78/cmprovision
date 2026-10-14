<?php

namespace App\Services;

use App\Models\EthernetSwitch;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;
use App\Services\Switch\MacTable;

/**
 * What the switch's learned MAC table says over SNMP (MacTable, which also lists the methods tried), for the
 * settings page's Test and the artisan command. Ports are named with ifAlias (the port description, when set) or
 * ifName. A module's port at its start comes from the stand map (App\Services\Switch\PortLookup).
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

    protected $snmp, $table, $names;

    /** @param string $method "auto" or one of METHODS; with auto the preferred method goes first */
    public function __construct(SnmpClient $snmp, $method = 'auto', $preferred = null)
    {
        $this->snmp = $snmp;
        $this->table = new MacTable($snmp, $method, $preferred);
    }

    /* A finder for these settings (see config()), e.g. to test them before saving */
    public static function forConfig(array $config)
    {
        return new self(app(SnmpClient::class, ['config' => $config]), $config['method'] ?? 'auto', $config['detected'] ?? null);
    }

    /* The first switch of the stand, until every caller knows several (docs/superpowers/plans/2026-10-14-multi-switch.md) */
    public static function config()
    {
        $switch = EthernetSwitch::orderBy('id')->first();
        return $switch ? $switch->config() : EthernetSwitch::emptyConfig();
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
