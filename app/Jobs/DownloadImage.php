<?php

namespace App\Jobs;

use App\Models\Image;
use App\Services\ImageFetcher;
use App\Services\ImageSpace;
use App\Support\ImageSourceException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/*
 * One slice of fetching an image from the internet (2.8.0, App\Services\ImageDownloads). It works for about a minute
 * (config image_download_slice, less than the queue's retry_after) and, if the file is not whole yet, queues the next slice behind
 * whatever else waits on the images queue: the checksums and pieces copies of other images are not held up for the hours
 * a large download can take. All the state is in the database and in the .part file, so a restart of the worker loses nothing
 * but the slice. The slices of one image never overlap (a lock), and a chain that breaks leaves the image failed, not downloading.
 */
class DownloadImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /* a pause before the next slice after a failure, by the number of failures in a row; the fifth fails the image */
    const BACKOFF = [1 => 5, 2 => 15, 3 => 30, 4 => 60];
    const FAILURES = 5;

    public $image;
    public $failures;
    public $tries = 1;
    public $timeout = 80;
    public $failOnTimeout = true;
    public $deleteWhenMissingModels = true;

    public function __construct(Image $image, int $failures = 0)
    {
        $this->image = $image;
        $this->failures = $failures;
        $this->onQueue('images');
    }

    public function handle(ImageFetcher $fetcher, ImageSpace $space)
    {
        $image = Image::find($this->image->id);
        if (!$image || !in_array($image->download_state, ['queued', 'downloading'], true)) {
            return;   // deleted, cancelled or failed meanwhile
        }
        $lock = Cache::lock('image-fetch-'.$image->id, 85);
        if (!$lock->get()) {
            return;   // another slice of this image is running
        }
        try {
            $this->slice($image, $fetcher, $space);
        } finally {
            $lock->release();
        }
    }

    protected function slice(Image $image, ImageFetcher $fetcher, ImageSpace $space): void
    {
        $part = $image->imagepath().'.part';
        $have = is_file($part) ? (int) filesize($part) : 0;
        if ($refusal = $space->refusal(max(0, (int) $image->download_total - $have), false, $image)) {
            $this->fail($image, $refusal);

            return;
        }
        $image->download_state = 'downloading';
        $image->download_bytes = $have;
        $image->download_updated_at = now();
        $image->save();

        $id = $image->id;
        $deadline = microtime(true) + (int) config('cmprovision.image_download_slice');
        try {
            $result = $fetcher->download($image->download_url, $part, (int) $image->download_total, function (int $bytes) use ($id): bool {
                // false once the image is gone or no longer fetching: the page's Delete is how a fetch is cancelled
                return Image::whereKey($id)->where('download_state', 'downloading')->update(['download_bytes' => $bytes, 'download_updated_at' => now()]) > 0;
            }, $deadline);
        } catch (ImageSourceException $e) {
            $this->again(Image::find($id), $this->failures + 1, $e->fatal, $e->getMessage());

            return;
        }

        $fresh = Image::find($id);
        if (!$fresh || $result['cancelled']) {
            @unlink($part);

            return;
        }
        $fresh->download_bytes = $result['bytes'];
        $fresh->download_updated_at = now();
        $fresh->save();

        if ($result['done']) {
            $this->finish($fresh, $part);

            return;
        }
        // a slice that brought no byte is a failure like a broken transfer: five in a row end the chain
        $this->again($fresh, $result['bytes'] > $have ? 0 : $this->failures + 1, false, 'No data arrived in '.self::FAILURES.' slices in a row.');
    }

    /* the file is whole: it gets its name, and the checks take over (hashes, and the published checksums) */
    protected function finish(Image $image, string $part): void
    {
        rename($part, $image->imagepath());
        $image->download_state = 'checking';
        $image->download_bytes = $image->download_total;
        $image->save();
        ComputeSHA256::dispatch($image);
    }

    /* the next slice, after a pause when the last one failed; $failures failures in a row (0: it went well) */
    protected function again(?Image $image, int $failures, bool $fatal, string $message): void
    {
        if (!$image) {
            return;
        }
        if ($fatal || $failures >= self::FAILURES) {
            $this->fail($image, $message);

            return;
        }
        $job = new self($image, $failures);
        if ($failures > 0) {
            $job->delay(self::BACKOFF[$failures]);
        }
        dispatch($job);
    }

    protected function fail(Image $image, string $message): void
    {
        $image->download_state = 'failed';
        $image->download_error = mb_substr($message, 0, 500);
        $image->save();
    }

    /* the worker was killed, the job ran out of time: the image says so instead of staying "downloading" */
    public function failed(\Throwable $e): void
    {
        $image = Image::find($this->image->id ?? null);
        if ($image && in_array($image->download_state, ['queued', 'downloading'], true)) {
            $this->fail($image, 'The fetch stopped: '.$e->getMessage());
        }
    }
}
