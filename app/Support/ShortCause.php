<?php

namespace App\Support;

use App\Models\Cm;
use Illuminate\Support\Str;

/* What went wrong with a module in two short lines at most, for the front panel's lists ("Download cut (curl 18)"), with
   the full reason and the exit codes for its card. Made only from what the server keeps (the failure reason, the timeline,
   the EEPROM result and its log): a failure it does not know is told by its step, never guessed. */
class ShortCause
{
    /* keeps a code with its word when the panel wraps the line */
    const NBSP = "\u{00A0}";

    /* The diagnoses of a failed image write (ScriptExecuteController::WRITE_FAILURE_HINTS), by their first words */
    const WRITES = [
        'the storage was written after' => 'Storage overwritten',
        'the image file on the server is shorter' => 'Image file truncated',
        'the first MiB of the pieces copy' => 'Pieces copy damaged',
        'the provisioning server sent nothing' => 'No data from server 10 min',
        'the server answered with an error' => 'Image gone from server',
        'the server would not send the rest' => 'Server refused resume',
        'the connection to the provisioning server was closed' => 'Download cut',
        'the module could not reach' => 'Server unreachable',
        'the decompressor stopped' => 'Decompressor stopped',
        'the provisioning server name' => 'Server name unknown',
        'the downloaded data is not a valid' => 'Not a valid image',
        'the compressed image is truncated' => 'Image data corrupted',
        'the storage device could not be written' => 'Storage not writable',
        'the storage device reported an I/O error' => 'eMMC I/O error',
    ];

    /* What the server answers when it refuses a module at the start (FailureAdvice::REFUSALS) */
    const REFUSALS = [
        'no active project' => 'No active project',
        'uncompressed SHA256 not computed yet' => 'Image checksum not ready',
        'not dividable by sector size' => 'Not a disk image',
        'Missing eMMC/SD card' => 'No eMMC or SD card',
        'Image does not fit in storage' => 'Image larger than eMMC',
    ];

    /* A failed EEPROM flash by the output of flashrom and the flash script (FailureAdvice::eepromAdvice) */
    const EEPROM = [
        '/No EEPROM\/flash device found/' => 'EEPROM: no SPI chip',
        '/pieeprom\.bin: FAILED|did NOT match/' => 'EEPROM image mismatch',
        '/curl: \(\d+\)/' => 'EEPROM download failed',
    ];

    const LATE = [
        'The new system did not start' => 'New OS did not boot',
        Cm::OS_NO_REPORT => 'New OS did not report',
        'OS check failed' => 'OS check failed',
        'Provisioning server error' => 'Server error',
    ];

    /* ['cause' => short text, 'reason' => the full reason or null, 'code' => "curl 18, exit 1" or null] for a failed or
       silent module, null for one that works normally or finished */
    public static function for(Cm $cm): ?array
    {
        if ($cm->phase !== 'failed')
        {
            $silent = $cm->silentFor();
            return $silent === null ? null : ['cause' => 'No report for '.intdiv($silent, 60).' min', 'reason' => null, 'code' => null];
        }

        $detail = (string) $cm->phase_detail;
        $exit = preg_match('/Return code (\d+)/', $detail, $m) ? (int) $m[1] : null;
        $reason = $codes = null;

        if (Str::startsWith($detail, 'Error during dd'))
        {
            $cause = self::stepFailed($cm) ?? 'Writing image failed';
            if (preg_match('/Diagnosis: (.*?)\.?(?: Codes: ([^.]*)\.)?$/s', $detail, $m))
            {
                $reason = Str::ucfirst($m[1]).'.';
                foreach (self::WRITES as $start => $short)
                    if (Str::startsWith($m[1], $start))
                    {
                        $cause = $short;
                        break;
                    }
            }
            $codes = preg_match('/ Codes: ([^.]*)\.$/', $detail, $m) ? $m[1] : null;
            if ($codes)
                $cause .= ' ('.str_replace(' ', self::NBSP, $codes).')';
        }
        elseif (Str::startsWith($detail, 'Error during preinstall'))
            $cause = $cm->eeprom_result === 'failed' ? self::eeprom((string) $cm->pre_script_output)
                : 'Pre-install script failed (code '.($exit ?? '?').')';
        elseif (Str::startsWith($detail, 'Error during postinstall'))
        {
            $step = self::lastStep($cm);
            $cause = ($step['phase'] ?? null) === 'verify' ? 'SHA256 mismatch after write'
                : (($step['detail'] ?? null) === 'Installing the OS check' ? 'OS check install failed'
                    : 'Post-install script failed (code '.($exit ?? '?').')');
        }
        else
            $cause = self::known($detail) ?? (Str::startsWith($detail, 'Error during storage') ? 'Storage check failed'
                : (self::stepFailed($cm) ?? 'Failed'));

        $code = implode(', ', array_filter([$codes, $exit !== null ? "exit $exit" : null]));
        return ['cause' => $cause, 'reason' => $reason, 'code' => $code !== '' ? $code : null];
    }

    /* The step to show by the percent: where a failed module stopped ("Writing image"), the current step of the others */
    public static function step(Cm $cm): string
    {
        if ($cm->phase !== 'failed')
            return $cm->phaseLabel();
        $step = self::lastStep($cm);
        return $step ? (Cm::PHASE_LABELS[$step['phase']] ?? $step['phase']) : 'Failed';
    }

    /* Every short text the panel may show, for the test that they fit its lines */
    public static function catalogue(): array
    {
        return [...array_values(self::WRITES), ...array_values(self::REFUSALS), ...array_values(self::EEPROM),
            ...array_values(self::LATE), 'EEPROM flash failed', 'Pre-install script failed (code 255)',
            'Post-install script failed (code 255)', 'SHA256 mismatch after write', 'OS check install failed',
            'Storage check failed', 'No report for 999 min', 'Writing image failed', 'Checking the OS failed', 'Failed'];
    }

    protected static function known(string $detail): ?string
    {
        foreach (self::REFUSALS + self::LATE as $text => $short)
            if (Str::contains($detail, $text))
                return $short;
        return null;
    }

    protected static function eeprom(string $log): string
    {
        foreach (self::EEPROM as $pattern => $short)
            if (preg_match($pattern, $log))
                return $short;
        return 'EEPROM flash failed';
    }

    /* The timeline entry the module was in when it failed (the one before the final 'failed') */
    protected static function lastStep(Cm $cm): ?array
    {
        $entries = array_values($cm->timeline ?: []);
        $count = count($entries);
        return $count >= 2 && $entries[$count - 1]['phase'] === 'failed' ? $entries[$count - 2] : null;
    }

    protected static function stepFailed(Cm $cm): ?string
    {
        $step = self::lastStep($cm);
        return $step ? (Cm::PHASE_LABELS[$step['phase']] ?? $step['phase']).' failed' : null;
    }
}
