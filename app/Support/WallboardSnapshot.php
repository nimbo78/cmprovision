<?php

namespace App\Support;

use App\Models\Cm;
use App\Models\Project;
use App\Models\UsbDiagnosticReport;
use App\Services\OuiVendors;
use App\Services\Switch\Stand;

/* Local display only. No HTTP route, credentials, raw logs or commands to modules. The Ports page and this
   reader share the same switch cache and classification; a disconnected historical module is not on the stand. */
class WallboardSnapshot
{
    const WALLBOARD_SECONDS = 15;

    public function current(): array
    {
        // A display may be a little behind: it takes the Ports cache up to WALLBOARD_SECONDS old and asks a switch itself
        // only when that has run out, instead of every few seconds around the clock (the web UI's own polls refresh it too).
        $stand = Stand::current(null, 0, [], self::WALLBOARD_SECONDS);
        $batch = BatchSummary::forDashboard();
        $map = $stand['map'] ?? null;
        $modules = $switches = $unknown = [];
        foreach ($map['switches'] ?? [] as $id => $switch)
        {
            $state = $stand['switches'][$id];
            $blocks = [];
            foreach ($switch['blocks'] as $block)
            {
                $ports = [];
                foreach ($block['tiles'] as $tile)
                {
                    $serials = [];
                    if (in_array($tile['state'], ['foreign', 'quiet'], true))
                        $unknown[] = $this->unknown($tile);
                    foreach ($tile['modules'] as $cm)
                    {
                        $serials[] = $cm->serial;
                        $modules[$cm->serial] = $this->module($cm, $tile['label'], $batch?->slow[$cm->id] ?? null);
                    }
                    $ports[] = ['name' => $tile['name'], 'number' => $tile['panel_number'] ?? $tile['number'],
                        'state' => $tile['state'] === 'foreign' ? 'unrecognized' : $tile['state'],
                        'mark' => $tile['mark'], 'filter' => $tile['filter'], 'serials' => $serials, 'server' => $tile['server'],
                        'speed' => $tile['speed'], 'watts' => $tile['poe']['watts'] ?? null];
                }
                $blocks[] = ['rows' => $block['rows'], 'label' => $block['label'] ?? ($block['uplinks'] ? 'UPLINK' : 'PORTS'),
                    'ports' => $ports];
            }
            $switches[] = ['name' => $switch['name'], 'model' => $switch['panel']['model'] ?? ($state['snapshot']['model'] ?? ''),
                'verified_layout' => $switch['panel'] !== null, 'answering' => Stand::answering($state),
                'checked_at' => $state['checked_at'], 'blocks' => $blocks];
        }
        // A switch which has never answered has no map yet. Keep its outage visible too.
        foreach ($stand['switches'] ?? [] as $id => $state)
            if (!isset($map['switches'][$id]) && !$state['same_as'])
                $switches[] = ['name' => $state['switch']->hostname(), 'model' => '', 'verified_layout' => false,
                    'answering' => false, 'checked_at' => $state['checked_at'], 'blocks' => []];

        // No map: no switch configured, or none has answered yet. The modules that are on the bench still count.
        if (!$map)
            foreach (Cm::where('polled_at', '>', now()->subSeconds(Cm::ON_BENCH_SECONDS))
                ->orWhere(function ($q) { $q->whereIn('phase', Cm::ACTIVE_PHASES)->where('provisioning_started_at', '>', now()->subHours(Cm::ACTIVE_HOURS)); })
                ->orderBy('serial')->limit(96)->get() as $cm)
                $modules[$cm->serial] = $this->module($cm, 'Port unknown', $batch?->slow[$cm->id] ?? null);

        // USB observations are separate from provisioning runs and never increase batch success/failure counts.
        foreach (UsbDiagnosticReport::where('received_at', '>', now()->subSeconds(Cm::ON_BENCH_SECONDS))
            ->orderByDesc('received_at')->limit(48)->get()->unique('serial') as $row)
            if (!isset($modules[$row->serial]) && !Cm::where('serial', $row->serial)->exists())
                $modules[$row->serial] = ['serial' => $row->serial, 'mac' => $row->mac, 'hostname' => null,
                    'name' => $row->mac ?: 'Unregistered module', 'port' => 'USB / port unknown',
                    'phase' => 'unregistered', 'state' => 'unknown', 'detail' => 'USB observation; no provisioning run',
                    'percent' => null, 'speed' => null, 'eta' => null, 'cycle' => null, 'silent' => null,
                    'diagnostics' => $this->diagnostics($row->report, $row->received_at), 'steps' => [], 'advice' => null,
                    'os_state' => null, 'registered' => false];

        $modules = array_values($modules);
        $counts = ['done' => 0, 'running' => 0, 'failed' => 0, 'stale' => 0, 'unknown' => 0];
        foreach ($modules as $module)
            $counts[['ok' => 'done', 'run' => 'running', 'fail' => 'failed', 'stale' => 'stale'][$module['state']] ?? 'unknown']++;
        $project = Project::getActive();
        return ['version' => 1, 'generated_at' => now()->getTimestamp(), 'clock' => now()->format('Y-m-d H:i:s T'),
            'host' => gethostname(), 'app_version' => AppVersion::text(), 'project' => $project?->name,
            'batch' => $batch ? ['title' => $batch->title(), 'open' => $batch->open, 'counts' => $batch->counts] : null,
            'counts' => $counts, 'switches' => $switches, 'modules' => $modules, 'unknown' => $unknown];
    }

    /* A port with a device that is not a module and is not booting: what it says about itself over LLDP or CDP, or else
       who made its adapter (the MAC), for the table of the display; the texts are cleaned already (Neighbors::text) */
    protected function unknown(array $tile): array
    {
        $says = $tile['neighbors'][0] ?? null;
        $mac = $tile['macs'][0] ?? null;
        $maker = $mac ? OuiVendors::name($mac) : null;
        if ($says)
        {
            $name = $says['name'] !== '' ? $says['name'] : ($says['platform'] !== '' ? $says['platform'] : $says['chassis']);
            $detail = implode(' / ', array_filter([$says['platform'] !== '' ? $says['platform'] : $says['description'], $says['address'],
                implode(', ', $says['capabilities'])]));
        }
        else
        {
            $name = $maker ?: ($mac ?: 'unknown device');
            $detail = $tile['state'] === 'quiet' ? 'link is up, nothing sent from it' : implode(' / ', array_filter([$maker ? $mac : null]));
        }
        return ['port' => $tile['label'], 'state' => $tile['state'] === 'quiet' ? 'quiet' : 'unrecognized', 'name' => $name !== '' ? $name : 'unknown device',
            'mac' => $mac, 'detail' => $detail, 'says' => (bool) $says];
    }

    /* $slow: the seconds the run is past what the finished modules of its batch took (SlowRuns), null when it is not slow */
    protected function module(Cm $cm, string $port, ?int $slow = null): array
    {
        $end = $cm->provisioning_complete_at ?? ($cm->phase === 'failed' ? $cm->phase_started_at : null) ?? now();
        // A module is named by its host name or its MAC; the serial only identifies the row (it matches the ports)
        return ['serial' => $cm->serial, 'mac' => $cm->mac, 'hostname' => trim((string) $cm->hostname) ?: null,
            'name' => $cm->displayName(), 'port' => $port,
            'phase' => $cm->usb_prep_at !== null && $cm->phase === 'done' ? 'ready' : $cm->phase, 'state' => StandMap::moduleState($cm), 'detail' => $cm->phase_detail,
            'percent' => $cm->progressPercent(), 'speed' => $cm->bytesPerSecond(), 'eta' => $cm->secondsLeft(),
            'bytes' => $cm->progress_bytes, 'total' => $cm->progress_total,
            'cycle' => $cm->provisioning_started_at ? max(0, $end->getTimestamp() - $cm->provisioning_started_at->getTimestamp()) : null,
            'silent' => $cm->silentFor(), 'slow' => $slow, 'os_state' => $cm->os_state,
            'diagnostics' => $this->diagnostics($cm->storage_diagnostics, $cm->storage_diagnostics_at),
            'steps' => array_values(array_unique(array_column($cm->timeline ?: [], 'phase'))),
            'advice' => FailureAdvice::for($cm)['advice'] ?? null, 'registered' => true];
    }

    protected function diagnostics(?array $report, $received): ?array
    {
        if (!$report || !$received)
            return null;
        $summary = ModuleDiagnostics::summary($report);
        $facts = array_intersect_key($report['facts'], array_flip(['temperature_mc', 'throttled', 'mem_available_kb',
            'mem_total_kb', 'block', 'controller', 'eth_carrier', 'eth_speed_mbps', 'kernel', 'utility_os']));
        return ['received_at' => $received->getTimestamp(), 'source' => ($report['source'] ?? '') === 'usb' ? 'USB' : 'Ethernet',
            'title' => $summary['title'], 'severity' => $summary['severity'], 'facts' => $facts,
            'issues' => array_map(fn ($issue) => array_intersect_key($issue, array_flip(['title', 'advice', 'evidence'])), array_slice($summary['issues'], 0, 4))];
    }
}
