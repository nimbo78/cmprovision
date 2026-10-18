<?php

namespace App\Services\Sensor;

use App\Models\Cm;
use App\Models\Project;
use App\Models\ReprovisionOperation;
use App\Models\Setting;

class ReprovisionIntent
{
    public static function fingerprint(Project $project): string
    {
        $project = $project->fresh() ?? $project;
        $data = $project->only($project->getFillable());
        $data['id'] = $project->id;
        $data['image'] = $project->image?->only(['id','filename_on_server','sha256','uncompressed_sha256','uncompressed_size','split_filename','split_piece_mb']);
        $data['scripts'] = $project->scripts()->orderBy('scripts.id')->get()->map(fn ($s) => $s->only(['id','name','script_type','bg','priority','script']))->all();
        $data['packages'] = $project->packages()->orderBy('packages.id')->get()->map(fn ($p) => $p->only(['id','filename_on_server','sha256']))->all();
        $data['label'] = $project->label?->getAttributes();
        $data['eeprom'] = Setting::find('active_eeprom_sha256')?->value;
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /* Called under the module lock before the old run can be resumed or replaced. A failed guard keeps the intent. */
    public static function guard(string $serial, ?Project $project, ?string $mac): ?string
    {
        $op = ReprovisionOperation::pending($serial);
        if (!$op) return null;
        if (!$project || !$project->image || $op->project_id != $project->id
            || !hash_equals($op->project_hash, self::fingerprint($project)))
            return 'The re-provision project changed. Confirm the current project in the module card, then restart the module.';
        if ($op->expires_at->isPast())
            return 'The re-provision request expired. Confirm the current project in the module card, then restart the module.';
        if (strtolower((string) $mac) !== $op->mac)
            return 'The re-provision MAC does not match this module.';
        return null;
    }

    public static function started(Cm $cm): void
    {
        ReprovisionOperation::where('serial', $cm->serial)->whereIn('state', ReprovisionOperation::PENDING)
            ->update(['state' => 'booted', 'message' => 'The module returned to network boot; a new run started.', 'updated_at' => now()]);
    }
}
