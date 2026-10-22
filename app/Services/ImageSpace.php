<?php

namespace App\Services;

use App\Models\Cm;
use App\Models\Image;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * The disk of the provisioner under the images (public/uploads): what a fetch needs, what the fetches already on their way
 * still need, and what can be deleted to make room. The eMMC is small (29 GB on the stand's provisioner).
 */
class ImageSpace
{
    /* a repacked .zip comes out about as large as the archive; this much of the archive's size, to be safe */
    const ZIP_FACTOR = 1.3;

    /* the states of an image being fetched, repacked or checked */
    const IN_FLIGHT = ['queued', 'downloading', 'converting', 'checking'];

    protected $free;

    /** @param callable(): int|null $free the free bytes (tests give their own) */
    public function __construct(?callable $free = null)
    {
        $this->free = $free;
    }

    public function free(): int
    {
        return $this->free ? (int) call_user_func($this->free) : (int) @disk_free_space(public_path('uploads'));
    }

    /* what must stay free after a fetch, bytes */
    public function reserve(): int
    {
        return (int) config('cmprovision.image_reserve_gb') * 1073741824;
    }

    /* what fetching a file of $bytes takes, the reserve included; a .zip also needs room for its repacked file */
    public function need(int $bytes, bool $zip = false): int
    {
        return $bytes + ($zip ? (int) ceil($bytes * self::ZIP_FACTOR) : 0) + $this->reserve();
    }

    /* what the fetches already on their way still have to write */
    public function committed(?Image $except = null): int
    {
        $sum = 0;
        $query = Image::whereIn('download_state', ['queued', 'downloading', 'converting']);
        if ($except) {
            $query->where('id', '!=', $except->id);
        }
        foreach ($query->get() as $image) {
            $total = (int) $image->download_total;
            $done = min($total, (int) $image->download_bytes);
            if ($image->download_state === 'converting') {
                $sum += (int) ceil($total * self::ZIP_FACTOR * ($total ? 1 - $done / $total : 1));
                continue;
            }
            $sum += $total - $done + ($image->filename_extension === 'zip' ? (int) ceil($total * self::ZIP_FACTOR) : 0);
        }

        return $sum;
    }

    /* null when a file of $bytes fits, else why not, in words for the operator */
    public function refusal(int $bytes, bool $zip = false, ?Image $except = null): ?string
    {
        $need = $this->need($bytes, $zip) + $this->committed($except);
        $free = $this->free();
        if ($free >= $need) {
            return null;
        }
        $text = 'Needs about '.Image::size($need).', '.Image::size($free).' free.';
        $unused = $this->unused();
        if ($unused->isEmpty()) {
            return $text.' There is nothing unused to delete: free space first.';
        }

        return $text.' Delete unused images ('.$unused->count().' '.($unused->count() === 1 ? 'image' : 'images').', '.Image::size($this->bytes($unused)).') or free space first.';
    }

    /* what images take on the disk: the file, the pieces copy and what an unfinished fetch or repacking left */
    public function bytes(iterable $images): int
    {
        $sum = 0;
        foreach ($images as $image) {
            foreach ([$image->imagepath(), $image->splitPath(), $image->imagepath().'.part', $image->imagepath().'.gz.part'] as $file) {
                if ($file && is_file($file)) {
                    $sum += (int) filesize($file);
                }
            }
        }

        return $sum;
    }

    /* Images nobody needs: in no project, not on their way, not being written by a module. A failed fetch counts: it has nothing worth keeping. */
    public function unused(): Collection
    {
        $taken = Project::whereNotNull('image_id')->pluck('image_id')->map(fn ($id) => (int) $id)->all();
        $writing = Cm::whereIn('phase', ['write', 'verify'])->get(['durable_image_id', 'image_filename']);
        $ids = $writing->pluck('durable_image_id')->filter()->map(fn ($id) => (int) $id)->all();
        $names = $writing->pluck('image_filename')->filter()->all();

        return Image::where(function ($query) {
            $query->whereNull('download_state')->orWhere('download_state', 'failed');
        })->get()->reject(function (Image $image) use ($taken, $ids, $names) {
            return in_array($image->id, $taken, true) || in_array($image->id, $ids, true) || in_array($image->filename, $names, true);
        })->values();
    }
}
