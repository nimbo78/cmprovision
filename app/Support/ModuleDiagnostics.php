<?php

namespace App\Support;

use App\Models\Cm;
use InvalidArgumentException;

/* A bounded snapshot of the utility OS, never a verdict that a physical component is dead.
   The format contains only whitelisted facts and selected kernel output, not project scripts or credentials. */
class ModuleDiagnostics
{
    const MAX_BYTES = 32768;
    const REQUEST_SECONDS = 90;
    /* A request nobody answered stops counting: the card stops warning about it and USB no longer collects for it. */
    const REQUEST_EXPIRES_SECONDS = 900;
    const FACTS = ['serial', 'kernel', 'model', 'target', 'block', 'controller', 'host', 'card_type',
        'card_name', 'cid', 'csd', 'manfid', 'oemid', 'fwrev', 'life_time', 'pre_eol_info', 'sectors', 'readonly', 'throttled',
        'boot_id', 'event_id', 'mac', 'network_stage', 'last_http_code', 'last_curl_exit',
        'architecture', 'utility_os', 'boot_mode', 'bootloader_version', 'temperature_mc', 'thermal_sensor', 'thermal_trip_mc',
        'mem_total_kb', 'mem_available_kb', 'uptime_seconds', 'ethernet', 'eth_carrier', 'eth_operstate', 'eth_speed_mbps',
        'eth_duplex', 'eth_rx_errors', 'eth_tx_errors', 'eth_rx_dropped', 'eth_tx_dropped'];
    const CONTEXTS = ['discovery', 'startup', 'failure', 'complete', 'manual'];

    public static function parse(string $text, ?Cm $cm = null): array
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
        $sections = ['kernel' => '', 'partitions' => '', 'mmc_ios' => '', 'network' => ''];
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
        if (!preg_match('/^[0-9a-f]{16}$/D', $facts['serial'] ?? '')
            || ($facts['serial'] ?? '') === str_repeat('0', 16)
            || ($cm && (($facts['serial'] ?? '') !== $cm->serial
                || ($facts['target'] ?? '') !== ($cm->provisioning_storage ?: '/dev/mmcblk0')))
            || (!$cm && !preg_match('#^/dev/(?:mmcblk[0-9]+|sd[a-z]+|nvme[0-9]+n[0-9]+)$#D', $facts['target'] ?? ''))
            || !in_array($context, self::CONTEXTS, true))
            throw new InvalidArgumentException('Diagnostics do not describe this run.');
        foreach (['boot_id', 'event_id'] as $key)
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $facts[$key] ?? ''))
                $facts[$key] = '';
        if (!preg_match('/^(?:[a-f0-9]{2}:){5}[a-f0-9]{2}$/Di', $facts['mac'] ?? ''))
            $facts['mac'] = '';
        else
            $facts['mac'] = strtolower($facts['mac']);
        if (!in_array($facts['network_stage'] ?? '', ['waiting_interface', 'waiting_link', 'dhcp', 'script_download', 'script_running', 'script_ended'], true))
            $facts['network_stage'] = '';
        foreach (['block', 'controller', 'ethernet'] as $key)
            if (!in_array($facts[$key] ?? '', ['present', 'missing', 'unknown'], true))
                $facts[$key] = 'unknown';
        if (!preg_match('/^0x[0-9a-f]{1,8}$/i', $facts['throttled'] ?? ''))
            $facts['throttled'] = 'unknown';
        // Missing, malformed and unsupported readings stay unknown, never zero/healthy.
        foreach (['last_http_code' => [0, 599], 'last_curl_exit' => [0, 255], 'temperature_mc' => [-40000, 200000], 'thermal_trip_mc' => [1, 200000],
            'mem_total_kb' => [1, 1000000000000], 'mem_available_kb' => [0, 1000000000000],
            'uptime_seconds' => [0, 10000000000], 'eth_speed_mbps' => [1, 1000000],
            'eth_rx_errors' => [0, PHP_INT_MAX], 'eth_tx_errors' => [0, PHP_INT_MAX],
            'eth_rx_dropped' => [0, PHP_INT_MAX], 'eth_tx_dropped' => [0, PHP_INT_MAX]] as $key => [$min, $max])
        {
            $value = $facts[$key] ?? '';
            $facts[$key] = preg_match('/^-?[0-9]{1,19}$/D', $value) && (float) $value >= $min && (float) $value <= $max ? $value : '';
        }
        if (!in_array($facts['eth_carrier'] ?? '', ['0', '1'], true))
            $facts['eth_carrier'] = '';
        foreach (['kernel' => 24576, 'partitions' => 2048, 'mmc_ios' => 2048, 'network' => 2048] as $key => $limit)
            $sections[$key] = mb_strcut(rtrim($sections[$key]), 0, $limit, 'UTF-8');
        return ['version' => 1, 'context' => $context, 'facts' => $facts, 'sections' => $sections];
    }

    public static function summary(?array $report, ?string $onlyGroup = null): ?array
    {
        if (!$report)
            return null;
        $facts = $report['facts'];
        $kernel = $report['sections']['kernel'];
        $missing = $facts['block'] === 'missing';
        $issues = [];
        $add = function ($code, $severity, $title, $advice, $evidence = '', $group = 'storage') use (&$issues) {
            $issues[] = compact('code', 'severity', 'title', 'advice', 'evidence', 'group');
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
                    'Check the power supply, cable and powered USB hub. These firmware flags do not measure the eMMC supply directly.', $facts['throttled'], 'power');
        }
        $temperature = self::reading($facts, 'temperature_mc');
        $trip = self::reading($facts, 'thermal_trip_mc');
        if ($temperature !== null && $trip !== null && $temperature >= $trip)
            $add('thermal_trip', 'warning', 'Temperature reached a reported thermal limit',
                'Check cooling, airflow and the workload. This compares the sensor with the lowest passive, hot or critical trip point reported by this kernel.',
                self::temperature($temperature).' / '.self::temperature($trip), 'power');
        if (($flags ?? 0) & 0x80008)
            $add('soft_temperature_limit', 'warning', ($flags & 8) ? 'Soft temperature limit is active' : 'Soft temperature limit occurred since boot',
                'The firmware reported a soft temperature limit. Check cooling and airflow; a historical flag does not mean the module is hot now.', $facts['throttled'], 'power');
        if (($flags ?? 0) & 0x60006)
            $add('throttling', 'warning', ($flags & 6) ? 'CPU performance is limited now' : 'CPU performance was limited since boot',
                'The firmware reported frequency capping or throttling. Check power and cooling together; these flags alone do not identify the cause.', $facts['throttled'], 'power');
        $total = self::reading($facts, 'mem_total_kb');
        $available = self::reading($facts, 'mem_available_kb');
        if ($total !== null && $available !== null && $available <= $total && $available / $total < 0.05)
            $add('memory_pressure', 'warning', 'Less than 5% of RAM is available',
                'This is a snapshot of memory pressure, not a physical RAM test. Inspect running scripts and their memory use before retrying.',
                self::memory($available).' available / '.self::memory($total).' total', 'memory');
        if (preg_match('/^.*(?:Out of memory: Killed process|oom-kill:|invoked oom-killer).*$/mi', $kernel, $match))
            $add('oom', 'warning', 'The kernel reported an out-of-memory event',
                'A process exhausted available memory during this boot. Inspect the workload; this message does not prove a defective RAM chip.', trim($match[0]), 'memory');
        if (($facts['ethernet'] ?? '') === 'missing' || ($facts['eth_carrier'] ?? '') === '0')
            $add('ethernet_link', 'warning', ($facts['ethernet'] ?? '') === 'missing' ? 'eth0 was not detected' : 'Ethernet link was down at collection',
                ($facts['ethernet'] ?? '') === 'missing'
                    ? 'This utility OS did not expose eth0. Check interface naming and whether the image includes the Ethernet driver. This alone does not diagnose a faulty Ethernet controller.'
                    : 'Check the Ethernet cable and switch port. The uploaded snapshot can describe a link that has since recovered.', '', 'network');
        if (($facts['eth_duplex'] ?? '') === 'half')
            $add('ethernet_duplex', 'warning', 'Ethernet negotiated half duplex',
                'Check link negotiation and switch port settings. This is not a throughput test.', ($facts['eth_speed_mbps'] ?? '').' Mbps', 'network');
        if (preg_match('/^.*(?:NETDEV WATCHDOG:.*\beth0\b|\beth0\b.*(?:timed out|tx timeout)).*$/mi', $kernel, $match))
            $add('ethernet_timeout', 'warning', 'The kernel reported a network transmit timeout',
                'Inspect the interface, driver, power and switch port. Retain the report for comparison with another module.', trim($match[0]), 'network');
        if ($report['context'] === 'discovery' && in_array($facts['network_stage'] ?? '', ['dhcp', 'script_download'], true))
            $add('network_boot_wait', 'warning', $facts['network_stage'] === 'dhcp' ? 'DHCP has not completed' : 'The provisioning script has not been obtained',
                'Check DHCP/TFTP and HTTP service logs on the provisioner. This is the last boot stage recorded by the utility OS, not a hardware-failure verdict.',
                'stage='.$facts['network_stage'].'; HTTP='.($facts['last_http_code'] ?? 'not reported').'; curl='.($facts['last_curl_exit'] ?? 'not reported'), 'network');
        $preEol = preg_match('/^0x0[0-3]$/i', $facts['pre_eol_info'] ?? '') ? hexdec($facts['pre_eol_info']) : 0;
        preg_match_all('/0x([0-9a-f]{2})/i', $facts['life_time'] ?? '', $life);
        $worn = array_filter($life[1], fn ($value) => hexdec($value) >= 10 && hexdec($value) <= 11);
        if (in_array($preEol, [2, 3], true) || $worn)
            $add('emmc_wear', 'warning', 'eMMC reports a wear warning',
                'The eMMC lifetime or reserved-block estimate is near its limit. Plan a replacement and retain the diagnostic report.',
                'life_time='.($facts['life_time'] ?? 'not reported').'; pre_eol_info='.($facts['pre_eol_info'] ?? 'not reported'));
        if ($onlyGroup !== null)
            $issues = array_values(array_filter($issues, fn ($issue) => $issue['group'] === $onlyGroup));
        $severity = collect($issues)->contains('severity', 'error') ? 'error' : ($issues ? 'warning' : 'info');
        return ['title' => $issues[0]['title'] ?? ($onlyGroup === 'storage' ? ($facts['block'] === 'present' ? 'Storage detected' : 'Storage status not reported') : 'No recognized warnings'),
            'severity' => $severity, 'issues' => $issues, 'checks' => self::checks($facts, $issues)];
    }

    protected static function reading(array $facts, string $key): ?float
    {
        return isset($facts[$key]) && $facts[$key] !== '' && is_numeric($facts[$key]) ? (float) $facts[$key] : null;
    }

    public static function temperature(float $millidegrees): string
    {
        return number_format($millidegrees / 1000, 1).' °C';
    }

    protected static function memory(float $kb): string
    {
        return $kb >= 1048576 ? number_format($kb / 1048576, 2).' GiB' : number_format($kb / 1024, 0).' MiB';
    }

    public static function bootMode(?string $value): string
    {
        return ['00000001' => 'SD / eMMC', '00000002' => 'Network (PXE)', '00000003' => 'USB device (rpiboot)',
            '00000004' => 'USB mass storage', '00000006' => 'NVMe'][$value ?? ''] ?? 'not reported';
    }

    protected static function checks(array $facts, array $issues): array
    {
        $temperature = self::reading($facts, 'temperature_mc');
        $total = self::reading($facts, 'mem_total_kb');
        $available = self::reading($facts, 'mem_available_kb');
        $power = ($temperature !== null ? self::temperature($temperature) : 'temperature not reported').' · '
            .(($facts['throttled'] ?? 'unknown') === 'unknown' ? 'power flags not reported' : 'flags '.$facts['throttled']);
        $memory = $total !== null ? self::memory($total).' total · available RAM not reported' : 'not reported';
        if ($available !== null && $total !== null && $available <= $total)
            $memory = self::memory($available).' available / '.self::memory($total).' total';
        $ethernet = ($facts['ethernet'] ?? '') === 'missing' ? 'eth0 not detected' : 'link not reported';
        if (($facts['eth_carrier'] ?? '') === '1')
            $ethernet = 'Link up'.(self::reading($facts, 'eth_speed_mbps') !== null ? ' · '.$facts['eth_speed_mbps'].' Mbps' : '')
                .(in_array($facts['eth_duplex'] ?? '', ['full', 'half'], true) ? ' · '.$facts['eth_duplex'].' duplex' : '');
        elseif (($facts['eth_carrier'] ?? '') === '0')
            $ethernet = 'Link down';
        $values = [
            'storage' => ['Storage', $facts['block'] === 'present' ? 'Block device detected' : ($facts['block'] === 'missing' ? 'Block device missing' : 'not reported')],
            'power' => ['Power & temperature', $power],
            'memory' => ['Memory', $memory],
            'network' => ['Ethernet · eth0', $ethernet],
            'boot' => ['Boot & system', self::bootMode($facts['boot_mode'] ?? null).' · kernel '.($facts['kernel'] ?? 'not reported')],
        ];
        $checks = [];
        foreach ($values as $group => [$label, $value])
        {
            $warnings = array_values(array_filter($issues, fn ($issue) => $issue['group'] === $group));
            $severity = collect($warnings)->contains('severity', 'error') ? 'error' : ($warnings ? 'warning' : 'info');
            $checks[] = compact('group', 'label', 'value', 'severity');
        }
        return $checks;
    }

    public static function text(Cm $cm): string
    {
        return self::format($cm->storage_diagnostics, $cm->serial, $cm->storage_diagnostics_at?->toIso8601String(),
            $cm->eepromVersionBefore(), $cm->eepromVersionAfter());
    }

    public static function format(array $report, string $serial, ?string $received, ?string $before = null, ?string $after = null): string
    {
        $text = "cmprovision module diagnostics\nSerial: ".$serial."\nReceived: ".$received."\n";
        $text .= 'Source: '.($report['source'] ?? 'ethernet')."\nContext: ".$report['context']."\n";
        if (isset($report['collected_at'])) $text .= 'Collected (approx.): '.$report['collected_at']."\n";
        $text .= 'EEPROM before: '.($before ?? 'not reported')."\nEEPROM after: ".($after ?? 'not reported')."\n";
        foreach ($report['facts'] as $key => $value)
            $text .= $key.'='.$value."\n";
        foreach ($report['sections'] as $key => $value)
            $text .= "\n[".$key."]\n".$value."\n";
        return $text;
    }
}
