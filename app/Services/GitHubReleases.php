<?php

namespace App\Services;

use App\Support\ImageSourceException;
use App\Support\ImageUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The images published as assets of the releases of a GitHub repository (2.8.0): the list (cached five minutes, GitHub allows 60 requests
 * an hour without a token) and the checksum of an asset, by what the release publishes: the digest GitHub itself records, a
 * .sha256 file next to the asset, or a list of sums for the release.
 */
class GitHubReleases
{
    const API = 'https://api.github.com';
    const REPO = '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#';
    const KEEP_SECONDS = 300;
    const LISTED = 20;

    protected $fetcher;

    public function __construct(ImageFetcher $fetcher)
    {
        $this->fetcher = $fetcher;
    }

    /**
     * The releases of a repository, newest first (as GitHub lists them), with the images among their assets.
     *
     * @return array<int, array{tag: string, name: string, published: ?string, prerelease: bool, assets: array}>
     * @throws ImageSourceException
     */
    public function releases(string $repo, bool $prereleases = false): array
    {
        $repo = trim($repo);
        if (!preg_match(self::REPO, $repo) || str_contains($repo, '..')) {
            throw new ImageSourceException('Enter the repository as owner/name, for example the part of a GitHub address after github.com/.', true);
        }
        $all = Cache::get('github-releases:'.strtolower($repo));
        if ($all === null) {
            $all = $this->fetch($repo);
            Cache::put('github-releases:'.strtolower($repo), $all, self::KEEP_SECONDS);
        }
        $list = array_values(array_filter($all, fn ($release) => $prereleases || !$release['prerelease']));

        return array_slice($list, 0, self::LISTED);
    }

    /**
     * The sha256 an asset is published with, and how we know it. The digest and the files are compared when there are several: a
     * release that says two things is not to be believed.
     *
     * @param array $asset one asset of releases()
     * @return array{sha256: ?string, source: string}
     * @throws ImageSourceException
     */
    public function checksum(array $asset): array
    {
        $found = [];
        if (!empty($asset['digest'])) {
            $found['github-digest'] = strtolower($asset['digest']);
        }
        if (!empty($asset['sibling'])) {
            if ($sum = ImageChecksums::parse($this->fetcher->text($asset['sibling']), $asset['name'])) {
                $found['sha256-file'] = $sum;
            }
        }
        if (!empty($asset['sums'])) {
            if ($sum = ImageChecksums::parse($this->fetcher->text($asset['sums']), $asset['name'])) {
                $found['sums-file'] = $sum;
            }
        }
        if (count(array_unique($found)) > 1) {
            throw new ImageSourceException('Published checksums disagree for '.$asset['name'].': the release says more than one thing.', true);
        }

        return $found ? ['sha256' => reset($found), 'source' => key($found)] : ['sha256' => null, 'source' => 'none'];
    }

    protected function fetch(string $repo): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'cmprovision', 'Accept' => 'application/vnd.github+json'])
                ->timeout(20)->get(self::API.'/repos/'.$repo.'/releases', ['per_page' => 30]);
        } catch (ConnectionException $e) {
            throw new ImageSourceException('The server cannot reach api.github.com: check its internet access.');
        }
        if ($response->status() === 404) {
            throw new ImageSourceException('GitHub has no repository '.$repo.' (or it has no releases): check the name.', true);
        }
        if (in_array($response->status(), [403, 429], true) && $response->header('x-ratelimit-remaining') === '0') {
            $at = Carbon::createFromTimestamp((int) $response->header('x-ratelimit-reset'))->local()->format('H:i');
            throw new ImageSourceException('GitHub rate limit reached, try again after '.$at.'.');
        }
        if (!$response->ok()) {
            throw new ImageSourceException('GitHub answered '.$response->status().' for the releases of '.$repo.'.');
        }

        $releases = [];
        foreach ((array) $response->json() as $release) {
            if (!empty($release['draft'])) {
                continue;
            }
            $releases[] = [
                'tag' => (string) ($release['tag_name'] ?? ''),
                'name' => (string) ($release['name'] ?? ''),
                'published' => isset($release['published_at']) ? substr($release['published_at'], 0, 10) : null,
                'prerelease' => !empty($release['prerelease']),
                'assets' => $this->assets($repo, (array) ($release['assets'] ?? [])),
            ];
        }

        return $releases;
    }

    /* the images among the assets of a release, each with the files that can check it */
    protected function assets(string $repo, array $assets): array
    {
        $uploaded = array_values(array_filter($assets, fn ($a) => ($a['state'] ?? '') === 'uploaded' && $this->ours($repo, $a['browser_download_url'] ?? '')));
        $urls = [];
        $sums = null;
        foreach ($uploaded as $a) {
            $urls[$a['name']] = $a['browser_download_url'];
            if ($sums === null && preg_match('/(sha256sums?|checksums?)(\.txt)?$/i', $a['name'])) {
                $sums = $a['browser_download_url'];
            }
        }
        $images = [];
        foreach ($uploaded as $a) {
            $ext = ImageUrlGuard::extensionOf($a['name']);
            if ($ext === null || preg_match('/sbom|source|\.src\./i', $a['name']) || ($ext !== 'zip' && !preg_match('/\.img\.(gz|xz|bz2)$/i', $a['name']))) {
                continue;
            }
            $digest = isset($a['digest']) && preg_match('/^sha256:([0-9a-f]{64})$/i', $a['digest'], $m) ? strtolower($m[1]) : null;
            $sibling = $urls[$a['name'].'.sha256'] ?? null;
            $images[] = [
                'name' => $a['name'],
                'size' => (int) $a['size'],
                'ext' => $ext,
                'url' => $a['browser_download_url'],
                'digest' => $digest,
                'sibling' => $sibling,
                'sums' => $sums,
                'hint' => $digest ? 'github-digest' : ($sibling ? 'sha256-file' : ($sums ? 'sums-file' : 'none')),
            ];
        }

        return $images;
    }

    /* the asset's address is a release download of the repository itself: nothing else the API says is followed */
    protected function ours(string $repo, string $url): bool
    {
        return stripos($url, 'https://github.com/'.$repo.'/releases/download/') === 0;
    }
}
