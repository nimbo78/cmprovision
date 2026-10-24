<?php

namespace App\Services;

use App\Models\Cm;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * The modules that were provisioned, as files NetBox imports (2.21.0, CMs → Export for NetBox): the devices, their
 * interfaces and the MAC addresses of these. NetBox asks for the same few things of every device (site, role, manufacturer,
 * device type), so they are set once in Settings → Provisioning and the files carry them. Names of what must exist in
 * NetBox are the settings' business; the file only repeats them.
 *
 * Which columns the import takes depends on the NetBox: from 4.2 a MAC address is an object of its own that an interface
 * points to (a third file, `is_primary` makes it the interface's address), before that the interface has a mac_address
 * column. Import in the order devices, interfaces, MAC addresses.
 *
 * What comes from the modules is not trusted: a host name that is not a plain name (a module's system may call itself
 * anything, and a cell that starts with = or + is a formula to a spreadsheet) is replaced by a name made from the MAC.
 */
class NetBoxExport
{
    const DEFAULTS = [
        'site' => 'Default', 'role' => 'Sensor', 'manufacturer' => 'Raspberry Pi', 'device_type' => 'Compute Module 4',
        'status' => 'active', 'interface' => 'eth0', 'interface_type' => '1000base-t', 'mac_style' => 'objects',
    ];
    const STATUSES = ['active', 'planned', 'staged', 'offline', 'inventory', 'failed', 'decommissioning'];
    const MAC_STYLES = [
        'objects' => 'NetBox 4.2 or newer: the MAC addresses are a file of their own',
        'column' => 'Before NetBox 4.2: the MAC address is a column of the interfaces',
    ];
    const KINDS = ['devices', 'interfaces', 'macs'];
    const NAME_PREFIX = 'cm-';      // the name of a module that has no usable host name: cm-e45f01629fd3
    const MAX_NAME = 64;            // NetBox's limit for the name of a device

    /* The settings as they stand: what is saved over the defaults */
    public static function settings(): array
    {
        $saved = Setting::whereIn('key', array_map(fn ($k) => 'netbox_'.$k, array_keys(self::DEFAULTS)))->pluck('value', 'key');
        $result = [];
        foreach (self::DEFAULTS as $key => $default)
        {
            $value = trim((string) ($saved['netbox_'.$key] ?? ''));
            $result[$key] = $value !== '' ? $value : $default;
        }
        return $result;
    }

    public static function save(array $values): void
    {
        foreach (self::DEFAULTS as $key => $default)
            Setting::updateOrCreate(['key' => 'netbox_'.$key], ['value' => trim((string) ($values[$key] ?? $default))]);
    }

    /* The modules of $cms that go to NetBox: provisioned and finished, not a board that only had its bootloader set over USB */
    public static function exportable(iterable $cms): Collection
    {
        return collect($cms)->filter(function (Cm $cm) {
            return $cm->phase === 'done' && $cm->usb_prep_at === null;
        })->values();
    }

    /* The CSV of one kind of file for these modules; the modules are filtered as exportable() does */
    public static function csv(string $kind, iterable $cms, ?array $settings = null): string
    {
        if (!in_array($kind, self::KINDS, true))
            throw new \InvalidArgumentException("unknown kind $kind");
        $settings = $settings ?? self::settings();
        $rows = self::rows($kind, self::exportable($cms), $settings);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_keys($rows[0] ?? self::header($kind, $settings)), ',', '"', '');
        foreach ($rows as $row)
            fputcsv($out, array_values($row), ',', '"', '');
        rewind($out);
        $text = stream_get_contents($out);
        fclose($out);
        return $text;
    }

    /* What the first line of a file says when it has no row */
    protected static function header(string $kind, array $settings): array
    {
        $empty = ['devices' => ['name', 'role', 'manufacturer', 'device_type', 'status', 'site', 'serial', 'comments'],
            'interfaces' => $settings['mac_style'] === 'column' ? ['device', 'name', 'type', 'mac_address'] : ['device', 'name', 'type'],
            'macs' => ['mac_address', 'device', 'interface', 'is_primary']][$kind];
        return array_fill_keys($empty, '');
    }

    protected static function rows(string $kind, Collection $cms, array $settings): array
    {
        $names = self::names($cms);
        $rows = [];
        foreach ($cms as $cm)
        {
            $name = $names[$cm->id];
            $mac = strtoupper((string) $cm->mac);
            if ($kind === 'devices')
                $rows[] = ['name' => $name, 'role' => $settings['role'], 'manufacturer' => $settings['manufacturer'],
                    'device_type' => $settings['device_type'], 'status' => $settings['status'], 'site' => $settings['site'],
                    'serial' => (string) $cm->serial, 'comments' => self::comment($cm)];
            elseif ($kind === 'interfaces')
                $rows[] = array_merge(['device' => $name, 'name' => $settings['interface'], 'type' => $settings['interface_type']],
                    $settings['mac_style'] === 'column' ? ['mac_address' => $mac] : []);
            elseif ($settings['mac_style'] !== 'column')
                $rows[] = ['mac_address' => $mac, 'device' => $name, 'interface' => $settings['interface'], 'is_primary' => 'true'];
        }
        return $rows;
    }

    /* The name of each module in NetBox: its host name when that is a plain name and no other module has it, else cm- and its
       MAC without the colons (unique like the MAC) */
    public static function names(Collection $cms): array
    {
        $count = [];
        foreach ($cms as $cm)
            if (self::usable($cm->hostname))
                $count[strtolower($cm->hostname)] = ($count[strtolower($cm->hostname)] ?? 0) + 1;
        $names = [];
        foreach ($cms as $cm)
            $names[$cm->id] = self::usable($cm->hostname) && $count[strtolower($cm->hostname)] === 1
                ? $cm->hostname
                : self::NAME_PREFIX.str_replace(':', '', strtolower((string) ($cm->mac ?: $cm->serial)));
        return $names;
    }

    protected static function usable($hostname): bool
    {
        $hostname = (string) $hostname;
        return $hostname !== '' && strlen($hostname) <= self::MAX_NAME && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $hostname) === 1;
    }

    /* What the device's comments say about where it came from; it starts with a letter whatever the project is called */
    protected static function comment(Cm $cm): string
    {
        $at = $cm->finishedAt();
        return trim('Provisioned by cmprovision'.($cm->project ? ', project '.$cm->project->name : '')
            .($at ? ', '.$at->copy()->utc()->format('Y-m-d H:i').' UTC' : ''));
    }
}
