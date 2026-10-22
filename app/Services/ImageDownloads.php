<?php

namespace App\Services;

use App\Jobs\ComputeSHA256;
use App\Jobs\ConvertImageArchive;
use App\Jobs\DownloadImage;
use App\Models\Image;
use App\Support\ImageSourceException;
use App\Support\ImageUrlGuard;
use Illuminate\Support\Str;

/** The entry of the images from the internet: a request becomes a queued image and a chain of jobs; a failed one can go on; a deleted one is cancelled */
class ImageDownloads
{
    protected $space;

    public function __construct(ImageSpace $space)
    {
        $this->space = $space;
    }

    /**
     * @param string $type       github, raspberrypi or url
     * @param string $url        the address of the file as given (it may carry a token in its query: kept encrypted)
     * @param array  $source     what to show people about where it came from; the address without its query is added
     * @param array  $checksums  sha256 (of the file as published), uncompressed (of the unpacked image), source (how we know them: github-digest, sums-file, sha256-file, catalog, user, none)
     * @throws ImageSourceException when the request cannot be taken (the text says why)
     */
    public function start(string $type, string $url, string $filename, array $source, int $size, array $checksums, bool $unverifiedOk): Image
    {
        if (PHP_INT_MAX === 2147483647) {
            throw new ImageSourceException('Fetching images needs a 64-bit PHP: sizes over 2 GB do not fit in a 32-bit number.', true);
        }
        $filename = trim(preg_replace('/[\x00-\x1f\x7f\/\\\\]+/', '', $filename));
        $extension = ImageUrlGuard::extensionOf($filename);
        if ($extension === null) {
            throw new ImageSourceException('Only .gz, .xz, .bz2 and .zip images are supported.', true);
        }
        $limit = (int) config('cmprovision.image_download_max_gb');
        if ($size > $limit * 1000000000) {
            throw new ImageSourceException('The file ('.Image::size($size).') is larger than the '.$limit.' GB limit.', true);
        }
        if (empty($checksums['sha256']) && empty($checksums['uncompressed']) && !$unverifiedOk) {
            throw new ImageSourceException('No published checksum for this file: tick "Add without a checksum" to take it anyway.', true);
        }
        if ($refusal = $this->space->refusal($size, $extension === 'zip')) {
            throw new ImageSourceException($refusal, true);
        }
        foreach (Image::whereIn('download_state', ImageSpace::IN_FLIGHT)->get() as $other) {
            if ($other->download_url === $url) {
                throw new ImageSourceException('This file is already being fetched.', true);
            }
        }

        $image = new Image;
        $image->filename = $filename;
        $image->filename_extension = $extension;
        do {
            $image->filename_on_server = Str::random(40).'.'.$extension;
        } while (file_exists($image->imagepath()));
        $image->sha256 = '';
        $image->source_type = $type;
        $image->source = json_encode(array_merge($source, ['url' => ImageUrlGuard::redact($url)]), JSON_UNESCAPED_SLASHES);
        $image->download_url = $url;
        $image->download_state = 'queued';
        $image->download_bytes = 0;
        $image->download_total = $size;
        $image->download_started_at = now();
        $image->download_updated_at = now();
        $image->expected_sha256 = $checksums['sha256'] ?? null;
        $image->expected_uncompressed_sha256 = $checksums['uncompressed'] ?? null;
        $image->checksum_source = $checksums['source'] ?? (empty($checksums['sha256']) && empty($checksums['uncompressed']) ? 'none' : 'user');
        $image->save();

        DownloadImage::dispatch($image);

        return $image;
    }

    /* A failed image goes on from what is there: the part, the whole file (repacked or checked again), or nothing (a fetch from the start) */
    public function retry(Image $image): void
    {
        if ($image->download_state !== 'failed') {
            return;
        }
        $image->download_error = null;
        if (is_file($image->imagepath())) {
            $zip = $image->filename_extension === 'zip';
            $image->download_state = $zip ? 'converting' : 'checking';
            $image->download_bytes = $zip ? 0 : $image->download_total;
            $image->save();
            $zip ? ConvertImageArchive::dispatch($image) : ComputeSHA256::dispatch($image);

            return;
        }
        $part = $image->imagepath().'.part';
        $image->download_bytes = is_file($part) ? (int) filesize($part) : 0;
        $image->download_state = 'queued';
        $image->save();
        DownloadImage::dispatch($image);
    }

    /* Whatever the image is doing stops: the running slice sees the record gone, the files go with it */
    public function cancel(Image $image): void
    {
        $image->delete();
    }
}
