<?php

namespace App\Http\Livewire;

use App\Models\Image;
use App\Models\Setting;
use App\Services\GitHubReleases;
use App\Services\ImageChecksums;
use App\Services\ImageDownloads;
use App\Services\ImageFetcher;
use App\Services\ImageSpace;
use App\Support\ImageSourceException;
use App\Support\ImageUrlGuard;
use Livewire\Attributes\Locked;
use Livewire\Component;

/* The window "Add from the internet" of the Images page (2.8.0): a source, what it offers, and Download, which queues the fetch.
   The window only asks and checks; the fetching is App\Jobs\DownloadImage. */
class ImageSources extends Component
{
    /* the repository shown first, until the operator has used one of their own */
    const SUGGESTED = 'WLAN-Pi/pi-gen';

    public $tab = 'github';
    public $unverified = false;
    public $error = null;

    // an address
    public $url = '';
    public $filename = '';
    public $sum = '';
    /* what a check of the address found: size, name, whether the server resumes, what it takes; null before and after a refusal */
    public $probe = null;

    // a GitHub repository
    public $repo = '';
    public $prereleases = false;
    public $repos = [];
    /* the releases as GitHub listed them and the repository they are of: only the server sets them (the addresses in them are fetched) */
    #[Locked]
    public $releases = [];
    #[Locked]
    public $listed = '';

    public function mount()
    {
        $this->repos = $this->savedRepos();
    }

    public function updatedTab()
    {
        $this->error = null;
    }

    public function updatedUrl()
    {
        $this->probe = null;
        $this->error = null;
    }

    public function updatedPrereleases(GitHubReleases $github)
    {
        if ($this->listed !== '') {
            $this->loadReleases($github);
        }
    }

    // --- a link

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
        $this->started($filename);
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

    // --- a GitHub repository

    public function loadReleases(GitHubReleases $github)
    {
        $this->error = null;
        $this->releases = [];
        $this->listed = '';
        $repo = trim($this->repo);
        try {
            $this->releases = $github->releases($repo, (bool) $this->prereleases);
            $this->listed = $repo;
            $this->remember($repo);
        } catch (ImageSourceException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function useRepo(string $repo)
    {
        $this->repo = $repo;
        $this->releases = [];
        $this->listed = '';
        $this->error = null;
    }

    public function forgetRepo(string $repo)
    {
        $repos = array_values(array_diff($this->savedRepos(), [$repo]));
        $this->saveRepos($repos);
        $this->repos = $repos;
    }

    /* one image of the list: its checksum is looked up by what the release publishes, then the fetch is queued */
    public function fetchAsset(int $release, int $asset, GitHubReleases $github, ImageDownloads $downloads)
    {
        $this->error = null;
        $chosen = $this->releases[$release]['assets'][$asset] ?? null;
        if (!$chosen) {
            $this->error = 'That image is no longer in the list: show the releases again.';

            return;
        }
        $tag = $this->releases[$release]['tag'];
        try {
            $found = $github->checksum($chosen);
            $downloads->start('github', $chosen['url'], $chosen['name'], ['repo' => $this->listed, 'tag' => $tag, 'asset' => $chosen['name']], $chosen['size'],
                ['sha256' => $found['sha256'], 'uncompressed' => null, 'source' => $found['source']], (bool) $this->unverified);
        } catch (ImageSourceException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->started($chosen['name']);
    }

    /* the repositories used before, newest first, at most ten */
    protected function savedRepos(): array
    {
        $saved = json_decode((string) Setting::find('image_source_repos')?->value, true);

        return is_array($saved) ? array_values(array_filter($saved, 'is_string')) : [];
    }

    protected function saveRepos(array $repos): void
    {
        Setting::updateOrCreate(['key' => 'image_source_repos'], ['value' => json_encode(array_slice($repos, 0, 10))]);
    }

    protected function remember(string $repo): void
    {
        $repos = array_values(array_unique(array_merge([$repo], array_diff($this->savedRepos(), [$repo]))));
        $this->saveRepos($repos);
        $this->repos = array_slice($repos, 0, 10);
    }

    // --- both

    protected function started(string $name): void
    {
        session()->flash('message', 'Fetching '.$name.' started: it appears in the list with its progress.');
        $this->dispatch('image-sources-closed');
    }

    public function close()
    {
        $this->dispatch('image-sources-closed');
    }

    public function render()
    {
        return view('livewire.image-sources', ['suggested' => self::SUGGESTED]);
    }
}
