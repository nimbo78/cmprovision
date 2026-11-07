<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Image;
use App\Services\ImageVerification;

class ComputeSHA256 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The Image for which the hash should be computed.
     *
     * @var \App\Models\Image
     */
    public $image;

    public $tries = 1;
    public $timeout = 7200;
    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Image $image)
    {
        $this->image = $image;
        $this->onQueue('images');   // minutes of hashing: a worker of its own (cmprovision-queue-images)
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        /* An image fetched from the internet (2.8.0) is checked here, last: it is ready only when what it is is what was published.
           An uploaded image has no source and none of this applies. */
        $fetched = $this->image->source_type !== null && $this->image->download_state === 'checking';

        if (!$this->image->sha256)
        {
            $this->image->sha256 = hash_file("sha256", $this->image->imagepath());
            $this->image->save();
        }
        if ($fetched && $this->image->expected_sha256 && strtolower($this->image->expected_sha256) !== $this->image->sha256)
        {
            return $this->reject('The downloaded file is not the published one: its sha256 is '.$this->image->sha256.', the published one is '.strtolower($this->image->expected_sha256).'.');
        }
        if (!$this->image->uncompressed_sha256)
        {
            try {
                $verified = app(ImageVerification::class)->uncompressed($this->image->imagepath(), $this->image->filename_extension);
            } catch (\RuntimeException $error) {
                if ($fetched) return $this->reject('The image does not unpack (the download is damaged): '.$error->getMessage());
                throw $error;
            }
            $this->image->uncompressed_sha256 = $verified['sha256'];
            $this->image->uncompressed_size = $verified['size'];
            $this->image->save();
        }

        if ($fetched)
        {
            if ($this->image->expected_uncompressed_sha256 && strtolower($this->image->expected_uncompressed_sha256) !== $this->image->uncompressed_sha256)
            {
                return $this->reject('The unpacked image does not match the published checksum: its sha256 is '.$this->image->uncompressed_sha256.', the published one is '.strtolower($this->image->expected_uncompressed_sha256).'.');
            }
            $this->image->download_state = null;
            $this->image->download_error = null;
            $this->image->save();
        }
    }

    /* what was fetched is not what was published: it is deleted, and the image says why (Retry fetches it again from the start) */
    protected function reject(string $why)
    {
        @unlink($this->image->imagepath());
        $this->image->sha256 = '';
        $this->image->uncompressed_sha256 = null;
        $this->image->uncompressed_size = null;
        $this->image->download_state = 'failed';
        $this->image->download_error = $why;
        $this->image->save();
    }

    /* The job has only one try: an error in the checks (a corrupt archive, a full disk, a timeout) must not leave a fetched image "ready" without hashes */
    public function failed(\Throwable $e)
    {
        $image = Image::find($this->image->id ?? null);
        if ($image && $image->source_type !== null && $image->download_state === 'checking')
        {
            $image->download_state = 'failed';
            $image->download_error = 'Checking the image failed: '.mb_substr($e->getMessage(), 0, 300);
            $image->save();
        }
    }
}
