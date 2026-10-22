<?php

namespace App\Jobs;

use App\Models\Image;
use App\Services\ImageSpace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/*
 * A .zip image (the old releases of WLAN Pi OS, many images of other systems) repacked into an .img.gz (2.8.0): a module writes an image
 * by streaming it through a decompressor, which a zip cannot be. The image in the archive is read as a stream and goes straight into
 * gzip, so no unpacked copy ever lies on the disk (the room needed is the archive and, besides it, about as much for the .gz). The
 * archive's own checksum is checked first and the entry's CRC32 after; the new file is then checked like any other by ComputeSHA256,
 * which also proves that it unpacks. Only then is the archive deleted.
 */
class ConvertImageArchive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /* how often the progress goes to the database, seconds; and a hook for the tests, called after each write */
    public static $progressEvery = 2.0;
    public static $onProgress = null;

    public $image;
    public $tries = 1;
    public $timeout = 7200;
    public $failOnTimeout = true;
    public $deleteWhenMissingModels = true;

    public function __construct(Image $image)
    {
        $this->image = $image;
        $this->onQueue('images');   // minutes of processor work: the worker of the image checksums, like ComputeSHA256 and SplitImage
    }

    public function handle(ImageSpace $space)
    {
        $image = Image::find($this->image->id);
        if (!$image || $image->download_state !== 'converting' || $image->filename_extension !== 'zip') {
            return;
        }
        $archive = $image->imagepath();
        if (!is_file($archive)) {
            $this->fail($image, 'The archive is missing from the disk.');

            return;
        }
        if ($image->expected_sha256 && strtolower($image->expected_sha256) !== ($sum = hash_file('sha256', $archive))) {
            @unlink($archive);
            $this->fail($image, 'The downloaded archive is not the published one: its sha256 is '.$sum.', the published one is '.strtolower($image->expected_sha256).'.');

            return;
        }

        $zip = new \ZipArchive;
        if ($zip->open($archive, \ZipArchive::RDONLY) !== true) {
            @unlink($archive);   // what is no archive is not kept: Retry fetches it again
            $this->fail($image, 'This is not a readable zip archive.');

            return;
        }
        try {
            $this->repack($image, $zip, $space);
        } finally {
            $zip->close();
        }
    }

    protected function repack(Image $image, \ZipArchive $zip, ImageSpace $space): void
    {
        $found = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) $stat['name'];
            if (substr($name, -1) !== '/' && !str_starts_with($name, '__MACOSX/') && preg_match('/\.img$/i', $name)) {
                $found[] = $stat;
            }
        }
        if (count($found) !== 1) {
            $this->fail($image, 'The archive must hold exactly one .img file (found: '.($found ? implode(', ', array_column($found, 'name')) : 'none').').');

            return;
        }
        $entry = $found[0];
        $archive = $image->imagepath();
        if ($refusal = $space->refusal((int) ceil(filesize($archive) * ImageSpace::ZIP_FACTOR), false, $image)) {
            $this->fail($image, $refusal);

            return;
        }

        $part = $archive.'.gz.part';
        try {
            $outcome = $this->stream($image, $zip, $entry, $part);
        } catch (\Throwable $e) {
            @unlink($part);
            $this->fail($image, 'Repacking the archive failed: '.mb_substr($e->getMessage(), 0, 300));

            return;
        }
        if ($outcome !== true) {
            @unlink($part);
            if ($outcome !== null) {
                @unlink($archive);   // a damaged archive is not kept: Retry fetches it again
                $this->fail($image, $outcome);   // a reason; null is a cancel: the image is gone
            }

            return;
        }

        // the new file takes the archive's place
        do {
            $name = Str::random(40).'.gz';
        } while (file_exists(public_path('uploads/'.$name)));
        rename($part, public_path('uploads/'.$name));
        @unlink($archive);
        $image->filename_on_server = $name;
        $image->filename_extension = 'gz';
        $image->filename = preg_match('/(\.img)?\.zip$/i', $image->filename) ? preg_replace('/(\.img)?\.zip$/i', '.img.gz', $image->filename) : $image->filename.'.img.gz';
        $image->sha256 = '';
        $image->uncompressed_sha256 = null;
        $image->uncompressed_size = null;
        $image->expected_sha256 = null;   // the archive's sum is spent; ComputeSHA256 checks the new file by its own hashes
        $image->download_state = 'checking';
        $image->download_total = $image->download_bytes = (int) filesize($image->imagepath());
        $image->save();
        ComputeSHA256::dispatch($image);
    }

    /**
     * The image of the archive, through gzip, into $part. True when it is whole and its CRC32 is the archive's; a reason in words when
     * it is not; null when the image was deleted meanwhile.
     */
    protected function stream(Image $image, \ZipArchive $zip, array $entry, string $part)
    {
        $source = $zip->getStream($entry['name']);
        if ($source === false) {
            return 'The image in the archive cannot be read.';
        }
        $process = proc_open(['nice', '-n', '10', 'gzip', '-6', '-n', '-c'], [0 => ['pipe', 'r'], 1 => ['file', $part, 'wb'], 2 => ['pipe', 'w']], $pipes);
        if (!$process) {
            throw new \RuntimeException('gzip could not be started');
        }
        $crc = hash_init('crc32b');
        $read = 0;
        $last = 0.0;
        $total = max(1, (int) $entry['size']);
        $id = $image->id;
        try {
            while (!feof($source)) {
                $chunk = fread($source, 1048576);
                if ($chunk === false) {
                    throw new \RuntimeException('the archive could not be read to its end');
                }
                if ($chunk === '') {
                    continue;
                }
                $read += strlen($chunk);
                hash_update($crc, $chunk);
                for ($at = 0, $length = strlen($chunk); $at < $length;) {
                    $written = fwrite($pipes[0], substr($chunk, $at));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('gzip stopped: '.trim((string) stream_get_contents($pipes[2])));
                    }
                    $at += $written;
                }
                $now = microtime(true);
                if ($now - $last >= self::$progressEvery) {
                    $last = $now;
                    $shown = (int) floor($read / $total * (int) $image->download_total);
                    if (Image::whereKey($id)->where('download_state', 'converting')->update(['download_bytes' => $shown, 'download_updated_at' => now()]) === 0) {
                        proc_terminate($process);

                        return null;
                    }
                    if (self::$onProgress) {
                        call_user_func(self::$onProgress, $shown, (int) $image->download_total);
                    }
                }
            }
        } finally {
            fclose($source);
            @fclose($pipes[0]);
        }
        $error = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new \RuntimeException('gzip failed ('.$code.'): '.$error);
        }
        if ($read !== (int) $entry['size']) {
            return 'The image in the archive is shorter than the archive says: it is damaged.';
        }
        if (hash_final($crc) !== sprintf('%08x', $entry['crc'])) {
            return 'The image in the archive does not match its checksum: the archive is damaged.';
        }

        return true;
    }

    protected function fail(Image $image, string $message): void
    {
        $image->download_state = 'failed';
        $image->download_error = mb_substr($message, 0, 500);
        $image->save();
    }

    /* the worker was killed or the job ran out of time */
    public function failed(\Throwable $e): void
    {
        $image = Image::find($this->image->id ?? null);
        if ($image && $image->download_state === 'converting') {
            @unlink($image->imagepath().'.gz.part');
            $this->fail($image, 'The repacking stopped: '.$e->getMessage());
        }
    }
}
