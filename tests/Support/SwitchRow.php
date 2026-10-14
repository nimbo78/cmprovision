<?php

namespace Tests\Support;

use App\Models\EthernetSwitch;

/* The first switch of the stand, set and read by 2.3's setting names (ip, snmp_community, snmp_write_community...) */
class SwitchRow
{
    public static function put(array $settings): EthernetSwitch
    {
        $columns = [];
        foreach ($settings as $key => $value)
            $columns[self::column($key)] = $value;
        $switch = EthernetSwitch::orderBy('id')->first() ?? new EthernetSwitch;
        $switch->forceFill($columns)->save();
        return $switch;
    }

    public static function get(string $key)
    {
        $switch = EthernetSwitch::orderBy('id')->first();
        return $switch ? $switch->getAttribute(self::column($key)) : null;
    }

    public static function column(string $key): string
    {
        return ['ip' => 'host', 'snmp_version' => 'snmp_version'][$key] ?? preg_replace('/^snmp_/', '', $key);
    }
}
