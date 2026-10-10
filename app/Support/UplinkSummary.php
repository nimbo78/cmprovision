<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** What the uplink icon in the top bar and its tooltip say, from a NetworkStatus snapshot. */
class UplinkSummary
{
    /**
     * @return array icon (wifi, wired, offline), arcs (0-3), badge (Wi-Fi generation or Ethernet speed),
     *               tone (normal, warn, bad), label (for screen readers), heading, note, warning, rows
     *               ([label, value] of the uplink), bars (signal meter, 0-4), sections ([title, line] for the
     *               backups and the modules' network), checked
     */
    public static function from(array $status): array
    {
        $view = [
            'icon' => 'offline', 'arcs' => 0, 'badge' => null, 'tone' => 'bad',
            'label' => 'No internet connection', 'heading' => 'No internet connection', 'note' => null,
            'warning' => 'The server has no default route. Modules still provision: their network is separate.',
            'rows' => [], 'bars' => null, 'sections' => [],
            'checked' => 'Checked at '.Carbon::parse($status['checked_at'])->local()->format('H:i:s'),
        ];
        $uplink = $status['uplink'];
        if ($uplink !== null) {
            $view = array_merge($view, $uplink['kind'] === 'wifi' ? self::wifi($uplink) : self::wired($uplink));
            $view['tone'] = 'normal';
            $view['warning'] = null;
            if ($status['preferred_down'] !== null) {
                $view['tone'] = 'warn';
                $view['warning'] = 'Preferred connection '.$status['preferred_down'].' is not connected, the backup carries the traffic.';
                $view['label'] .= '. Preferred connection '.$status['preferred_down'].' is not connected';
            }
        }
        foreach ($status['backups'] as $backup) {
            $view['sections'][] = ['Backup: '.self::adapterPhrase($backup).' ('.$backup['name'].')', self::backupLine($backup)];
        }
        if ($status['modules'] !== null) {
            $modules = $status['modules'];
            $view['sections'][] = ['Modules network: '.self::kindName($modules).' ('.$modules['name'].')', self::modulesLine($modules)];
        }

        return $view;
    }

    private static function wifi(array $uplink): array
    {
        $w = $uplink['wifi'] !== null ? $uplink['wifi'] : [
            'ssid' => null, 'band' => null, 'channel' => null, 'width' => null, 'signal' => null,
            'rx' => null, 'tx' => null, 'generation' => null, 'streams' => null,
        ];
        $signal = $w['signal'];
        $label = 'Internet via Wi-Fi'.($w['generation'] !== null ? ' '.$w['generation'] : '');
        if ($signal !== null) {
            $label .= ', '.strtolower(WifiLink::quality($signal)).' signal';
        }
        $rate = self::linkRate($w);
        if ($rate !== null) {
            $label .= ', '.self::speed($rate);
        }

        $band = array_filter([
            $w['band'],
            $w['channel'] !== null ? 'channel '.$w['channel'] : null,
            $w['width'] !== null ? $w['width'].' MHz wide' : null,
        ]);
        $speed = self::wifiSpeed($w);
        $rows = array_filter([
            $w['ssid'] !== null ? ['Network', $w['ssid']] : null,
            $speed !== null ? ['Speed', $speed] : null,
            $signal !== null ? ['Signal', WifiLink::quality($signal).', '.self::dbm($signal)] : null,
            $band ? ['Band', implode(', ', $band)] : null,
            ['Adapter', self::adapterShort($uplink).' ('.$uplink['name'].')'],
            $uplink['addresses'] ? ['Address', self::bare($uplink['addresses'][0])] : null,
        ]);

        return [
            'icon' => 'wifi',
            'arcs' => $signal !== null ? WifiLink::arcs($signal) : 3,
            'badge' => $w['generation'],
            'label' => $label,
            'heading' => 'Internet via Wi-Fi',
            'note' => $w['generation'] !== null ? 'Wi-Fi '.$w['generation'] : null,
            'rows' => array_values($rows),
            'bars' => $signal !== null ? WifiLink::bars($signal) : null,
        ];
    }

    private static function wired(array $uplink): array
    {
        $speed = isset($uplink['speed']) ? $uplink['speed'] : null;
        $kind = self::kindName($uplink);
        $rows = array_filter([
            ['Speed', self::wiredSpeed($uplink)],
            ['Adapter', self::adapterShort($uplink).' ('.$uplink['name'].')'],
            $uplink['addresses'] ? ['Address', self::bare($uplink['addresses'][0])] : null,
        ]);

        return [
            'icon' => 'wired',
            'arcs' => 0,
            'badge' => $speed !== null ? self::badge($speed) : null,
            'label' => 'Internet via '.$kind.($speed !== null ? ', '.self::speed($speed) : ''),
            'heading' => 'Internet via '.$kind,
            'note' => $speed !== null ? self::speed($speed) : null,
            'rows' => array_values($rows),
            'bars' => null,
        ];
    }

    private static function backupLine(array $backup): string
    {
        if ($backup['kind'] !== 'wifi') {
            return implode(', ', array_merge([self::wiredSpeed($backup)], $backup['addresses']));
        }
        if ($backup['wifi'] === null) {
            return 'Not connected';
        }
        $w = $backup['wifi'];

        return implode(', ', array_filter([
            'Connected',
            $w['signal'] !== null ? self::dbm($w['signal']) : null,
            self::wifiSpeed($w),
            $backup['addresses'] ? self::bare($backup['addresses'][0]) : null,
        ]));
    }

    private static function modulesLine(array $modules): string
    {
        return implode(', ', array_merge([self::wiredSpeed($modules)], $modules['addresses']));
    }

    /**
     * The link speed is the transmit rate, as NetworkManager and Windows show it: an idle link's receive rate
     * is that of its last frame, often a broadcast at the 6 Mbit/s basic rate.
     */
    private static function linkRate(array $w): ?float
    {
        return $w['tx'] !== null ? $w['tx'] : $w['rx'];
    }

    /** "1.2 Gbit/s, 2 streams" */
    private static function wifiSpeed(array $w): ?string
    {
        $rate = self::linkRate($w);
        if ($rate === null) {
            return null;
        }
        $text = self::speed($rate);
        if ($w['streams'] !== null) {
            $text .= ', '.$w['streams'].($w['streams'] === 1 ? ' stream' : ' streams');
        }

        return $text;
    }

    /** "1 Gbit/s full duplex", "No link" */
    private static function wiredSpeed(array $iface): string
    {
        $speed = isset($iface['speed']) ? $iface['speed'] : null;
        if (!$iface['up']) {
            return 'No link';
        }
        if ($speed === null) {
            return 'Link up';
        }
        $duplex = isset($iface['duplex']) ? $iface['duplex'] : null;

        return self::speed($speed).($duplex !== null ? ' '.$duplex.' duplex' : '');
    }

    /** Mbit/s as people read it: "1.2 Gbit/s", "866 Mbit/s", "6.5 Mbit/s" */
    private static function speed(float $mbit): string
    {
        if ($mbit >= 1000) {
            return self::decimal($mbit / 1000).' Gbit/s';
        }
        if ($mbit >= 10) {
            return (int) round($mbit).' Mbit/s';
        }

        return self::decimal($mbit).' Mbit/s';
    }

    /** Ethernet speed on the icon: 100M, 1G, 2.5G */
    private static function badge(int $mbit): string
    {
        return $mbit >= 1000 ? self::decimal($mbit / 1000).'G' : $mbit.'M';
    }

    private static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private static function dbm(int $dbm): string
    {
        return ($dbm < 0 ? '−'.abs($dbm) : $dbm).' dBm';
    }

    private static function kindName(array $iface): string
    {
        if ($iface['kind'] === 'wifi') {
            return 'Wi-Fi';
        }

        return $iface['kind'] === 'ethernet' ? 'Ethernet' : $iface['name'];
    }

    /** the Adapter row: "M.2 card", "USB adapter", "Built-in" */
    private static function adapterShort(array $iface): string
    {
        if ($iface['bus'] === 'pci') {
            return 'M.2 card';
        }

        return $iface['bus'] === 'usb' ? 'USB adapter' : 'Built-in';
    }

    /** a backup's title: "built-in Wi-Fi", "M.2 Wi-Fi card", "USB Ethernet" */
    private static function adapterPhrase(array $iface): string
    {
        $kind = self::kindName($iface);
        if ($iface['bus'] === 'pci') {
            return 'M.2 '.$kind.' card';
        }

        return $iface['bus'] === 'usb' ? 'USB '.$kind : 'built-in '.$kind;
    }

    private static function bare(string $cidr): string
    {
        $parts = explode('/', $cidr);

        return $parts[0];
    }
}
