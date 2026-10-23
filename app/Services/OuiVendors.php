<?php

namespace App\Services;

use App\Services\Switch\Neighbors;
use App\Support\StandMap;
use Illuminate\Support\Facades\Cache;

/**
 * Who made a network adapter, from the first digits of its MAC address, for the Ports page: what a device that says
 * nothing about itself (no LLDP, no CDP) can be. The names come from the IEEE registries of the ieee-data package: oui.txt
 * (24 bit blocks), mam.txt (28 bit), oui36.txt and iab.txt (36 bit); the longest block that matches wins. In the files of
 * the longer blocks the 24 bit OUI is on the "(hex)" line and the "(base 16)" line gives a range of the rest, so a block is
 * the OUI plus the first digits of the range. Without the package only the Raspberry Pi blocks are known. A locally
 * administered address (a random or virtual one) has no maker. The answers are kept for an hour: the files are long.
 */
class OuiVendors
{
    const FILES = [9 => ['oui36.txt', 'iab.txt'], 7 => ['mam.txt'], 6 => ['oui.txt']];
    const LOCAL = 'locally administered address (random or virtual)';
    const KEEP = 3600;

    /** [mac as given => vendor name or null] */
    public static function names(array $macs): array
    {
        $dir = rtrim((string) config('cmprovision.ieee_data_dir', '/usr/share/ieee-data'), '/');
        // a new package version is a new file: the answers of the old one stop being used
        $signature = crc32($dir).'.'.(int) @filemtime($dir.'/oui.txt');
        $result = $wanted = $keys = $raspberry = [];
        foreach ($macs as $mac)
        {
            $result[$mac] = null;
            $hex = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', (string) $mac));
            if (strlen($hex) !== 12)
                continue;
            if (hexdec($hex[1]) & 2)
            {
                $result[$mac] = self::LOCAL;
                continue;
            }
            $key = 'oui:'.$signature.':'.substr($hex, 0, 9);
            $known = Cache::get($key);
            if ($known !== null)
            {
                $result[$mac] = $known === '' ? null : $known;
                continue;
            }
            $keys[$mac] = $key;
            foreach (StandMap::RASPBERRY_PI_PREFIXES as $prefix)
                if (strncmp($hex, $prefix, strlen($prefix)) === 0)
                    $raspberry[$mac] = true;
            foreach (array_keys(self::FILES) as $length)
                $wanted[$length][substr($hex, 0, $length)][] = $mac;
        }
        $named = [];
        foreach (self::FILES as $length => $files)
            foreach ($files as $file)
                if (!empty($wanted[$length]))
                    self::scan($dir.'/'.$file, $length, $wanted[$length], $result, $named);
        foreach ($keys as $mac => $key)
        {
            // the registry may say only that the block is the registration authority's: the built-in name is better
            if (isset($raspberry[$mac]) && ($result[$mac] === null || stripos($result[$mac], 'IEEE Registration Authority') === 0))
                $result[$mac] = 'Raspberry Pi Trading Ltd';
            Cache::put($key, $result[$mac] ?? '', self::KEEP);
        }
        return $result;
    }

    public static function name(?string $mac): ?string
    {
        return $mac === null ? null : self::names([$mac])[$mac];
    }

    /* Reads one registry until every wanted block of this length is found; a MAC already named by a longer block keeps that name */
    protected static function scan(string $path, int $length, array $wanted, array &$result, array &$named): void
    {
        $handle = is_readable($path) ? @fopen($path, 'r') : false;
        if (!$handle)
            return;
        $open = $wanted;
        $oui = null;
        while ($open && ($line = fgets($handle)) !== false)
        {
            if (strpos($line, '(hex)') !== false)
            {
                $oui = preg_match('/^([0-9A-Fa-f]{2})-([0-9A-Fa-f]{2})-([0-9A-Fa-f]{2})\s+\(hex\)/', $line, $m) ? strtolower($m[1].$m[2].$m[3]) : null;
                continue;
            }
            if (strpos($line, '(base 16)') === false || !preg_match('/^([0-9A-Fa-f]{6})(?:-[0-9A-Fa-f]{6})?\s+\(base 16\)\s+(.+?)\s*$/', $line, $m))
                continue;
            $block = $length === 6 ? strtolower($m[1]) : ($oui !== null ? $oui.strtolower(substr($m[1], 0, $length - 6)) : null);
            if ($block === null || !isset($open[$block]))
                continue;
            foreach ($open[$block] as $mac)
                if (empty($named[$mac]))
                {
                    $result[$mac] = Neighbors::text($m[2], 64);
                    $named[$mac] = true;
                }
            unset($open[$block]);
        }
        fclose($handle);
    }
}
