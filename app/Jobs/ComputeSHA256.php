<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Image;

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
            switch ($this->image->filename_extension)
            {
                case 'gz':
                    $cmd = "gzip -dc";
                    break;
                case 'xz':
                    $cmd = "xz -dc";
                    break;
                case 'bz2':
                    $cmd = "bunzip2 -dc";
                    break;
                default:
                    throw new \Exception("Unsupported image file extension");
            }

            /* We want to know both the sha256 of uncompressed image and uncompressed size.
               In absense of a standard function that gives us both, 
               just use tee to pipe the output of the decompressor to both sha256sum
               and wc */
            $cmd .= " ".escapeshellarg($this->image->imagepath())." | tee >(sha256sum>&2) | LC_ALL=C wc --bytes";
            $cmd = 'bash -c "'.$cmd.'"';
            $output = null;
            $ret = null;

            $desc = [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"], /* wc */
                2 => ["pipe", "w"]  /* sha256sum */
            ];
            $pipes = null;

            $proc = proc_open($cmd, $desc, $pipes);
            if (!$proc)
                throw new \Exception("Error starting decompressor/sha256sum/wc");
            
            fclose($pipes[0]);
            $sha256sum = stream_get_contents($pipes[2]);
            $wc = trim(stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $ret = proc_close($proc);

            /* Output of sha256sum is like: "002bc976b7f95f3803b6d0b85c022911840a175268d5d2145c2b0a358892afb9  -" */
            if ($ret == 0 && preg_match("/^([a-z0-9]+)  /", $sha256sum, $r))
            {
                $this->image->uncompressed_sha256 = $r[1];
                $this->image->uncompressed_size = intval($wc);
                $this->image->save();
            }
            elseif ($fetched)
            {
                return $this->reject('The image does not unpack (the download is damaged): '.mb_substr(trim($sha256sum), 0, 200));
            }
            else
            {
                throw new \Exception("Error decompressing/calculating sha256. Ret code=$ret sha256sum=$sha256sum wc=$wc");
            }

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
