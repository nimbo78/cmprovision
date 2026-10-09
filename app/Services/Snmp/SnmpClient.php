<?php

namespace App\Services\Snmp;

interface SnmpClient
{
    /**
     * Every object below $oid as [numeric oid starting with "." => plain value]; [] when the agent
     * has nothing there. Throws SnmpError when the agent does not answer or refuses.
     * $vlan selects a Cisco-style per-VLAN instance: community@vlan for v2c, context "vlan-<id>" for v3.
     */
    public function walk($oid, $vlan = null);
}
