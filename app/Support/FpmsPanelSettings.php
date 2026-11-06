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

    /* Layout and color mode are independent; an unknown setting falls back to the existing dark appearance. */
    public static function get(): array
    {
        $rows = Setting::whereIn('key', ['fpms_theme', 'fpms_ports_map', 'fpms_color_mode'])->pluck('value', 'key');
        return [
            'theme' => in_array($rows['fpms_theme'] ?? null, self::THEMES, true) ? $rows['fpms_theme'] : 'new',
            'ports_map' => in_array($rows['fpms_ports_map'] ?? null, self::PORTS_MAPS, true) ? $rows['fpms_ports_map'] : 'pages',
            'color_mode' => in_array($rows['fpms_color_mode'] ?? null, self::COLOR_MODES, true) ? $rows['fpms_color_mode'] : 'dark',
        ];
    }

    public static function save(string $theme, string $portsMap, string $colorMode = 'dark'): void
    {
        Setting::updateOrCreate(['key' => 'fpms_theme'], ['value' => $theme]);
        Setting::updateOrCreate(['key' => 'fpms_ports_map'], ['value' => $portsMap]);
        Setting::updateOrCreate(['key' => 'fpms_color_mode'], ['value' => $colorMode]);
    }
}
