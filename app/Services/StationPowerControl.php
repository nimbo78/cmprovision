<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/** Fixed privileged executor; the application alone owns confirmations and reads its DB. */
class StationPowerControl
{
    const HELPER = '/usr/lib/cmprovision/station-power';
    const DIRECTORY = '/run/cmprovision-power';
    const BOOT_ID = '/proc/sys/kernel/random/boot_id';

    public function available(): bool { return is_file(self::HELPER) && $this->bootId() !== ''; }
    public function bootId(): string
    {
        $id = trim((string) @file_get_contents(self::BOOT_ID, false, null, 0, 128));
        return preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $id) ? $id : '';
    }
    public function pending(): ?array
    {
        $directory = @lstat(self::DIRECTORY);
        if (!$directory || ($directory['mode'] & 0170000) !== 0040000 || $directory['uid'] !== 0 || ($directory['mode'] & 0022)) return null;
        $path = self::DIRECTORY.'/pending.json';
        $info = @lstat($path);
        if (!$info || ($info['mode'] & 0170000) !== 0100000 || $info['uid'] !== 0 || ($info['mode'] & 0022) || $info['size'] > 4096) return null;
        $state = json_decode((string) @file_get_contents($path, false, null, 0, 4097), true);
        if (!is_array($state) || !in_array($state['action'] ?? null, ['reboot', 'poweroff'], true)
            || ($state['boot_id'] ?? '') !== $this->bootId() || !is_int($state['scheduled_at'] ?? null)
            || time() - $state['scheduled_at'] < 0 || time() - $state['scheduled_at'] >= 120) return null;
        return array_intersect_key($state, array_flip(['action', 'boot_id', 'scheduled_at', 'delay', 'confirmed']));
    }
    public function schedule(string $action): array
    {
        if (!in_array($action, ['reboot', 'poweroff'], true)) throw new \InvalidArgumentException('Unknown power action.');
        $process = new Process(['sudo', '-n', self::HELPER]);
        $process->setInput(json_encode(['action' => $action], JSON_THROW_ON_ERROR));
        $process->setTimeout(8);
        try { $process->run(); }
        catch (\Throwable $error) { throw new \RuntimeException('The power controller did not answer. Check the station status before retrying.'); }
        $response = json_decode($process->getOutput(), true);
        $state = $response['result'] ?? null;
        if (!$process->isSuccessful() || ($response['ok'] ?? false) !== true || !is_array($state)
            || ($state['action'] ?? null) !== $action || ($state['boot_id'] ?? null) !== $this->bootId()
            || !is_int($state['scheduled_at'] ?? null) || ($state['delay'] ?? null) !== 5) {
            throw new \RuntimeException('Could not schedule the power action. Check the station status and package installation.');
        }
        return array_intersect_key($state, array_flip(['action', 'boot_id', 'scheduled_at', 'delay']));
    }
}
