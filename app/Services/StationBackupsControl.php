<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/** Fixed privileged broker and sanitized status only; callers cannot supply a local path. */
class StationBackupsControl
{
    const HELPER = '/usr/lib/cmprovision/station-backups';
    const STATUS = '/var/lib/cmprovision-backups/public/status.json';
    const PENDING = '/run/cmprovision-backups/pending.json';
    const MUTATIONS = '/run/cmprovision-backups/mutations.lock';

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
        return !$status || !empty($status['pending']);
    }

    public function request(#[\SensitiveParameter] array $request): array
    {
        if (!$this->available()) throw new \RuntimeException('Install the updated cmprovision4 package to enable full station backups.');
        $input = json_encode($request, JSON_THROW_ON_ERROR);
        if (strlen($input) > 196608) throw new \RuntimeException('The backup request exceeds its size limit.');
        $process = new Process(['sudo', '-n', self::HELPER]);
        $process->setInput($input);
        $process->setTimeout(12);
        try { $process->run(); }
        catch (\Throwable $error) { throw new \RuntimeException('The backup controller did not answer. Reload status before trying again.'); }
        $raw = $process->getOutput();
        if (strlen($raw) > 4194304) throw new \RuntimeException('The backup response exceeds its size limit.');
        $response = json_decode($raw, true);
        if (!$process->isSuccessful() || ($response['ok'] ?? false) !== true || !is_array($response['result'] ?? null)) {
            throw new \RuntimeException(is_string($response['error'] ?? null) ? $response['error'] : 'The backup controller is unavailable. Check its service journal.');
        }
        return $response['result'];
    }

    /** Prime the authenticated format header before sending HTTP headers; never buffer the archive. */
    public function download(string $id): array
    {
        if (!preg_match('/\A[0-9a-f]{32}\z/D', $id)) throw new \RuntimeException('Invalid backup identifier.');
        $process = $this->downloadProcess($id);
        $process->setTimeout(7200);
        $process->setIdleTimeout(60);
        $process->start();
        $chunks = (function () use ($process) {
            try {
                foreach ($process->getIterator() as $type => $chunk) if ($type === Process::OUT) yield $chunk;
                if (!$process->isSuccessful()) throw new \RuntimeException('Backup download interrupted. Download the prepared copy again.');
            } finally { if ($process->isRunning()) $process->stop(1); }
        })();
        $first = '';
        while ($chunks->valid() && strlen($first) < 13) { $first .= $chunks->current(); $chunks->next(); }
        if (!str_starts_with($first, "CMPROVISION\x01\x03")) throw new \RuntimeException('The prepared backup is unavailable or expired. Create a fresh copy.');
        $remaining = (function () use ($chunks) {
            while ($chunks->valid()) { yield $chunks->current(); $chunks->next(); }
        })();
        return [$remaining, $first];
    }

    protected function downloadProcess(string $id): Process
    {
        $process = new Process(['sudo', '-n', self::HELPER]);
        $process->setInput(json_encode(['action' => 'download', 'id' => $id], JSON_THROW_ON_ERROR));
        return $process;
    }
}
