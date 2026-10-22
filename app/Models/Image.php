<?php

namespace App\Models;

use App\Jobs\SplitImage;
use App\Support\ImagePieces;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use HasFactory;

    protected $casts = [
        'uncompressed_size' => 'integer', 'split_piece_mb' => 'integer', 'split_queued_at' => 'datetime',
        'download_url' => 'encrypted', 'download_bytes' => 'integer', 'download_total' => 'integer',
        'download_started_at' => 'datetime', 'download_updated_at' => 'datetime',
    ];

    /* the address an image was fetched from may carry a token in its query: never in an API answer */
    protected $hidden = ['download_url'];

    /* Images no project takes and no module writes yet: an image from the internet is fetched, repacked and checked first.
       An uploaded image has no download_state and is ready as soon as it is stored. */
    function scopeReady($query)
    {
        return $query->whereNull('download_state')->whereNull('download_error');
    }

    function isReady(): bool
    {
        return $this->download_state === null && $this->download_error === null;
    }

    /* What the Images page says of an image that is not ready; '' for a ready one */
    function downloadLabel(): string
    {
        $percent = $this->download_total ? min(100, (int) floor($this->download_bytes * 100 / $this->download_total)) : null;
        switch ($this->download_state)
        {
            case 'queued':
                return 'Waiting';
            case 'downloading':
                return $percent === null ? 'Downloading'
                    : 'Downloading '.$percent.' % ('.self::size((int) $this->download_bytes).' of '.self::size((int) $this->download_total).')';
            case 'converting':
                return 'Repacking zip to gz'.($percent === null ? '' : ' '.$percent.' %');
            case 'checking':
                return 'Checking';
            case 'failed':
                return 'Failed: '.$this->download_error;
        }

        return $this->download_error === null ? '' : 'Failed: '.$this->download_error;
    }

    /* Why a module cannot write this image yet; null when it can */
    function notReadyReason(): ?string
    {
        if ($this->isReady())
            return null;
        switch ($this->download_state)
        {
            case 'queued':
                return 'Image '.$this->filename.' is still waiting to be downloaded, try again later...';
            case 'downloading':
                return 'Image '.$this->filename.' is still being downloaded, try again later...';
            case 'converting':
                return 'Image '.$this->filename.' is still being repacked, try again later...';
            case 'checking':
                return 'Image '.$this->filename.' is still being checked, try again later...';
        }

        return 'Image '.$this->filename.' failed to download: '.($this->download_error ?: 'see the Images page');
    }

    /* 800 kB, 544 MB, 1.6 GB */
    static function size(int $bytes): string
    {
        if ($bytes >= 1000000000)
            return number_format($bytes / 1000000000, 1).' GB';

        return $bytes >= 1000000 ? number_format($bytes / 1000000).' MB' : number_format($bytes / 1000).' kB';
    }

    /* " · 12 MB/s · 4 min left" for a fetch that runs at $speed bytes a second; '' while the speed is not known */
    function downloadRate(?float $speed): string
    {
        if (!$speed || $speed <= 0)
            return '';
        $seconds = (int) ceil(max(0, (int) $this->download_total - (int) $this->download_bytes) / $speed);
        if ($seconds < 90)
            $left = $seconds.' s';
        elseif ($seconds < 5400)
            $left = round($seconds / 60).' min';
        else
            $left = intdiv($seconds, 3600).' h '.round(($seconds % 3600) / 60).' min';

        return ' · '.self::size((int) $speed).'/s · '.$left.' left';
    }

    /* where an image came from, for the page: "WLAN-Pi/pi-gen 26.10-DeadEye", "Raspberry Pi OS catalog", "example.org"; null for an uploaded one */
    function sourceLabel(): ?string
    {
        if ($this->source_type === null)
            return null;
        $source = json_decode((string) $this->source, true) ?: [];
        switch ($this->source_type)
        {
            case 'github':
                return trim(($source['repo'] ?? 'GitHub').' '.($source['tag'] ?? ''));
            case 'raspberrypi':
                return 'Raspberry Pi OS catalog'.(isset($source['name']) ? ': '.$source['name'] : '');
        }

        return (isset($source['url']) ? parse_url($source['url'], PHP_URL_HOST) : null) ?: 'a link';
    }

    /* how the file is checked against what its source published */
    function checksumLabel(): ?string
    {
        $labels = [
            'github-digest' => 'verified against the GitHub digest',
            'sums-file' => 'verified against the release checksum list',
            'sha256-file' => 'verified against the published .sha256 file',
            'catalog' => 'verified against the Raspberry Pi catalog',
            'user' => 'verified against the checksum you gave',
            'none' => 'no published checksum',
        ];

        return $labels[$this->checksum_source] ?? null;
    }

    function imagepath()
    {
        return public_path('uploads/'.$this->filename_on_server);
    }

    /* Size of the compressed file on disk, or null when the file is gone */
    function filesize()
    {
        return is_file($this->imagepath()) ? filesize($this->imagepath()) : null;
    }

    /* The pieces copy (2.6.1, App\Support\ImagePieces): its file, null without one */
    function splitPath()
    {
        return $this->split_filename ? public_path('uploads/'.$this->split_filename) : null;
    }

    /* Where every gzip member starts in the copy, and last the copy's size */
    function splitOffsets(): array
    {
        $offsets = json_decode((string) $this->split_offsets, true);
        return is_array($offsets) ? array_map('intval', $offsets) : [];
    }

    /* The copy is there: in the database, with at least one piece, and on disk */
    function splitReady(): bool
    {
        return $this->split_filename && count($this->splitOffsets()) >= 3 && is_file($this->splitPath());
    }

    /* A job makes the copy; one queued longer ago than the job's timeout has stopped without a result */
    function splitPreparing(): bool
    {
        return $this->split_queued_at !== null
            && $this->split_queued_at->getTimestamp() > now()->getTimestamp() - ImagePieces::SPLIT_TIMEOUT;
    }

    /* A project with "Split the image into pieces" writes this image */
    function splitNeeded(): bool
    {
        return Project::where('image_id', $this->id)->where('split_image', true)->exists();
    }

    /* Have the pieces copy made (App\Jobs\SplitImage), unless it is there or on its way */
    function queueSplit()
    {
        if ($this->splitReady() || $this->splitPreparing())
            return;
        $this->split_error = null;
        $this->split_queued_at = now();
        $this->save();
        $job = new SplitImage($this);
        try
        {
            dispatch($job);
        }
        catch (\Throwable $e)
        {
            /* not queued: no "preparing…", and no lock against a second job, which Laravel keeps and would skip Retry with */
            (new UniqueLock(app(Cache::class)))->release($job);
            $this->split_queued_at = null;
            $this->save();
            throw $e;
        }
    }

    /* What the pages say about the pieces copy */
    function splitState(): string
    {
        if ($this->splitReady())
            return 'ready, '.ImagePieces::gb((int) filesize($this->splitPath()));
        if ($this->splitPreparing())
            return 'preparing…';
        if ($this->split_error)
            return 'failed: '.$this->split_error;
        return 'not made yet';
    }

    function removeSplit()
    {
        if ($this->splitPath() && is_file($this->splitPath()))
            unlink($this->splitPath());
        $this->split_filename = null;
        $this->split_offsets = null;
        $this->split_piece_mb = null;
        $this->split_error = null;
        $this->save();
    }

    function delete()
    {
        // Delete image from filesystem, if it is still there
        if (is_file($this->imagepath()))
            unlink($this->imagepath());
        // and what an unfinished fetch left (App\Jobs\DownloadImage)
        if (is_file($this->imagepath().'.part'))
            unlink($this->imagepath().'.part');
        // and the half-made .gz of an archive being repacked (App\Jobs\ConvertImageArchive)
        if (is_file($this->imagepath().'.gz.part'))
            unlink($this->imagepath().'.gz.part');
        // and its pieces copy
        if ($this->splitPath() && is_file($this->splitPath()))
            unlink($this->splitPath());

        // Delete from database
        parent::delete();
    }
}
