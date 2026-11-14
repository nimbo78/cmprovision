<?php

namespace App\Services\Sensor;

use App\Models\Cm;
use RuntimeException;

/** Compatibility is evidence from the installed system, never inferred from OURS or an OUI. */
class TargetInspection
{
    public function validate(array $identity, string $mac, ?Cm $cm): void
    {
        if ($cm) {
            if (strtolower($cm->serial) !== $identity['serial'])
                throw new RuntimeException('A different device answered at this address (serial '.$identity['serial'].').');
            if (($cm->provisioning_storage && $cm->provisioning_storage !== $identity['storage'])
                || ($cm->cid && strtolower($cm->cid) !== $identity['cid'])
                || ($cm->storage && (int) $cm->storage !== (int) $identity['sectors'] * 512))
                throw new RuntimeException('The sensor disk does not match its last provisioning record.');
        }
        if ($identity['agent'] === '1') {
            if (!$cm) throw new RuntimeException('Extended hardware inspection is required for a first-time module. Enter an account with sudo access.');
            return; // Existing serial/CID/size-bound v1 installations remain supported.
        }
        if ($identity['provisioner'] !== '0')
            throw new RuntimeException('This device has a cmprovision installation. Use the separate station reimage procedure with a backup.');
        if (!preg_match('/^Raspberry Pi (?:Compute Module 4 |4 Model B )Rev [0-9.]+$/D', $identity['model']))
            throw new RuntimeException('Unsupported model: '.$identity['model'].'. Only CM4 and Raspberry Pi 4 are supported.');
        if ($identity['mac'] !== $mac)
            throw new RuntimeException('The inspected Ethernet MAC does not match the selected network device.');
        if (!self::bootReady($identity['boot_order']))
            throw new RuntimeException('Network boot could not be confirmed. Use USB prep to configure network boot before erasing the installed system.');
    }

    public static function bootReady(string $order): bool
    {
        if (!preg_match('/^(?:0x)?([a-f0-9]{1,8})$/Di', $order, $m)) return false;
        foreach (str_split(strrev(strtolower($m[1]))) as $mode) {
            if ($mode === '2') return true;
            // The checked root mmc disk will be erased; other modes can stop/restart or choose another boot device.
            if ($mode !== '1') return false;
        }
        return false;
    }
}
