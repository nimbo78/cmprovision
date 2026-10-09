<?php

namespace Tests\Support;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;

/** A switch answering from a table of OIDs, per VLAN instance like Cisco IOS does */
class FakeSnmp implements SnmpClient
{
    public $tables, $walks = [], $timeout = false;

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
}
