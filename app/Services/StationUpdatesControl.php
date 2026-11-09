<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/** Read-only projection for rendering; fixed root broker for requested operations. */
class StationUpdatesControl
{
    const HELPER = '/usr/lib/cmprovision/station-updates';
    const STATUS = '/var/lib/cmprovision-updates/public/status.json';
    const PENDING = '/run/cmprovision-updates/pending.json';

    public function available(): bool { return is_file(self::HELPER); }
    public function status(): array
    {
        $info = @lstat(self::STATUS);
        if (!$info || ($info['mode'] & 0170000) !== 0100000 || $info['uid'] !== 0 || ($info['mode'] & 0022) || $info['size'] > 4194304) return [];
        $value = json_decode((string) @file_get_contents(self::STATUS, false, null, 0, 4194305), true);
        return is_array($value) && ($value['schema'] ?? null) === 1 ? $value : [];
    }
    public function pending(): bool
    {
        if (!$this->available()) return false;
        if (file_exists(self::PENDING) || is_link(self::PENDING)) return true;
        $status = $this->status();
        return !$status || !empty($status['pending']); // Unknown maintenance state fails closed.
    }
    public function request(array $request): array
    {
        if (!$this->available()) throw new \RuntimeException('Install the updated cmprovision4 package to enable updates.');
        $process = new Process(['sudo', '-n', self::HELPER]);
        $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
        $process->setTimeout(12);
        try { $process->run(); }
        catch (\Throwable $error) { throw new \RuntimeException('The updater did not answer. Reload its status before retrying.'); }
        $response = json_decode($process->getOutput(), true);
        if (!$process->isSuccessful() || ($response['ok'] ?? false) !== true || !is_array($response['result'] ?? null)) {
            throw new \RuntimeException(is_string($response['error'] ?? null) ? $response['error'] : 'The updater is unavailable. Check its service journal.');
        }
        return $response['result'];
    }
}
