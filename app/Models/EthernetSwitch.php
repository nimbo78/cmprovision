<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/* A switch of the stand (docs/operations.md#switches-and-poe-restart): how to reach it by SNMP, read and
   write, and what it last said about itself */
class EthernetSwitch extends Model
{
    const SECRETS = ['community', 'auth_password', 'priv_password', 'write_community', 'write_auth_password', 'write_priv_password'];

    protected $table = 'ethernet_switches';

    protected $fillable = ['host', 'snmp_version', 'community', 'user', 'auth_protocol', 'auth_password', 'priv_protocol',
        'priv_password', 'write_community', 'write_user', 'write_auth_protocol', 'write_auth_password', 'write_priv_protocol',
        'write_priv_password', 'method', 'method_detected', 'sys_name', 'base_mac', 'own_macs', 'links'];

    protected $hidden = self::SECRETS;

    protected $casts = ['own_macs' => 'array', 'links' => 'array'];

    protected static function booted()
    {
        // what a module or a PoE outage was found on stays in their text; the link goes
        static::deleting(function (EthernetSwitch $switch) {
            Cm::where('switch_id', $switch->id)->update(['switch_id' => null]);
            PoeOutage::where('switch_id', $switch->id)->update(['switch_id' => null]);
        });
    }

    /** What SwitchSnapshot::forConfig(), PoeControl::forConfig() and the SnmpClient binding take */
    public function config(): array
    {
        $s = function ($value) { return (string) $value; };
        return [
            'id' => $this->id, 'host' => $s($this->host), 'version' => $this->snmp_version ?: '2c',
            'community' => $s($this->community), 'user' => $s($this->user),
            'auth_protocol' => $s($this->auth_protocol), 'auth_password' => $s($this->auth_password),
            'priv_protocol' => $s($this->priv_protocol), 'priv_password' => $s($this->priv_password),
            'method' => $this->method ?: 'auto', 'detected' => $this->method_detected, 'sys_name' => $this->sys_name,
            'write' => [
                'community' => $s($this->write_community), 'user' => $s($this->write_user),
                'auth_protocol' => $s($this->write_auth_protocol), 'auth_password' => $s($this->write_auth_password),
                'priv_protocol' => $s($this->write_priv_protocol), 'priv_password' => $s($this->write_priv_password),
            ],
        ];
    }

    public static function emptyConfig(): array
    {
        return (new self)->config();
    }

    /** The other way round: the SNMP settings of a config() array (what the switch said about itself stays) */
    public function fillConfig(array $config): self
    {
        $w = $config['write'] ?? [];
        $this->fill([
            'host' => $config['host'], 'snmp_version' => $config['version'] ?? '2c', 'community' => $config['community'] ?? '',
            'user' => $config['user'] ?? '', 'auth_protocol' => $config['auth_protocol'] ?? '', 'auth_password' => $config['auth_password'] ?? '',
            'priv_protocol' => $config['priv_protocol'] ?? '', 'priv_password' => $config['priv_password'] ?? '',
            'method' => $config['method'] ?? 'auto',
            'write_community' => $w['community'] ?? '', 'write_user' => $w['user'] ?? '',
            'write_auth_protocol' => $w['auth_protocol'] ?? '', 'write_auth_password' => $w['auth_password'] ?? '',
            'write_priv_protocol' => $w['priv_protocol'] ?? '', 'write_priv_password' => $w['priv_password'] ?? '',
        ]);
        return $this;
    }

    /** sysName without its domain ("sw01" of "sw01.corp.example.com"), an IP address as it is; null without one */
    public static function shortName(?string $sysName): ?string
    {
        $name = trim((string) $sysName);
        if ($name === '')
            return null;
        if (filter_var($name, FILTER_VALIDATE_IP))
            return $name;
        $short = strstr($name, '.', true);
        return $short !== false && $short !== '' ? $short : $name;
    }

    /** The name boards and texts use: the switch's own name, its address before it said one */
    public function hostname(): string
    {
        return self::shortName($this->sys_name) ?? (string) $this->host;
    }

    /** "sw01 Gi0/3": a module's board */
    public function board(string $port): string
    {
        return $this->hostname().' '.$port;
    }
}
