<?php

namespace App\Support;

use App\Models\Cm;
use InvalidArgumentException;

/* A bounded snapshot of the utility OS, never a verdict that a physical component is dead.
   The format contains only whitelisted facts and kernel/MMC output, not project scripts or credentials. */
class StorageDiagnostics
{
    const MAX_BYTES = 32768;
    const REQUEST_SECONDS = 90;
    const FACTS = ['serial', 'kernel', 'model', 'target', 'block', 'controller', 'host', 'card_type',
        'card_name', 'cid', 'csd', 'manfid', 'oemid', 'fwrev', 'life_time', 'pre_eol_info', 'sectors', 'readonly', 'throttled'];
    const CONTEXTS = ['startup', 'failure', 'complete', 'manual'];

    public static function parse(string $text, Cm $cm): array
    {
        if (strlen($text) > self::MAX_BYTES)
            throw new InvalidArgumentException('Diagnostics exceed the size limit.');
        $text = str_replace("\r", '', mb_scrub($text, 'UTF-8'));
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text);
        $lines = explode("\n", $text);
        if (array_shift($lines) !== 'CMPROVISION_DIAGNOSTICS_V1')
            throw new InvalidArgumentException('Unknown diagnostics format.');
        $facts = [];
        $context = '';
        $section = '';
        $sections = ['kernel' => '', 'partitions' => '', 'mmc_ios' => ''];
        foreach ($lines as $line)
        {
            if (preg_match('/^\[([^]]+)\]$/', $line, $match))
            {
                $section = $match[1];
                continue;
            }
            if ($section !== '')
            {
                if (array_key_exists($section, $sections))
                    $sections[$section] .= mb_substr($line, 0, 240)."\n";
                continue;
            }
            if (!str_contains($line, '='))
                continue;
            [$key, $value] = explode('=', $line, 2);
            if ($key === 'context')
                $context = trim($value);
            elseif (in_array($key, self::FACTS, true))
                $facts[$key] = mb_substr(trim($value), 0, 160);
        }
        if (($facts['serial'] ?? '') !== $cm->serial
            || ($facts['target'] ?? '') !== ($cm->provisioning_storage ?: '/dev/mmcblk0')
            || !in_array($context, self::CONTEXTS, true))
            throw new InvalidArgumentException('Diagnostics do not describe this run.');
        foreach (['block', 'controller'] as $key)
            if (!in_array($facts[$key] ?? '', ['present', 'missing', 'unknown'], true))
                $facts[$key] = 'unknown';
        if (!preg_match('/^0x[0-9a-f]{1,8}$/i', $facts['throttled'] ?? ''))
            $facts['throttled'] = 'unknown';
        foreach (['kernel' => 24576, 'partitions' => 2048, 'mmc_ios' => 2048] as $key => $limit)
            $sections[$key] = mb_strcut(rtrim($sections[$key]), 0, $limit, 'UTF-8');
        return ['version' => 1, 'context' => $context, 'facts' => $facts, 'sections' => $sections];
    }

    public static function summary(?array $report): ?array
    {
        if (!$report)
            return null;
        $facts = $report['facts'];
        $kernel = $report['sections']['kernel'];
        $missing = $facts['block'] === 'missing';
        $issues = [];
        $add = function ($code, $severity, $title, $advice, $evidence = '') use (&$issues) {
            $issues[] = compact('code', 'severity', 'title', 'advice', 'evidence');
        };
        $host = preg_match('/^mmc[0-9]+$/', $facts['host'] ?? '') ? $facts['host'] : null;
        if ($host && preg_match('/^.*'.preg_quote($host, '/').': Card stuck being busy!.*$/m', $kernel, $match))
            $add('mmc_busy', $missing ? 'error' : 'warning', $missing ? 'MMC storage did not initialize' : 'MMC initialization errors were reported',
                'The MMC controller reports that the card stays busy. Fully power off the module, check its seating and power supply, '
                .'then compare with a known working module or card. This alone does not prove that the eMMC is faulty.', trim($match[0]));
        elseif ($missing)
            $add('storage_missing', 'error', 'Configured storage was not detected',
                'Check the storage path in the project. A CM4 Lite needs a microSD card; a CM4 with eMMC uses its on-module storage. '
                .'Fully power off before checking the card or module seating, then test with a stable power supply.', $facts['target']);
        preg_match_all('/^.*(?:I\/O error|recovery failed|mmc\d+:.*(?:error -\d+|timed out|timeout)).*$/mi', $kernel, $ioLines);
        $device = preg_quote(basename($facts['target']), '/');
        $devicePattern = '/\b(?:'.$device.'(?:p?[0-9]+)?'.($host ? '|'.preg_quote($host, '/') : '').')\b/';
        foreach ($ioLines[0] as $line)
        {
            if (preg_match($devicePattern, $line))
            {
                $add('storage_io', 'warning', 'Storage I/O errors were reported',
                    'Inspect the kernel output below. Check power and connections and compare with another module or card before attributing the error to a component.', trim($line));
                break;
            }
        }
        if (($facts['readonly'] ?? '') === '1')
            $add('read_only', 'error', 'Storage reports read-only',
                'The kernel reports this block device as read-only. Check card write protection and the device state before retrying a write.');
        if ($facts['throttled'] !== 'unknown')
        {
            $flags = hexdec($facts['throttled']);
            if ($flags & 0x10001)
                $add('undervoltage', 'warning', ($flags & 1) ? 'Undervoltage is reported now' : 'Undervoltage was reported since boot',
                    'Check the power supply, cable and powered USB hub. These firmware flags do not measure the eMMC supply directly.', $facts['throttled']);
        }
        $preEol = preg_match('/^0x0[0-3]$/i', $facts['pre_eol_info'] ?? '') ? hexdec($facts['pre_eol_info']) : 0;
        preg_match_all('/0x([0-9a-f]{2})/i', $facts['life_time'] ?? '', $life);
        $worn = array_filter($life[1], fn ($value) => hexdec($value) >= 10 && hexdec($value) <= 11);
        if (in_array($preEol, [2, 3], true) || $worn)
            $add('emmc_wear', 'warning', 'eMMC reports a wear warning',
                'The eMMC lifetime or reserved-block estimate is near its limit. Plan a replacement and retain the diagnostic report.',
                'life_time='.($facts['life_time'] ?? 'not reported').'; pre_eol_info='.($facts['pre_eol_info'] ?? 'not reported'));
        $severity = collect($issues)->contains('severity', 'error') ? 'error' : ($issues ? 'warning' : 'info');
        return ['title' => $issues[0]['title'] ?? ($facts['block'] === 'present' ? 'Storage detected' : 'Storage status not reported'),
            'severity' => $severity, 'issues' => $issues];
    }

    public static function text(Cm $cm): string
    {
        $report = $cm->storage_diagnostics;
        $text = "cmprovision storage diagnostics\nSerial: ".$cm->serial."\nReceived: ".$cm->storage_diagnostics_at?->toIso8601String()."\n";
        foreach ($report['facts'] as $key => $value)
            $text .= $key.'='.$value."\n";
        foreach ($report['sections'] as $key => $value)
            $text .= "\n[".$key."]\n".$value."\n";
        return $text;
    }
}
