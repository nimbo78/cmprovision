<?php

namespace App\Models;

use App\Jobs\SplitImage;
use App\Support\ImagePieces;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use HasFactory;

    protected $casts = ['uncompressed_size' => 'integer', 'split_piece_mb' => 'integer', 'split_queued_at' => 'datetime'];

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
        SplitImage::dispatch($this);
    }

    function removeSplit()
    {
        if ($this->splitPath() && is_file($this->splitPath()))
            unlink($this->splitPath());
        $this->split_filename = null;
        $this->split_offsets = null;
        $this->split_piece_mb = null;
        $this->save();
    }

    function delete()
    {
        // Delete image from filesystem, if it is still there
        if (is_file($this->imagepath()))
            unlink($this->imagepath());
        // and its pieces copy
        if ($this->splitPath() && is_file($this->splitPath()))
            unlink($this->splitPath());

        // Delete from database
        parent::delete();
    }
}
