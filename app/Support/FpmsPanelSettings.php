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

    /* ['theme' => new|classic, 'ports_map' => pages|whole]; a value the panel would not know reads as the default */
    public static function get(): array
    {
        $rows = Setting::whereIn('key', ['fpms_theme', 'fpms_ports_map'])->pluck('value', 'key');
        return [
            'theme' => in_array($rows['fpms_theme'] ?? null, self::THEMES, true) ? $rows['fpms_theme'] : 'new',
            'ports_map' => in_array($rows['fpms_ports_map'] ?? null, self::PORTS_MAPS, true) ? $rows['fpms_ports_map'] : 'pages',
        ];
    }

    public static function save(string $theme, string $portsMap): void
    {
        Setting::updateOrCreate(['key' => 'fpms_theme'], ['value' => $theme]);
        Setting::updateOrCreate(['key' => 'fpms_ports_map'], ['value' => $portsMap]);
    }
}
