<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class Wallboard
{
    const CONFIG = '/etc/default/cmprovision-wallboard.json';
    const STATUS = '/run/cmprovision-wallboard/status.json';
    const DEFAULTS = ['mode' => 'off', 'palette' => 'warm', 'view' => 'auto', 'rotation' => 12];

    public function settings(): array
    {
        $data = json_decode((string) @file_get_contents(self::CONFIG), true);
        if (is_array($data) && isset($data['palette']) && is_string($data['palette']))
            $data['palette'] = ['phosphor' => 'warm', 'mono' => 'neutral'][$data['palette']] ?? $data['palette'];
        return is_array($data) ? array_intersect_key($data, self::DEFAULTS) + self::DEFAULTS : self::DEFAULTS;
    }

    public function status(): array
    {
        $data = json_decode((string) @file_get_contents(self::STATUS), true);
        return is_array($data) ? $data : ['state' => 'not installed', 'connected' => false];
    }

    public function save(array $settings): void
    {
        $process = new Process(['sudo', '-n', '/usr/lib/cmprovision/wallboard-control']);
        $process->setInput(json_encode($settings, JSON_THROW_ON_ERROR));
        $process->setTimeout(5);
        $process->run();
        if (!$process->isSuccessful())
            throw new \RuntimeException('Could not save HDMI settings. Check that the wallboard package is installed.');
    }
}
