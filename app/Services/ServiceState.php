<?php

namespace App\Services;

/** Whether a systemd unit of the server runs; the Settings page asks it, the tests put a fake in its place */
class ServiceState
{
    public function status(string $unit): string
    {
        if ($this->isActive($unit))
            return 'running';

        // Network roles deliberately mask these optional boot servers. A failed unit still needs attention.
        if (in_array($unit, ['cmprovision-dnsmasq', 'cmprovision-rpiboot'], true)) {
            $properties = $this->unitProperties($unit);
            if (in_array('ActiveState=inactive', $properties, true)
                && (in_array('UnitFileState=masked', $properties, true) || in_array('UnitFileState=masked-runtime', $properties, true)))
                return 'disabled';
        }

        return 'stopped';
    }

    protected function unitProperties(string $unit): array
    {
        $output = [];
        exec('/bin/systemctl show --property=ActiveState,UnitFileState '.escapeshellarg($unit).' 2>/dev/null', $output, $code);

        return $code === 0 ? $output : [];
    }

    public function isActive(string $unit): bool
    {
        $output = $retcode = '';
        exec('/bin/systemctl is-active '.escapeshellarg($unit), $output, $retcode);

        return $retcode == 0;
    }
}
