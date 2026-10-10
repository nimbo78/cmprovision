<?php

namespace App\Support;

/**
 * A Wi-Fi connection as `iw dev <if> link` and `iw dev <if> info` describe it, for the uplink indicator.
 * The generation comes from the bitrate flags: HT is Wi-Fi 4, VHT 5, HE 6 (6E on 6 GHz), EHT 7. FullMAC
 * drivers such as the CM4's own brcmfmac report bitrates without flags, so their generation stays unknown.
 */
class WifiLink
{
    /**
     * @return array|null null when not connected; otherwise ssid, freq (MHz), band, channel, width (MHz),
     *                    signal (dBm), rx and tx (Mbit/s), generation ('4', '5', '6', '6E', '7') and streams,
     *                    each null when iw does not say
     */
    public static function parse(string $link, string $info = ''): ?array
    {
        if (!preg_match('/^Connected to /m', $link)) {
            return null;
        }
        $freq = self::number('/^\s*freq:\s*([\d.]+)/m', $link);
        $freq = $freq === null ? null : (int) round($freq);
        $rxLine = self::line('rx bitrate', $link);
        $txLine = self::line('tx bitrate', $link);
        $flags = $txLine.' '.$rxLine;

        $channel = self::number('/^\s*channel (\d+) \(/m', $info);
        $width = self::number('/width: (\d+) MHz/', $info);
        if ($width === null) {
            $width = self::number('/\b(\d+)MHz\b/', $flags);
        }
        $band = self::band($freq);
        $signal = self::number('/^\s*signal:\s*(-?\d+)/m', $link);

        return [
            'ssid' => self::text('/^\s*SSID: (.*)$/m', $link),
            'freq' => $freq,
            'band' => $band,
            'channel' => $channel !== null ? (int) $channel : self::channel($freq),
            'width' => $width === null ? null : (int) $width,
            'signal' => $signal === null ? null : (int) $signal,
            'rx' => self::number('/([\d.]+) MBit\/s/', $rxLine),
            'tx' => self::number('/([\d.]+) MBit\/s/', $txLine),
            'generation' => self::generation($flags, $band),
            'streams' => self::streams($flags),
        ];
    }

    /** a word for the signal strength */
    public static function quality(int $dbm): string
    {
        if ($dbm >= -55) {
            return 'Excellent';
        }
        if ($dbm >= -67) {
            return 'Good';
        }
        return $dbm >= -75 ? 'Fair' : 'Weak';
    }

    /** lit arcs of the Wi-Fi icon, 0 to 3 */
    public static function arcs(int $dbm): int
    {
        foreach ([3 => -67, 2 => -75, 1 => -82] as $arcs => $floor) {
            if ($dbm >= $floor) {
                return $arcs;
            }
        }
        return 0;
    }

    /** lit bars of the signal meter in the tooltip, 0 to 4 */
    public static function bars(int $dbm): int
    {
        foreach ([4 => -55, 3 => -67, 2 => -75, 1 => -82] as $bars => $floor) {
            if ($dbm >= $floor) {
                return $bars;
            }
        }
        return 0;
    }

    private static function generation(string $flags, ?string $band): ?string
    {
        if (strpos($flags, 'EHT-MCS') !== false) {
            return '7';
        }
        if (strpos($flags, 'HE-MCS') !== false) {
            return $band === '6 GHz' ? '6E' : '6';
        }
        if (strpos($flags, 'VHT-MCS') !== false) {
            return '5';
        }
        return preg_match('/(^|\s)MCS \d+/', $flags) ? '4' : null;
    }

    /** the most spatial streams either direction uses */
    private static function streams(string $flags): ?int
    {
        if (preg_match_all('/(?:EHT|HE|VHT)-NSS (\d+)/', $flags, $m)) {
            return max(array_map('intval', $m[1]));
        }
        // Wi-Fi 4 numbers its MCS across streams: 0-7 is one stream, 8-15 two, and so on
        if (preg_match_all('/(?:^|\s)MCS (\d+)/', $flags, $m)) {
            return max(array_map(function ($mcs) { return intdiv((int) $mcs, 8) + 1; }, $m[1]));
        }
        return null;
    }

    private static function band(?int $freq): ?string
    {
        if ($freq === null) {
            return null;
        }
        if ($freq >= 2400 && $freq < 2500) {
            return '2.4 GHz';
        }
        if ($freq >= 5925 && $freq <= 7125) {
            return '6 GHz';
        }
        return $freq >= 4900 && $freq < 5925 ? '5 GHz' : null;
    }

    private static function channel(?int $freq): ?int
    {
        if ($freq === null) {
            return null;
        }
        if ($freq === 2484) {
            return 14;
        }
        if ($freq >= 2400 && $freq < 2500) {
            return intdiv($freq - 2407, 5);
        }
        if ($freq === 5935) {
            return 2;
        }
        if ($freq >= 5950 && $freq <= 7125) {
            return intdiv($freq - 5950, 5);
        }
        return $freq >= 4900 && $freq < 5925 ? intdiv($freq - 5000, 5) : null;
    }

    private static function line(string $name, string $text): string
    {
        return preg_match('/^\s*'.preg_quote($name, '/').':\s*(.*)$/m', $text, $m) ? $m[1] : '';
    }

    private static function number(string $pattern, string $text): ?float
    {
        return preg_match($pattern, $text, $m) ? (float) $m[1] : null;
    }

    private static function text(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $m) ? $m[1] : null;
    }
}
