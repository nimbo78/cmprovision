<?php

namespace App\Support;

use App\Models\Cm;

/* One read-only interpretation for web, HDMI and FPMS. Factory attributes are firmware-reported,
   not proof of authenticity. A catalogue SKU is always inferred, never read from a product label. */
class ModuleHardware
{
    public static function forModule(Cm $cm): array
    {
        $facts = $cm->storage_diagnostics['facts'] ?? [];
        $facts['model'] = ($facts['model'] ?? '') ?: $cm->model;
        $facts['ram_gib'] = $cm->memory_in_gb;
        return self::fromFacts($facts);
    }

    public static function fromFacts(array $facts): array
    {
        $model = self::text($facts['model'] ?? null);
        $revision = null;
        if (preg_match('/\s+Rev\s+([0-9]+\.[0-9]+)$/D', $model, $match))
        {
            $revision = $match[1];
            $model = substr($model, 0, -strlen($match[0]));
        }
        $code = self::word($facts['revision_code'] ?? null);
        $extended = self::word($facts['board_revision_ext'] ?? null);
        $modern = $code !== null && ($code & 0x800000) !== 0;
        $type = $modern ? ($code >> 4) & 0xff : null;
        $cm4 = $type === 0x14 && in_array($model, ['', 'Raspberry Pi Compute Module 4', 'Compute Module 4', 'CM4'], true);
        if ($cm4)
        {
            $model = 'Raspberry Pi Compute Module 4';
            $revision = '1.'.($code & 0xf);
        }
        $ram = $modern ? ([0 => 0.25, 1 => 0.5, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 16][($code >> 20) & 7] ?? null) : null;
        if ($ram === null)
            $ram = self::positive($facts['ram_gib'] ?? null, 1024);
        if ($ram === null && ($kb = self::positive($facts['mem_total_kb'] ?? null, 1000000000)) !== null)
            $ram = max(1, round(($kb + 200000) / 1048576));

        $wifi = $fitted = null;
        if ($cm4 && $extended !== null)
        {
            $wifi = ($extended & 0x40000000) === 0;
            $fitted = ($extended & 0x80000000) === 0;
        }
        $detected = ($facts['emmc'] ?? null) === 'detected';
        $sectors = $detected ? self::positive($facts['emmc_sectors'] ?? null, 2000000000) : null;
        $nominal = null;
        if ($sectors !== null)
            foreach ([8, 16, 32] as $gb)
                if ($sectors * 512 >= $gb * 1e9 * 0.90 && $sectors * 512 <= $gb * 1e9 * 1.05)
                    $nominal = $gb;
        $emmc = 'Unknown';
        $conflict = $fitted === false && $detected;
        if ($conflict)
            $emmc = 'Conflict: Lite / eMMC detected';
        elseif ($fitted === false)
            $emmc = 'Not fitted (Lite)';
        elseif ($detected)
            $emmc = ($nominal ?? ($sectors !== null ? round($sectors * 512 / 1e9, 1) : '?')).' GB (detected)';
        elseif ($fitted === true)
            $emmc = ($facts['emmc'] ?? null) === 'not_detected' ? 'Fitted; not detected' : 'Fitted; capacity unknown';

        $sku = null;
        if ($cm4 && !$conflict && $wifi !== null && in_array($ram, [1, 2, 4, 8], true)
            && ($fitted === false || ($fitted === true && $nominal !== null)))
            $sku = sprintf('CM4%d%02d%03d (inferred)', $wifi ? 1 : 0, $ram, $fitted ? $nominal : 0);
        return ['model' => $model ?: 'Unknown', 'revision' => $revision ?? 'Unknown',
            'ram' => $ram !== null ? $ram.' GiB' : 'Unknown', 'emmc' => $emmc,
            'wifi' => $wifi === null ? 'Unknown' : ($wifi ? 'Built-in' : 'Not fitted'), 'sku' => $sku ?? 'Unknown'];
    }

    private static function word($value): ?int
    {
        return is_string($value) && preg_match('/^(?:0x)?[a-f0-9]{1,8}$/Di', $value)
            ? (int) hexdec($value) : null;
    }

    private static function positive($value, int $maximum): ?int
    {
        return (is_int($value) || is_string($value)) && preg_match('/^[0-9]{1,12}$/D', (string) $value)
            && (float) $value > 0 && (float) $value <= $maximum ? (int) $value : null;
    }

    private static function text($value): string
    {
        return is_string($value) ? mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/', ' ', $value)), 0, 100) : '';
    }
}
