<?php

namespace App\Services\Configuration;

use App\Models\Cm;
use App\Models\Project;
use App\Models\Image;
use Illuminate\Support\Facades\DB;

/** Cheap database snapshot; large file hashing happens before the write transaction. */
class TransferState
{
    public static function revision(): string
    {
        $hash = hash_init('sha256');
        foreach (['projects', 'scripts', 'labels', 'images', 'packages', 'project_script', 'package_project',
            'settings', 'ethernet_switches', 'hosts', 'notification_channels'] as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => TransferPreview::canonical((array) $row))->all();
            sort($rows, SORT_STRING);
            hash_update($hash, $table.TransferPreview::canonical($rows));
        }
        hash_update($hash, TransferPreview::canonical(Cm::whereIn('phase', Cm::ACTIVE_PHASES)
            ->orderBy('id')->get(['id', 'project_id', 'switch_id', 'phase'])->toArray()));
        return hash_final($hash);
    }

    public static function protected(string $type, int|string|null $id): bool
    {
        if ($id === null) return false;
        $projects = Cm::whereIn('phase', Cm::ACTIVE_PHASES)->whereNotNull('project_id')->pluck('project_id')->all();
        if ($active = Project::getActiveId()) $projects[] = $active;
        $query = Project::whereIn('id', array_unique($projects));
        return match ($type) {
            'project' => in_array((int) $id, array_map('intval', $projects), true),
            'image' => $query->where('image_id', $id)->exists() || (Image::find($id)?->splitPreparing() ?? false),
            'label' => $query->where('label_id', $id)->exists(),
            'firmware' => $query->where('eeprom_firmware', $id)->exists(),
            'script' => $query->whereHas('scripts', fn ($q) => $q->where('scripts.id', $id))->exists(),
            'package' => $query->whereHas('packages', fn ($q) => $q->where('packages.id', $id))->exists(),
            'switch' => Cm::whereIn('phase', Cm::ACTIVE_PHASES)->where('switch_id', $id)->exists(),
            'host', 'settings' => Cm::whereIn('phase', Cm::ACTIVE_PHASES)->exists(),
            default => false,
        };
    }
}
