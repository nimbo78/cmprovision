<?php

namespace App\Services\Switch;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;
use Illuminate\Support\Sleep;

/**
 * Switches a port's PoE off and on with the switch's write credentials (Settings, "Write access (PoE restart)").
 * The object comes from Poe::admin(); in both MIBs 1 is on and 2 is off (HUAWEI-POE-MIB hwPoePortEnable,
 * POWER-ETHERNET-MIB pethPsePortAdminEnable). Both keep the value over a reboot of the switch.
 */
class PoeControl
{
    const ON = 1;
    const OFF = 2;
    const TRIES = 3;

    protected $snmp;

    public function __construct(SnmpClient $snmp)
    {
        $this->snmp = $snmp;
    }

    /* Write access is set for the switch: a write community (v2c) or a write user (v3) */
    public static function writable(array $config): bool
    {
        if (!$config['host'])
            return false;
        $write = $config['write'] ?? [];
        return $config['version'] === '3' ? ($write['user'] ?? '') !== '' : ($write['community'] ?? '') !== '';
    }

    /* A control talking with the write credentials of these settings (SwitchPortFinder::config()), null without them */
    public static function forConfig(array $config): ?self
    {
        if (!self::writable($config))
            return null;
        return new self(app(SnmpClient::class, ['config' => array_merge($config, $config['write'])]));
    }

    public function switchOff(string $oid): void
    {
        $this->snmp->set($oid, 'i', self::OFF);
    }

    /* Switch on: up to TRIES writes a second apart, each read back. Null when it is on, otherwise why not */
    public function switchOnChecked(string $oid, int $on = self::ON): ?string
    {
        $error = null;
        for ($try = 1; $try <= self::TRIES; $try++)
        {
            if ($try > 1)
                Sleep::for(1)->second();
            try
            {
                $this->snmp->set($oid, 'i', $on);
                $value = $this->value($oid);
                if ($value === $on)
                    return null;
                $error = 'the switch reads '.$value.' after writing '.$on;
            }
            catch (\Throwable $e)
            {
                $error = $e->getMessage();   // an SNMP error, or a php-snmp warning: tried again all the same
            }
        }
        return $error;
    }

    public function value(string $oid): int
    {
        return (int) $this->snmp->get($oid);
    }

    /**
     * Write access without a change: the first PoE port's value is written back as it is.
     * @param array $admin Poe::admin() of the switch
     * @return int|null the ifIndex it used, null when the switch has no PoE ports; throws SnmpError
     */
    public function check(array $admin): ?int
    {
        foreach ($admin as $ifIndex => $oid)
        {
            $this->snmp->set($oid, 'i', $this->value($oid));
            return $ifIndex;
        }
        return null;
    }
}
