<?php

namespace Tests\Support;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;

/** A switch answering from a table of OIDs, per VLAN instance like Cisco IOS does; writes go to the table */
class FakeSnmp implements SnmpClient
{
    public $tables, $walks = [], $timeout = false, $host;
    public $sets = [];     // every write as [oid, type, value], refused ones too
    // oid => message, or "oid=value" => message: that write throws; the message "timeout" times out, "warning" throws
    // what a php-snmp warning becomes (an ErrorException), "ignored" is taken without changing anything
    public $refuse = [];
    public $onWalk = null;   // called on every walk: a test acts while a poll runs

    public function __construct(array $tables, string $host = '192.0.2.1')
    {
        $this->tables = $tables;   // ['' => [oid => value], '20' => [...]] keyed by VLAN instance
        $this->host = $host;
    }

    public function walk($oid, $vlan = null)
    {
        if ($this->onWalk)
            ($this->onWalk)($oid, $vlan);
        $this->walks[] = [$oid, $vlan];
        if ($this->timeout)
            throw new SnmpError('No response from '.$this->host, true);
        $result = [];
        foreach ($this->tables[(string) $vlan] ?? [] as $key => $value)
            if (strpos($key, $oid.'.') === 0)
                $result[$key] = $value;
        return $result;
    }

    public function get($oid)
    {
        if ($this->timeout)
            throw new SnmpError('No response from '.$this->host, true);
        if (!array_key_exists($oid, $this->tables[''] ?? []))
            throw new SnmpError('No Such Instance currently exists at this OID');
        return $this->tables[''][$oid];
    }

    public function set($oid, $type, $value)
    {
        $this->sets[] = [$oid, $type, $value];
        $why = $this->refuse[$oid.'='.$value] ?? $this->refuse[$oid] ?? null;
        if ($this->timeout || $why === 'timeout')
            throw new SnmpError('No response from '.$this->host, true);
        if ($why === 'warning')
            throw new \ErrorException('snmp2_set(): Could not open snmp connection');
        if ($why === 'ignored')
            return;
        if ($why !== null)
            throw new SnmpError($why);
        $this->tables[''][$oid] = $value;
    }
}
