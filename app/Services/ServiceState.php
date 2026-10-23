<?php

namespace App\Services;

/** Whether a systemd unit of the server runs; the Settings page asks it, the tests put a fake in its place */
class ServiceState
{
    public function isActive(string $unit): bool
    {
        $output = $retcode = '';
        exec('/bin/systemctl is-active '.escapeshellarg($unit), $output, $retcode);

        return $retcode == 0;
    }
}
