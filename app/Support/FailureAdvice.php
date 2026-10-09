<?php

namespace App\Support;

use App\Models\Cm;
use Illuminate\Support\Str;

/* Where a failed or silent module stopped, and what the operator can do about it. Works from what the
   server already keeps: the failure reason (phase_detail), the timeline, the EEPROM result and the logs. */
class FailureAdvice
{
    const RESTART = 'Restart the module (power cycle) to try again.';

    /* What the server answers when it refuses a module at the start => advice */
    const REFUSALS = [
        'no active project' => 'Activate a project on the Projects page, then restart the module.',
        'uncompressed SHA256 not computed yet' => 'The checksum of the image is still being computed. Wait until the Images page shows its SHA256, then restart the module.',
        'not dividable by sector size' => 'The image is not a raw disk image: its size is not a multiple of 512 bytes. Upload a .img compressed with gzip, xz or bzip2.',
        'Missing eMMC/SD card' => 'The module reported no eMMC and no SD card. A CM4 Lite needs an SD card in its carrier board; on a module with eMMC the eMMC may be faulty.',
        'Image does not fit in storage' => 'The image is larger than the storage of the module. Use a smaller image or a module with more eMMC.',
    ];

    /* ['step' => the step that failed, or null when the server refused the module at the start,
        'advice' => what to do], or null for a module that works normally or finished */
    public static function for(Cm $cm)
    {
        if ($cm->phase !== 'failed')
        {
            $silent = $cm->silentFor();
            if ($silent === null)
                return null;
            return [
                'step' => $cm->phaseLabel(),
                'advice' => 'The module has not reported for '.intdiv($silent, 60).' min. Check its power, the network cable '
                           .'and the switch port, then restart it; provisioning starts over.',
            ];
        }

        $reason = (string) $cm->phase_detail;
        $failedStep = self::stepBeforeFailure($cm);
        $step = $failedStep ? self::label($failedStep) : null;
        $code = preg_match('/Return code (\d+)/', $reason, $m) ? $m[1] : '?';

        if (Str::startsWith($reason, 'Provisioning server error'))
            return ['step' => $step, 'advice' => 'The provisioning server ran into an error; storage/logs/laravel.log on the server has the details. '
                                                 .'Once it is fixed, restart the module.'];

        if (Str::startsWith($reason, 'Error during preinstall'))
            return ['step' => $step, 'advice' => $cm->eeprom_result === 'failed'
                ? self::eepromAdvice((string) $cm->pre_script_output)
                : self::scriptAdvice('Pre-install', $failedStep, $code).' Fix the script, then restart the module.'];

        if (Str::startsWith($reason, 'Error during dd'))
        {
            $what = preg_match('/Diagnosis: (.*?)\.?$/s', $reason, $m) ? ': '.$m[1] : '; the log entry below has the output of curl, the decompressor and dd';
            return ['step' => $step, 'advice' => 'The image write stopped'.$what.'. The partition table was cleared, so the module '
                                                 .'starts from the network again. '.self::RESTART];
        }

        if (Str::startsWith($reason, 'Error during postinstall'))
        {
            if ($failedStep && $failedStep['phase'] === 'verify')
                return ['step' => $step, 'advice' => 'The data read back from the storage does not match the image (SHA256). '
                                                     .self::RESTART.' If it fails again, the eMMC is probably failing.'];
            return ['step' => $step, 'advice' => self::scriptAdvice('Post-install', $failedStep, $code)
                                                 .' The image is written. Fix the script, then restart the module to provision it again.'];
        }

        foreach (self::REFUSALS as $pattern => $advice)
        {
            if (Str::contains($reason, $pattern))
                return ['step' => $step, 'advice' => $advice];
        }

        return ['step' => $step, 'advice' => 'The log entries below have the details. '.self::RESTART];
    }

    protected static function eepromAdvice($log)
    {
        if (Str::contains($log, 'No EEPROM/flash device found'))
            return 'flashrom found no SPI flash chip on the module, so the EEPROM was not changed. Check that the module '
                  .'is a CM4 (a CM3 has no EEPROM) and that the carrier board does not hold the EEPROM write-protected. '.self::RESTART;
        if (preg_match('/pieeprom\.bin: FAILED|did NOT match/', $log))
            return 'pieeprom.bin on the server does not match the active project. Activate the project again on the Projects '
                  .'page (that rebuilds the file), then restart the module.';
        if (preg_match('/curl: \(\d+\)/', $log))
            return 'The module could not download the EEPROM image from the provisioning server. Check nginx on the server '
                  .'and the network, then restart the module.';
        return 'Flashing the EEPROM failed; the pre-install log has the output of flashrom. If the module no longer starts, '
              .'recover the EEPROM with rpiboot and the recovery image. '.self::RESTART;
    }

    protected static function scriptAdvice($kind, $step, $code)
    {
        $name = $step && $step['detail'] !== null ? "'".$step['detail']."'" : 'A script';
        return $kind.' script '.$name.' exited with code '.$code.'; its output is in the '.Str::lower($kind).' log.';
    }

    /* The timeline entry the module was in when it failed (the one before the final 'failed') */
    protected static function stepBeforeFailure(Cm $cm)
    {
        $entries = array_values($cm->timeline ?: []);
        $count = count($entries);
        if ($count >= 2 && $entries[$count - 1]['phase'] === 'failed')
            return $entries[$count - 2];
        return null;
    }

    protected static function label(array $entry)
    {
        $label = Cm::PHASE_LABELS[$entry['phase']] ?? $entry['phase'];
        return $entry['detail'] !== null ? $label.': '.$entry['detail'] : $label;
    }
}
