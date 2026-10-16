<?php

namespace App\Jobs;

use App\Models\Image;
use App\Support\ImagePieces;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Str;

/* The pieces copy of an image (2.6.1, App\Support\ImagePieces) for the projects with "Split the image into pieces". One pass
   decompresses the image and packs its first MiB, then every piece, as a gzip member of its own; the copy counts only once
   it decompresses to the SHA256 of that pass. The original stays as it is: a module downloading it is not disturbed. */
class SplitImage implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /* a run cut off by a restart of the queue worker (a package upgrade) is queued again this many times */
    const RESTARTS = 3;

    public $image;
    public $restarts;
    public $tries = 1;
    public $timeout = ImagePieces::SPLIT_TIMEOUT;
    /* the lock against a second job outlives the job no longer than "preparing…" does (a job that never got queued) */
    public $uniqueFor = ImagePieces::SPLIT_TIMEOUT;
    public $failOnTimeout = true;
    public $deleteWhenMissingModels = true;

    public function __construct(Image $image, int $restarts = 0)
    {
        $this->image = $image;
        $this->restarts = $restarts;
        $this->onQueue('images');   // minutes of work: the worker of the image checksums (cmprovision-queue-images)
    }

    public function uniqueId()
    {
        return (string) $this->image->id;
    }

    public function handle()
    {
        $image = $this->image;
        if ($image->splitReady())
            return $this->settle($image);

        $decompress = ['gz' => 'gzip -dc', 'xz' => 'xz -dc', 'bz2' => 'bunzip2 -dc'][$image->filename_extension] ?? null;
        if (!$decompress || !is_file($image->imagepath()))
            throw new \RuntimeException('the image file is missing');
        $need = (int) ceil(filesize($image->imagepath()) * (float) config('cmprovision.split_space_factor'));
        $free = (int) disk_free_space(public_path('uploads'));
        if ($free < $need)
            throw new \RuntimeException('not enough free space: need about '.ImagePieces::gb($need).', '.ImagePieces::gb($free).' free');

        $tmp = self::temp($image);
        self::clean($image);
        $mb = ImagePieces::pieceMb();
        [$code, , $err] = self::bash('set -o pipefail; '.$decompress.' "$SRC" | tee >(sha256sum >&2)'
            .' | { dd bs=1M count=1 iflag=fullblock status=none | gzip -6n > "$OUT" && stat -c %s "$OUT" > "$IDX"'
            .' && split -b '.$mb.'M --filter=\'gzip -6n >> "$OUT" && stat -c %s "$OUT" >> "$IDX"\' -; }',
            ['SRC' => $image->imagepath(), 'OUT' => $tmp, 'IDX' => $tmp.'.index']);
        if ($code !== 0 || !preg_match('/^([0-9a-f]{64})  -$/m', $err, $m))
            throw new \RuntimeException('splitting failed: '.trim($err));
        $sha = $m[1];

        [$code, $size, $err] = self::bash('set -o pipefail; gzip -dc "$OUT" | tee >(sha256sum >&2) | wc --bytes', ['OUT' => $tmp]);
        if ($code !== 0 || !preg_match('/^([0-9a-f]{64})  -$/m', $err, $m) || $m[1] !== $sha)
            throw new \RuntimeException('the pieces do not decompress to the image');
        if ($image->uncompressed_sha256 && $image->uncompressed_sha256 !== $sha)
            throw new \RuntimeException('the image file changed since its SHA256 was computed');

        // each line is the size of the copy after one more member: with a 0 in front, where every member starts
        $offsets = array_map('intval', file($tmp.'.index', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        array_unshift($offsets, 0);
        $size = (int) trim($size);
        if (end($offsets) !== filesize($tmp) || count($offsets) - 2 !== ImagePieces::count($size, $mb))
            throw new \RuntimeException('the pieces do not add up');

        $old = $image->splitPath();
        $name = Str::random(40).'.gz';
        rename($tmp, public_path('uploads/'.$name));
        $image->split_filename = $name;
        $image->split_offsets = json_encode($offsets);
        $image->split_piece_mb = $mb;
        $image->uncompressed_sha256 = $image->uncompressed_sha256 ?: $sha;
        $image->uncompressed_size = $image->uncompressed_size ?: $size;
        $this->settle($image);
        self::clean($image);
        if ($old && $old !== $image->splitPath() && is_file($old))
            unlink($old);
    }

    /* A run cut off by a restart of the worker goes again (the next run starts from the beginning), "preparing…" from then;
       a run that timed out, or anything else, is the reason the Images page shows, until Retry */
    public function failed(?\Throwable $e = null): void
    {
        $image = Image::find($this->image->id);
        if (!$image)
            return;
        self::clean($image);
        if ($e instanceof MaxAttemptsExceededException && !$e instanceof TimeoutExceededException && $this->restarts < self::RESTARTS)
        {
            $image->split_queued_at = now();
            $image->save();
            self::dispatch($image, $this->restarts + 1)->delay(now()->addMinute());
            return;
        }
        $image->split_error = $e ? Str::limit($e->getMessage(), 500) : 'the job failed';
        $image->split_queued_at = null;
        $image->save();
    }

    /* Where a run writes the copy before it counts */
    public static function temp(Image $image): string
    {
        return public_path('uploads/.split-'.$image->id.'.tmp');
    }

    protected function settle(Image $image)
    {
        $image->split_error = null;
        $image->split_queued_at = null;
        $image->save();
    }

    protected static function clean(Image $image)
    {
        foreach ([self::temp($image), self::temp($image).'.index'] as $file)
            if (is_file($file))
                unlink($file);
    }

    /* bash -c $script with $env: [exit code, stdout, stderr]; stderr is read to its end, which waits for the sha256sum of
       a process substitution too */
    protected static function bash(string $script, array $env): array
    {
        $proc = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            $env + ['PATH' => getenv('PATH') ?: '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL' => 'C']);
        if (!$proc)
            throw new \RuntimeException('could not start bash');
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }
}
