<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class Fpms
{
    const CONFIG = '/etc/default/cmprovision-fpms.json';
    const STATUS = '/run/cmprovision-fpms/status.json';
    const DEFAULTS = ['enabled' => false, 'open_on_start' => false];

    public function settings(): array
    {
        $data = json_decode((string) @file_get_contents(self::CONFIG), true);
        if (!is_array($data) || count($data) !== 2 || !is_bool($data['enabled'] ?? null) || !is_bool($data['open_on_start'] ?? null))
            return self::DEFAULTS;
        return array_intersect_key($data, self::DEFAULTS);
    }

    public function status(): array
    {
        $data = json_decode((string) @file_get_contents(self::STATUS), true);
        if (!is_array($data) || !is_numeric($data['checked_at'] ?? null) || abs(time() - $data['checked_at']) > 30)
            return ['state' => 'unavailable'];
        return ['state' => in_array($data['state'] ?? '', ['off', 'running', 'not installed', 'incompatible', 'no hardware', 'waiting for FPMS', 'stale', 'error'], true)
            ? $data['state'] : 'unavailable'];
    }

    public function save(array $settings): void
    {
        $process = new Process(['sudo', '-n', '/usr/lib/cmprovision/fpms-control']);
        $process->setInput(json_encode($settings, JSON_THROW_ON_ERROR));
        $process->setTimeout(5);
        $process->run();
        if (!$process->isSuccessful())
            throw new \RuntimeException('Could not save FPMS settings. Check the cmprovision package installation.');
    }
}
