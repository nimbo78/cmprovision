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
        $data = $this->readStatus();
        if (!is_array($data) || !is_numeric($data['checked_at'] ?? null) || abs(time() - $data['checked_at']) > 30)
            return ['state' => 'unavailable'];
        $status = ['state' => in_array($data['state'] ?? '', ['off', 'running', 'not installed', 'incompatible', 'no hardware', 'waiting for FPMS', 'stale', 'error'], true)
            ? $data['state'] : 'unavailable'];
        $display = $data['display'] ?? null;
        if (!in_array($status['state'], ['running', 'stale', 'error'], true) || !is_array($display))
            return $status;
        if (($display['profile'] ?? null) === 'hyperpixel-4-touch' && ($display['id'] ?? null) === 'gt911')
            $status['display'] = ['profile' => 'hyperpixel-4-touch', 'id' => 'gt911'];
        elseif (($display['profile'] ?? null) === 'waveshare-1.44' && is_string($display['id'] ?? null)
            && preg_match('/\A7c[89a-f][0-9a-f]{3}\z/D', $display['id']))
            $status['display'] = ['profile' => 'waveshare-1.44', 'id' => $display['id']];
        return $status;
    }

    protected function readStatus(): mixed
    {
        return json_decode((string) @file_get_contents(self::STATUS), true);
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
