<?php

namespace App\Support;

/* The provisioner's ARP cache (/proc/net/arp, readable by www-data like /proc/net/route): the MAC a switch of the
   stand answers from, so its neighbour's port towards it can be told by that address */
class ArpTable
{
    public static $path = '/proc/net/arp';

    /** The MAC of an address ("172.20.233.77", "172.20.233.77:161" or a name), null when the cache has none */
    public static function macOf(string $host): ?string
    {
        $ip = self::ip($host);
        if ($ip === null)
            return null;
        $lines = @file(self::$path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_slice($lines, 1) as $line)
        {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) >= 4 && $cols[0] === $ip && (hexdec($cols[2]) & 0x2) && $cols[3] !== '00:00:00:00:00:00')
                return strtolower($cols[3]);
        }
        return null;
    }

    protected static function ip(string $host): ?string
    {
        if (preg_match('/^([^:]+):\d+$/', $host, $m))
            $host = $m[1];
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            return $host;
        $ip = gethostbyname($host);
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : null;
    }
}
