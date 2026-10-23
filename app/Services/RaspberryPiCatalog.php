<?php

namespace App\Services;

use App\Support\ImageSourceException;
use App\Support\ImageUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Raspberry Pi OS from the official list the Raspberry Pi Imager uses (2.8.0). The list gives, for every image, where it is, how large it
 * is and the sha256 of the unpacked image; next to the download lies a .sha256 file of the download itself.
 */
class RaspberryPiCatalog
{
    const URL = 'https://downloads.raspberrypi.com/os_list_imagingutility_v4.json';
    const HOST = 'downloads.raspberrypi.com';
    const KEEP_SECONDS = 3600;
    const MAX_BYTES = 5242880;

    protected $fetcher;

    public function __construct(ImageFetcher $fetcher)
    {
        $this->fetcher = $fetcher;
    }

    /**
     * The Raspberry Pi OS images of the list (the other systems of the Imager's list have sources of their own): 64 and 32 bit, Lite and Full, legacy.
     *
     * @return array<int, array{name: string, url: string, filename: string, size: int, ext: string, date: ?string, uncompressed: string, uncompressed_size: int}>
     * @throws ImageSourceException
     */
    public function variants(): array
    {
        $variants = Cache::get('raspberrypi-catalog');
        if ($variants === null) {
            $variants = $this->fetch();
            Cache::put('raspberrypi-catalog', $variants, self::KEEP_SECONDS);
        }

        return $variants;
    }

    /**
     * The checksums of a variant: the one of the unpacked image from the list, and the one of the download from the file next to it.
     * A download without that file (404) is still checked by the unpacked checksum.
     *
     * @return array{sha256: ?string, uncompressed: string, source: string}
     * @throws ImageSourceException for any other failure to read the file
     */
    public function checksums(array $variant): array
    {
        $sha256 = null;
        try {
            $sha256 = ImageChecksums::parse($this->fetcher->text($variant['url'].'.sha256'), $variant['filename']);
        } catch (ImageSourceException $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }

        return ['sha256' => $sha256, 'uncompressed' => $variant['uncompressed'], 'source' => 'catalog'];
    }

    protected function fetch(): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'cmprovision'])->timeout(30)->get(self::URL);
        } catch (ConnectionException $e) {
            throw new ImageSourceException('The server cannot reach '.self::HOST.': check its internet access.');
        }
        if (!$response->ok()) {
            throw new ImageSourceException(self::HOST.' answered '.$response->status().' for the list of Raspberry Pi OS images.');
        }
        if (strlen($response->body()) > self::MAX_BYTES) {
            throw new ImageSourceException('The list of Raspberry Pi OS images from '.self::HOST.' is larger than expected: not used.', true);
        }
        $list = $response->json();
        if (!is_array($list) || !isset($list['os_list']) || !is_array($list['os_list'])) {
            throw new ImageSourceException('The list of Raspberry Pi OS images from '.self::HOST.' is not in the expected form.', true);
        }

        $variants = [];
        foreach ($list['os_list'] as $entry) {
            if (is_array($entry) && str_starts_with((string) ($entry['name'] ?? ''), 'Raspberry Pi OS')) {
                $this->collect($entry, $variants);
            }
        }

        return array_values($variants);
    }

    /* an entry is an image, or a group of entries */
    protected function collect(array $entry, array &$variants): void
    {
        foreach ((array) ($entry['subitems'] ?? []) as $sub) {
            if (is_array($sub)) {
                $this->collect($sub, $variants);
            }
        }
        $name = (string) ($entry['name'] ?? '');
        $url = (string) ($entry['url'] ?? '');
        $ext = ImageUrlGuard::extensionOf($url);
        // an image of the Raspberry Pi OS family, on the Raspberry Pi download server, with the checksum of the unpacked image
        if ($name === '' || $ext === null || $ext === 'zip' || isset($variants[$name]) || parse_url($url, PHP_URL_HOST) !== self::HOST
            || !preg_match('/^[0-9a-f]{64}$/i', (string) ($entry['extract_sha256'] ?? '')) || empty($entry['image_download_size'])) {
            return;
        }
        $variants[$name] = [
            'name' => $name,
            'url' => $url,
            'filename' => rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))),
            'size' => (int) $entry['image_download_size'],
            'ext' => $ext,
            'date' => $entry['release_date'] ?? null,
            'uncompressed' => strtolower($entry['extract_sha256']),
            'uncompressed_size' => (int) ($entry['extract_size'] ?? 0),
        ];
    }
}
