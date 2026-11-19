<?php

namespace App\Support;

use App\Models\Setting;

/* How the front panel looks: its theme and how Ports draws a switch wider than a screen. Kept in the settings table and
   sent to the panel in the snapshot, not in /etc/default/cmprovision-fpms.json: an older panel reading that file takes
   exactly its two keys, and a third would switch it off. */
class FpmsPanelSettings
{
    const THEMES = ['new', 'classic'];
    const PORTS_MAPS = ['pages', 'whole'];
    const COLOR_MODES = ['light', 'dark', 'auto'];
    const DEFAULT_SCREEN_TIMEOUT = 60;
    const SCREEN_TIMEOUTS = [15 => '15 seconds', 30 => '30 seconds', 60 => '1 minute', 120 => '2 minutes',
        300 => '5 minutes', 600 => '10 minutes', 1800 => '30 minutes', 3600 => '1 hour', 0 => 'Never'];

    /* Layout and color mode are independent; an unknown setting falls back to the existing dark appearance. */
    public static function get(): array
    {
        $rows = Setting::whereIn('key', ['fpms_theme', 'fpms_ports_map', 'fpms_color_mode', 'fpms_screen_timeout'])->pluck('value', 'key');
        $seconds = filter_var($rows['fpms_screen_timeout'] ?? null, FILTER_VALIDATE_INT);
        return [
            'theme' => in_array($rows['fpms_theme'] ?? null, self::THEMES, true) ? $rows['fpms_theme'] : 'new',
            'ports_map' => in_array($rows['fpms_ports_map'] ?? null, self::PORTS_MAPS, true) ? $rows['fpms_ports_map'] : 'pages',
            'color_mode' => in_array($rows['fpms_color_mode'] ?? null, self::COLOR_MODES, true) ? $rows['fpms_color_mode'] : 'dark',
            'screen_timeout' => is_int($seconds) && isset(self::SCREEN_TIMEOUTS[$seconds]) ? $seconds : self::DEFAULT_SCREEN_TIMEOUT,
        ];
    }

    public static function save(string $theme, string $portsMap, ?string $colorMode = 'dark', ?int $screenTimeout = null): void
    {
        Setting::updateOrCreate(['key' => 'fpms_theme'], ['value' => $theme]);
        Setting::updateOrCreate(['key' => 'fpms_ports_map'], ['value' => $portsMap]);
        if ($colorMode !== null) self::saveColorMode($colorMode);
        if ($screenTimeout !== null) self::saveScreenTimeout($screenTimeout);
    }

    /** A physical-panel choice must not replace layout/Ports changes made in the web UI. */
    public static function saveColorMode(string $mode): void
    {
        if (!in_array($mode, self::COLOR_MODES, true)) throw new \InvalidArgumentException('Unknown FPMS color mode.');
        Setting::updateOrCreate(['key' => 'fpms_color_mode'], ['value' => $mode]);
    }

    public static function saveScreenTimeout(int $seconds): void
    {
        if (!isset(self::SCREEN_TIMEOUTS[$seconds])) throw new \InvalidArgumentException('Unknown FPMS screen timeout.');
        Setting::updateOrCreate(['key' => 'fpms_screen_timeout'], ['value' => (string) $seconds]);
    }
}
