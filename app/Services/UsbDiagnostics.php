<?php

namespace App\Services;

use App\Models\Cm;
use App\Models\UsbDiagnosticReport;
use App\Support\ModuleDiagnostics;
use InvalidArgumentException;

/* Called locally by the unprivileged USB receiver, never through a network endpoint. */
class UsbDiagnostics
{
    const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function status(array $m): array
    {
        $this->validate($m);
        $cm = Cm::where('serial', $m['serial'])->first();
        $current = $cm && $m['token'] !== '-' && $cm->diagnostics_token && hash_equals($cm->diagnostics_token, $m['token']);
        if ($cm && $m['token'] !== '-' && !$current)
            return ['action' => 'skip', 'reason' => 'obsolete run'];
        if ($current && $cm->diagnostics_requested_at && $cm->os_checked_at === null && in_array($cm->phase, ['done', 'failed'], true)
            && $cm->diagnostics_requested_at->lte(now()->subSeconds(ModuleDiagnostics::REQUEST_SECONDS))
            && $cm->diagnostics_requested_at->gt(now()->subSeconds(ModuleDiagnostics::REQUEST_EXPIRES_SECONDS)))
        {
            $id = substr(hash('sha256', $cm->diagnostics_requested_at->toISOString().$m['boot_id']), 0, 32);
            $id = substr($id, 0, 8).'-'.substr($id, 8, 4).'-'.substr($id, 12, 4).'-'.substr($id, 16, 4).'-'.substr($id, 20);
            return ['action' => 'collect', 'event_id' => $id, 'context' => 'manual'];
        }
        if ($m['context'] === 'idle')
            return ['action' => 'skip', 'reason' => 'no failed Ethernet delivery'];
        if ($m['age'] < ModuleDiagnostics::REQUEST_SECONDS)
            return ['action' => 'wait'];
        if (UsbDiagnosticReport::where('serial', $m['serial'])->where('event_id', $m['event_id'])->exists())
            return ['action' => 'skip', 'reason' => 'already collected'];
        $report = $current ? $cm->storage_diagnostics : null;
        if ($report
            && (($report['facts']['event_id'] ?? '') === $m['event_id']
                || (($report['facts']['boot_id'] ?? '') === $m['boot_id']
                    && (int) ($report['facts']['uptime_seconds'] ?? -1) > $m['collected'])))
            return ['action' => 'skip', 'reason' => 'this or a newer report already arrived'];
        return ['action' => 'collect', 'event_id' => $m['event_id'], 'context' => $m['context']];
    }

    public function accept(array $input): array
    {
        $m = $input['status'] ?? [];
        $this->validate($m);
        // Telemetry must not take the non-blocking operation lock used by module starts/re-provisioning.
        // SQLite serializes this short transaction; an error rolls back the observation, never the run.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($input, $m) {
            $decision = $this->status($m); // Recheck after transfer; an Ethernet response or a new run may have arrived.
            if ($decision['action'] !== 'collect')
                return ['action' => 'ignored'];
            $bytes = isset($input['report']) && is_string($input['report']) ? base64_decode($input['report'], true) : false;
            if ($bytes === false || strlen($bytes) > ModuleDiagnostics::MAX_BYTES)
                throw new InvalidArgumentException('Invalid USB report size/encoding.');
            $cm = Cm::where('serial', $m['serial'])->first();
            $bound = $cm && $m['token'] !== '-' && $cm->diagnostics_token && hash_equals($cm->diagnostics_token, $m['token']);
            $report = ModuleDiagnostics::parse($bytes, $bound ? $cm : null);
            $facts = $report['facts'];
            if ($facts['serial'] !== $m['serial'] || $facts['boot_id'] !== $m['boot_id']
                || $facts['event_id'] !== $decision['event_id'] || $report['context'] !== $decision['context']
                || ($input['token'] ?? null) !== $m['token'])
                throw new InvalidArgumentException('USB report identity changed during collection.');
            $path = $input['usb_path'] ?? '';
            if (!is_string($path) || !preg_match('#^[a-zA-Z0-9/_.:-]{1,255}$#D', $path))
                throw new InvalidArgumentException('Invalid USB path.');
            $report['source'] = 'usb';
            $age = is_numeric($facts['uptime_seconds'] ?? '') ? max(0, $m['collected'] + $m['age'] - (int) $facts['uptime_seconds']) : 0;
            $report['collected_at'] = now()->subSeconds(min($age, 86400))->toIso8601String();
            $row = UsbDiagnosticReport::firstOrCreate(['serial' => $m['serial'], 'event_id' => $facts['event_id']], [
                'boot_id' => $facts['boot_id'], 'mac' => $facts['mac'] ?: null, 'usb_path' => $path,
                'report' => $report, 'received_at' => now(),
            ]);
            $cm = Cm::where('serial', $m['serial'])->first();
            if ($cm && $m['token'] !== '-' && $cm->diagnostics_token && hash_equals($cm->diagnostics_token, $m['token'])
                && $facts['target'] === ($cm->provisioning_storage ?: '/dev/mmcblk0'))
            {
                if ($report['context'] === 'failure' && $row->wasRecentlyCreated)
                    foreach (ModuleDiagnostics::summary($report)['issues'] as $issue)
                        \App\Models\Cmlog::create(['cm' => $cm->serial, 'board' => $cm->provisioning_board, 'loglevel' => $issue['severity'] === 'error' ? 'error' : 'warning',
                            'msg' => 'Module diagnostics: '.$issue['title'].'. '.$issue['advice'].' (received via USB)']);
                Cm::whereKey($cm->id)->where('diagnostics_token', $m['token'])->update(['storage_diagnostics' => $report, 'storage_diagnostics_at' => $row->received_at, 'diagnostics_requested_at' => null]);
            }
            // Bound history independently of provisioning records; no commands or credentials are retained.
            UsbDiagnosticReport::where('received_at', '<', now()->subDays(14))->delete();
            $cutoff = UsbDiagnosticReport::orderByDesc('id')->skip(1999)->value('id');
            if ($cutoff) UsbDiagnosticReport::where('id', '<', $cutoff)->delete();
            return ['action' => 'stored', 'id' => $row->id];
        });
    }

    protected function validate(array $m): void
    {
        if (!preg_match('/^[0-9a-f]{16}$/D', $m['serial'] ?? '') || $m['serial'] === str_repeat('0', 16)
            || ($m['descriptor_serial'] ?? '') !== $m['serial'] || !preg_match(self::UUID, $m['boot_id'] ?? '')
            || !in_array($m['context'] ?? '', array_merge(ModuleDiagnostics::CONTEXTS, ['idle']), true)
            || ($m['context'] !== 'idle' && !preg_match(self::UUID, $m['event_id'] ?? ''))
            || !is_int($m['age'] ?? null) || $m['age'] < 0 || $m['age'] > 10000000000
            || !is_int($m['collected'] ?? null) || $m['collected'] < 0 || $m['collected'] > 10000000000
            || !is_string($m['token'] ?? null) || ($m['token'] !== '-' && !preg_match('/^[a-zA-Z0-9]{40}$/D', $m['token'])))
            throw new InvalidArgumentException('Invalid USB diagnostic identity/status.');
    }
}
