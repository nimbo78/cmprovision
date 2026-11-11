<?php

namespace App\Http\Middleware;

use App\Services\StationBackupsControl;
use Closure;

/** Drain admitted mutations before a snapshot while keeping Backup status/cancel available. */
class StationBackupMaintenance
{
    public function __construct(private StationBackupsControl $control,
        private string $path = StationBackupsControl::MUTATIONS, private bool $verifyRoot = true) {}

    private function backupPanel($request): bool
    {
        if (!$request->is('livewire/update') || !$request->isMethod('POST')) return false;
        $components = $request->input('components');
        if (!is_array($components) || !$components || count($components) > 8) return false;
        foreach ($components as $component) {
            $snapshot = $component['snapshot'] ?? null;
            if (!is_string($snapshot) || strlen($snapshot) > 65536) return false;
            $value = json_decode($snapshot, true, 32);
            $name = $value['memo']['name'] ?? null;
            // Livewire verifies each signed snapshot; only the guarded Backup component may mutate.
            if ($name === 'station-backups-panel') continue;
            if (!in_array($name, ['wallboard-settings', 'fpms-settings'], true) || !empty($component['updates'])) return false;
            foreach ($component['calls'] ?? [] as $call) if (($call['method'] ?? null) !== '$refresh' || !empty($call['params'])) return false;
        }
        return true;
    }

    private function busy()
    {
        return response('A station backup is being created. Open Settings → System → Backup for progress or cancellation.', 503)
            ->header('Retry-After', '5')->header('Content-Type', 'text/plain')
            ->header('X-Cmprovision-Maintenance', 'station-backup');
    }

    public function handle($request, Closure $next)
    {
        if (!$this->control->available()) return $next($request);
        $panel = $this->backupPanel($request);
        $auth = $request->is('login', 'logout', 'user/confirm-password', 'two-factor-challenge');
        $read = ($request->isMethod('GET') || $request->isMethod('HEAD'))
            && $request->is('settings', 'settings/backups/status', 'settings/backups/confirm', 'settings/backups/*/download', 'up', 'updates/health');
        if ($this->control->pending()) return ($panel || $auth || $read) ? $next($request) : $this->busy();
        if ($panel || $auth || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) return $next($request);
        if (is_link($this->path) || !($handle = @fopen($this->path, 'r'))) return $this->busy();
        try {
            $info = fstat($handle);
            if (!$info || ($info['mode'] & 0170000) !== 0100000 ||
                ($this->verifyRoot && ($info['uid'] !== 0 || ($info['mode'] & 0022))) || !flock($handle, LOCK_SH | LOCK_NB)) return $this->busy();
            try { return $this->control->pending() ? $this->busy() : $next($request); }
            finally { flock($handle, LOCK_UN); }
        } finally { fclose($handle); }
    }
}
