<?php

namespace App\Http\Livewire;

use App\Models\Image;
use App\Services\ImageChecksums;
use App\Services\ImageDownloads;
use App\Services\ImageFetcher;
use App\Services\ImageSpace;
use App\Support\ImageSourceException;
use App\Support\ImageUrlGuard;
use Livewire\Component;

/* The window "Add from the internet" of the Images page (2.8.0): a source, what it offers, and Download, which queues the fetch.
   The window only asks and checks; the fetching is App\Jobs\DownloadImage. */
class ImageSources extends Component
{
    public $url = '';
    public $filename = '';
    public $sum = '';
    public $unverified = false;
    /* what a check of the address found: size, name, whether the server resumes, what it takes; null before and after a refusal */
    public $probe = null;
    public $error = null;

    public function updatedUrl()
    {
        $this->probe = null;
        $this->error = null;
    }

    /* what is behind the address: its size, its name, and whether it fits on the disk */
    public function check(ImageFetcher $fetcher, ImageSpace $space)
    {
        $this->probe = null;
        $this->error = null;
        $url = trim($this->url);
        try {
            if ($url === '') {
                throw new ImageSourceException('Paste the address of the image.');
            }
            $info = $fetcher->probe($url);
            $name = $info['name'];
            $zip = $name !== null && ImageUrlGuard::extensionOf($name) === 'zip';
            if ($name !== null && ImageUrlGuard::extensionOf($name) !== null) {
                $this->filename = $name;
            }
            if ($refusal = $space->refusal($info['size'], $zip)) {
                $this->error = $refusal;

                return;
            }
            $this->probe = [
                'size' => $info['size'],
                'name' => $name,
                'ranges' => $info['ranges'],
                'room' => 'Needs about '.Image::size($space->need($info['size'], $zip)).', '.Image::size($space->free()).' free.',
            ];
        } catch (ImageSourceException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function download(ImageFetcher $fetcher, ImageDownloads $downloads)
    {
        $this->error = null;
        if (!$this->probe) {
            $this->check($fetcher, app(ImageSpace::class));
            if (!$this->probe) {
                return;
            }
        }
        $filename = trim($this->filename);
        try {
            $checksums = $this->checksums($fetcher, $filename);
            $host = parse_url(trim($this->url), PHP_URL_HOST);
            $downloads->start('url', trim($this->url), $filename, ['host' => $host], (int) $this->probe['size'], $checksums, (bool) $this->unverified);
        } catch (ImageSourceException $e) {
            $this->error = $e->getMessage();

            return;
        }
        session()->flash('message', 'Fetching '.$filename.' started: it appears in the list with its progress.');
        $this->dispatch('image-sources-closed');
    }

    /* the checksum the operator gave: a sha256, or the address of a file that lists it; none is for the operator to accept */
    protected function checksums(ImageFetcher $fetcher, string $filename): array
    {
        $sum = trim($this->sum);
        if ($sum === '') {
            return ['sha256' => null, 'uncompressed' => null, 'source' => 'none'];
        }
        if (ImageChecksums::isSum($sum)) {
            return ['sha256' => ImageChecksums::normalize($sum), 'uncompressed' => null, 'source' => 'user'];
        }
        if (!preg_match('#^https://#i', $sum)) {
            throw new ImageSourceException('The checksum must be 64 hex digits or the address of a checksum file (https://...).');
        }
        $found = ImageChecksums::parse($fetcher->text($sum), $filename);
        if ($found === null) {
            throw new ImageSourceException('That checksum file has no checksum for '.$filename.'.');
        }

        return ['sha256' => $found, 'uncompressed' => null, 'source' => 'sha256-file'];
    }

    public function close()
    {
        $this->dispatch('image-sources-closed');
    }

    public function render()
    {
        return view('livewire.image-sources');
    }
}
