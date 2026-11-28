<?php

namespace App\Support;

use App\Models\Cm;
use App\Models\Project;
use App\Models\Setting;
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
        $host = gethostname();
        $modules = $switches = $unknown = $recognized = [];
        foreach ($stand['switches'] ?? [] as $id => $state)
        {
            if ($state['same_as'])
                continue;
            $switch = $map['switches'][$id] ?? null;
            $answering = Stand::answering($state);
            $blocks = [];
            foreach ($switch['blocks'] ?? [] as $block)
            {
                $ports = [];
                foreach ($block['tiles'] as $tile)
                {
                    if ($tile['state'] === 'free' || $tile['link_up'] === false)
                    {
                        // A cached station advertisement can outlive its cable.
                        $tile['server'] = false;
                        $tile['station'] = null;
                    }
                    $tile['neighbors'] = $this->currentNeighbors($tile);
                    $serials = [];
                    if ($answering)
                    {
                        if ($tile['filter'] === 'recognized')
                            $recognized[] = $this->recognized($tile);
                        elseif (in_array($tile['state'], ['foreign', 'quiet'], true))
                            $unknown[] = $this->unknown($tile);
                    }
                    foreach ($tile['modules'] as $cm)
                    {
                        $serials[] = $cm->serial;
                        $modules[$cm->serial] = $this->module($cm, $tile['label'], $batch?->slow[$cm->id] ?? null);
                        $modules[$cm->serial]['port_id'] = $tile['key'];
                    }
                    $ports[] = ['name' => $tile['name'], 'number' => $tile['panel_number'] ?? $tile['number'],
                        'state' => $tile['filter'] === 'recognized' ? 'recognized' : ($tile['state'] === 'foreign' ? 'unrecognized' : $tile['state']),
                        'mark' => $tile['mark'], 'filter' => $tile['filter'], 'serials' => $serials, 'server' => $tile['server'],
                        // another cmprovision station on the port: its name, address, whether it is the stand's provisioner
                        'station' => $tile['station'] ?? null, 'endpoint' => $this->endpoint($tile, $host),
                        'link_up' => $tile['link_up'], 'speed' => $tile['speed'],
                        'port_id' => $tile['key'], 'info' => $this->portInfo($tile),
                        'poe_state' => $tile['poe']['state'] ?? null, 'watts' => $tile['poe']['watts'] ?? null]
                        + array_intersect_key($tile, ['panel_y' => true]);
                }
                $blocks[] = ['rows' => $block['rows'], 'label' => $block['label'] ?? ($block['uplinks'] ? 'UPLINK' : 'PORTS'),
                    'ports' => $ports];
            }
            $switches[] = ['name' => $state['switch']->hostname(), 'host' => $state['config']['host'],
                'model' => $switch['panel']['model'] ?? ($state['snapshot']['model'] ?? ''),
                'serial' => $state['snapshot']['identity']['serial'] ?? null,
                'verified_layout' => isset($switch['panel']), 'answering' => $answering,
                'checked_at' => $state['checked_at'], 'silent_since' => $state['error']['since'] ?? null, 'blocks' => $blocks]
                + array_intersect_key($switch['panel'] ?? [], ['layout' => true]);
        }

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
                    'os_state' => null, 'registered' => false, 'hardware' => ModuleHardware::fromFacts($row->report['facts'] ?? [])];

        $modules = array_values($modules);
        $ids = array_unique(array_filter(array_column($modules, 'project_id')));
        $projects = $ids ? Project::whereKey(array_values($ids))->pluck('name', 'id') : collect();
        foreach ($modules as &$module)
        {
            if (isset($module['project_id']))
                $module['project'] = $projects->get($module['project_id']);
            unset($module['project_id']);
        }
        unset($module);
        $counts = ['done' => 0, 'running' => 0, 'failed' => 0, 'stale' => 0, 'unknown' => 0];
        foreach ($modules as $module)
            $counts[['ok' => 'done', 'run' => 'running', 'fail' => 'failed', 'stale' => 'stale'][$module['state']] ?? 'unknown']++;
        $project = Project::getActive();
        return ['version' => 1, 'generated_at' => now()->getTimestamp(), 'clock' => now()->local()->format('Y-m-d H:i:s T'),
            'host' => $host, 'app_version' => AppVersion::text(), 'project' => $project?->name,
            'batch' => $batch ? ['title' => $batch->title(), 'open' => $batch->open, 'counts' => $batch->counts,
                'left_seconds' => $batch->leftSeconds] : null,
            'counts' => $counts, 'switches' => $switches, 'modules' => $modules, 'unknown' => $unknown, 'recognized' => $recognized,
            // how the FPMS panel looks (Settings → System → FPMS display); an older panel does not read it
            'panel' => FpmsPanelSettings::get(),
            // Both local displays use the same station-wide sunrise calculation as the web UI.
            'display_auto_mode' => DisplaySunMode::auto(now()->toDateTimeImmutable(), SunPlace::forZone(config('app.display_timezone'))),
            'hdmi_auto_mode' => Setting::find('hdmi_color_mode')?->value === 'auto',
            // Independent of FPMS enablement: HDMI and either panel layout consume the same secret-free status.
            'rescue_wifi' => app(\App\Services\RescueWifi::class)->status(),
            // Both displays share the bounded, secret-free 30-second network snapshot, independently of FPMS enablement.
            'station' => DisplayStation::current()];
    }

    private function currentNeighbors(array $tile): array
    {
        if ($tile['state'] === 'free' || $tile['link_up'] === false)
            return [];
        $matched = array_values(array_filter($tile['neighbors'], static function ($neighbor) use ($tile) {
            $advertised = array_filter([$neighbor['mac'] ?? null, $neighbor['port_mac'] ?? null]);
            return !$tile['mac_count'] || ($tile['macs'] && (!$advertised || array_intersect($tile['macs'], $advertised)));
        }));
        // LLDP may carry the signed boot mark in its port description. The switch map has
        // already verified it. Both the legacy summary and structured fields use public text.
        foreach ($matched as &$neighbor)
            foreach (['name', 'description', 'platform', 'port', 'chassis'] as $key)
                if (stripos($neighbor[$key] ?? '', 'cmprovision mark ') !== false)
                    $neighbor[$key] = '';
        unset($neighbor);
        return $matched;
    }

    private function portInfo(array $tile): array
    {
        $neighbors = [];
        foreach (array_slice($tile['neighbors'], 0, 4) as $neighbor)
        {
            $fields = array_intersect_key($neighbor, array_flip(['protocol', 'name', 'platform', 'port', 'address', 'chassis', 'mac', 'port_mac']));
            if (empty($fields['platform']) && !empty($neighbor['description']))
                $fields['description'] = $neighbor['description'];
            $public = array_filter($fields, static fn ($value) => is_string($value) && $value !== '');
            $neighbors[] = array_map(static fn ($value) => mb_substr($value, 0, 128), $public);
        }
        return array_filter(['alias' => mb_substr((string) $tile['alias'], 0, 128), 'changed' => $tile['changed'],
            'media' => $tile['reported_media'] ?? null, 'transceiver' => $tile['transceiver'] ?? null,
            'poe_class' => $tile['poe']['class'] ?? null, 'mac_count' => $tile['mac_count'],
            'macs' => array_slice($tile['macs'], 0, 8), 'neighbors' => $neighbors,
            'neighbor_count' => count($tile['neighbors'])],
            static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    protected function endpoint(array $tile, string $host): ?string
    {
        if ($tile['state'] === 'free' || $tile['link_up'] === false)
            return null; // A lost link can leave the former device in the forwarding table.
        if ($tile['state'] === 'uplink')
            return $tile['server'] ? ($host ?: null) : (($tile['station']['name'] ?? '') ?: $tile['link']);
        if ($tile['modules'])
        {
            $extra = count($tile['modules']) - 1;
            return $tile['modules'][0]->displayName().($extra ? ' +'.$extra : '');
        }

        $macs = $tile['macs']; // StandMap removed module MACs assigned to another port.
        foreach ($tile['neighbors'] as $neighbor)
        {
            if ($neighbor['name'] !== '')
                return $neighbor['name'];
            if (!$tile['mac_count'] && isset($neighbor['mac']))
                return $neighbor['mac'];
        }
        return count($macs) === 1 ? $macs[0] : null;
    }

    /* A port with a device that is not a module and is not booting: what it says about itself over LLDP or CDP, or else
       who made its adapter (the MAC), for the table of the display; the texts are cleaned already (Neighbors::text) */
    protected function unknown(array $tile): array
    {
        $says = $tile['neighbors'][0] ?? null;
        $matches = $says ? array_intersect(array_filter([$says['mac'] ?? null, $says['port_mac'] ?? null]), $tile['macs']) : [];
        $mac = $matches ? reset($matches) : (!$says && count($tile['macs']) === 1 ? $tile['macs'][0] : null);
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
        return ['port' => $tile['label'], 'port_id' => $tile['key'], 'state' => $tile['state'] === 'quiet' ? 'quiet' : 'unrecognized', 'name' => $name !== '' ? $name : 'unknown device',
            'mac' => $mac, 'detail' => $detail, 'says' => (bool) $says];
    }

    /* A signed sensor is visible on the stand, but is not a local provisioning run and is not an error. */
    protected function recognized(array $tile): array
    {
        $sensor = $this->unknown($tile);
        $mark = $tile['flashed_by'];
        $sensor['state'] = 'recognized';
        $sensor['detail'] = 'Flashed by '.$mark['station'].' / boot '.$mark['boot'].' of '.$mark['boots'];
        $sensor['flashed_by'] = $mark;
        return $sensor;
    }

    /* $slow: the seconds the run is past what the finished modules of its batch took (SlowRuns), null when it is not slow */
    protected function module(Cm $cm, string $port, ?int $slow = null): array
    {
        $finished = $cm->finishedAt();
        $end = $finished ?? now();
        $advice = FailureAdvice::for($cm);
        $short = ShortCause::for($cm);
        // A module is named by its host name or its MAC; the serial only identifies the row (it matches the ports)
        return ['serial' => $cm->serial, 'mac' => $cm->mac, 'hostname' => trim((string) $cm->hostname) ?: null,
            // the front panel: the step by the percent, what went wrong in short and in full, its exit codes
            'step' => ShortCause::step($cm), 'cause' => $short['cause'] ?? null, 'reason' => $short['reason'] ?? null,
            'code' => $short['code'] ?? null,
            'name' => $cm->displayName(), 'port' => $port, 'hardware' => ModuleHardware::forModule($cm),
            'project_id' => $cm->project_id, 'image' => $cm->image_filename, 'started_at' => $cm->provisioning_started_at?->getTimestamp(),
            'started' => $cm->provisioning_started_at?->local()->format('Y-m-d H:i T'),
            'finished' => $finished?->local()->format('Y-m-d H:i T'),
            'phase' => $cm->usb_prep_at !== null && $cm->phase === 'done' ? 'ready' : $cm->phase, 'state' => StandMap::moduleState($cm), 'detail' => $cm->phase_detail,
            'percent' => $cm->progressPercent(), 'speed' => $cm->bytesPerSecond(), 'eta' => $cm->secondsLeft(),
            'bytes' => $cm->progress_bytes, 'total' => $cm->progress_total,
            'cycle' => $cm->provisioning_started_at ? max(0, $end->getTimestamp() - $cm->provisioning_started_at->getTimestamp()) : null,
            'silent' => $cm->silentFor(), 'slow' => $slow, 'os_state' => $cm->os_state,
            'diagnostics' => $this->diagnostics($cm->storage_diagnostics, $cm->storage_diagnostics_at),
            'steps' => array_values(array_unique(array_column($cm->timeline ?: [], 'phase'))),
            // with the reason given apart, the advice is only what to do (FailureAdvice todo)
            'advice' => $advice['todo'] ?? $advice['advice'] ?? null, 'registered' => true];
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
