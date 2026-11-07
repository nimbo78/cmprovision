<?php

namespace App\Services;

use App\Models\Cm;
use App\Models\Project;
use App\Models\StationSetupRun;
use App\Services\Sensor\ProvisionerKey;

class StationSetup
{
    public function prepare(Cm $cm, Project $project, string $server): string
    {
        if (!$project->verify || !$project->image || !preg_match('#^/dev/mmcblk[0-9]+$#D', $project->storage)
            || !preg_match('/^[a-f0-9]{16}$/D', (string) $cm->serial) || !preg_match('/^[a-f0-9]{32}$/D', (string) $cm->cid)
            || !preg_match('/^[A-Za-z0-9]{40}$/D', (string) $cm->diagnostics_token))
            throw new \RuntimeException('Station setup requires verification, eMMC/SD and a reported serial and storage CID.');
        self::prune();
        $run = StationSetupRun::where('cm_id', $cm->id)->first();
        if (!$run || $run->run !== $cm->diagnostics_token) {
            $settings = $project->station_setup;
            $settings['hostname'] = str_replace('{serial}', $cm->serial, $settings['hostname']);
            $run = StationSetupRun::updateOrCreate(['cm_id' => $cm->id], [
                'run' => $cm->diagnostics_token, 'settings' => $settings,
                'host_key' => null, 'state' => 'pending', 'expires_at' => now()->addHours(12),
            ]);
            \App\Jobs\ExpireStationSetup::dispatch($run->id)->delay($run->expires_at);
        }
        if ($run->state === 'applied') return "#!/bin/sh\necho 'OS setup already applied for this provisioning run'\n";
        $profile = $run->settings['profile'] ?? 'station';
        return view('scriptexecute.station-setup', [
            'stationStorage' => $project->storage, 'stationSerial' => $cm->serial, 'stationCid' => $cm->cid,
            'stationRun' => $run->run, 'stationPublic' => app(ProvisionerKey::class)->ensure(),
            'stationUrl' => 'http://'.$server.'/station-setup',
            'stationProfile' => $profile,
            'stationHelperUrl' => 'http://'.$server.'/tools/image-access',
            'stationHelperSha256' => $profile === 'raspios' ? hash_file('sha256', public_path('tools/image-access')) : null,
        ])->render();
    }

    public static function prune(): void
    {
        StationSetupRun::where('expires_at', '<', now())->delete();
    }
}
