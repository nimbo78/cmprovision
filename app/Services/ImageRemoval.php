<?php

namespace App\Services;

use App\Models\Cm;
use App\Models\Image;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** One current-state policy for Images, Storage and model-level deletion. */
class ImageRemoval
{
    public static function blocker(Image $image, bool $piecesOnly = false): ?string
    {
        if (Cm::where(fn ($q) => $q->whereNull('phase')->orWhereNotIn('phase', ['done', 'failed']))
            ->where(fn ($q) => $q->where('durable_image_id', $image->id)->orWhere('image_filename', $image->filename))->exists())
            return 'This image is needed by a current module run. Wait until it finishes.';
        if ($piecesOnly ? $image->splitNeeded() : Project::where('image_id', $image->id)->exists())
            return $piecesOnly ? 'A project uses these pieces: they stay.' : 'This image is used by a project. Change or remove that reference first.';
        if ($image->splitPreparing()) return 'The image pieces are being prepared. Wait until that job finishes.';
        return null;
    }

    public function remove(int $id, bool $piecesOnly = false, bool $unusedOnly = false): bool
    {
        return DB::transaction(function () use ($id, $piecesOnly, $unusedOnly) {
            $image = Image::find($id);
            if (!$image) return false;
            if ($unusedOnly && in_array($image->download_state, ImageSpace::IN_FLIGHT, true))
                throw new RuntimeException('This image now has an active job and stays protected.');
            if ($reason = self::blocker($image, $piecesOnly)) throw new RuntimeException($reason);
            if ($piecesOnly) $image->removeSplit();
            else $image->delete();
            return true;
        });
    }
}
