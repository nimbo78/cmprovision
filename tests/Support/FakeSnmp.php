<?php

namespace Tests\Support;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;

/** A switch answering from a table of OIDs, per VLAN instance like Cisco IOS does; writes go to the table */
class FakeSnmp implements SnmpClient
{
    public $tables, $walks = [], $timeout = false;
    public $sets = [];     // every write as [oid, type, value], refused ones too
    public $refuse = [];   // oid => message, or "oid=value" => message: that write throws; the message "timeout" times out

    public function __construct(array $tables)
    {
        $this->tables = $tables;   // ['' => [oid => value], '20' => [...]] keyed by VLAN instance
    }

    public function walk($oid, $vlan = null)
    {
        $this->walks[] = [$oid, $vlan];
        if ($this->timeout)
            throw new SnmpError('No response from 192.0.2.1', true);
        $result = [];
        foreach ($this->tables[(string) $vlan] ?? [] as $key => $value)
            if (strpos($key, $oid.'.') === 0)
                $result[$key] = $value;
        return $result;
    }

    public function get($oid)
    {
        if ($this->timeout)
            throw new SnmpError('No response from 192.0.2.1', true);
        if (!array_key_exists($oid, $this->tables[''] ?? []))
            throw new SnmpError('No Such Instance currently exists at this OID');
        return $this->tables[''][$oid];
    }

    public function set($oid, $type, $value)
    {
        $this->sets[] = [$oid, $type, $value];
        $why = $this->refuse[$oid.'='.$value] ?? $this->refuse[$oid] ?? null;
        if ($this->timeout || $why === 'timeout')
            throw new SnmpError('No response from 192.0.2.1', true);
        if ($why !== null)
            throw new SnmpError($why);
        $this->tables[''][$oid] = $value;
    }
}
