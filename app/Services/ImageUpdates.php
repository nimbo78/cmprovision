<?php
namespace App\Services;

use App\Models\Image;
use App\Support\ImageSourceException;
use Illuminate\Support\Facades\Cache;

/** Explicit advisory checks. No download, project mutation, activation or tag-version guesses. */
class ImageUpdates
{
    public function __construct(private GitHubReleases $github) {}

    private function key(Image $image): string
    {
        return 'image-update:'.hash('sha256', $image->id.'|'.$image->source_type.'|'.$image->source.'|'.$image->filename);
    }
    public function peek(Image $image): ?array { return Cache::get($this->key($image)); }

    private function timestamp(array $release): ?int
    {
        $value = $release['published_at'] ?? $release['published'] ?? null;
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))?$/D', $value)) return null;
        try { return \Illuminate\Support\Carbon::parse($value, 'UTC')->timestamp; }
        catch (\Throwable $invalid) { return null; }
    }

    /** Preserve variant/architecture even when it happens to be inside a release tag. */
    private function family(string $asset, string $tag): string
    {
        $name = strtolower($asset);
        preg_match_all('/(?:^|[-_.])(arm64|aarch64|armhf|armv[5-8][a-z0-9]*|amd64|x86_64|x64|i[3-6]86|32bit|64bit|lite|full|desktop|server|legacy|bookworm|trixie|bullseye|cm[345]|m4plus)(?=[-_.]|$)/', $name, $markers);
        $markers = array_unique($markers[1]); sort($markers);
        $name = preg_replace('/(?:\.img\.(?:gz|xz|bz2)|\.zip)$/', '.img', $name);
        // Textual tag suffixes can be unknown hardware/OS variants. Keep them in the name.
        $versions = [];
        if (preg_match('/^v?(\d+(?:\.\d+){1,3})(?:[-_]|$)/i', $tag, $numeric)) {
            $versions[] = $numeric[1]; $versions[] = 'v'.$numeric[1];
        }
        foreach (array_unique($versions) as $version) {
            if (strlen($version) < 2) continue;
            $name = preg_replace('/(?<![a-z0-9])'.preg_quote(strtolower($version), '/').'(?![a-z0-9])/', '-', $name);
        }
        $name = preg_replace('/(?<!\d)20\d{2}[-_.]\d{2}[-_.]\d{2}(?!\d)/', '-', $name);
        return implode(',', $markers).':'.trim(preg_replace('/[-_.]+/', '-', $name), '-');
    }

    public function check(Image $image): array
    {
        $result = ['state'=>'unsupported', 'message'=>'Checks are available for images added from GitHub releases.',
            'checked_at'=>now()->timestamp, 'candidate'=>null];
        $source = json_decode((string) $image->source, true);
        if ($image->source_type === 'github' && is_array($source)) {
            $repo = $source['repo'] ?? null; $tag = $source['tag'] ?? null; $asset = $source['asset'] ?? null;
            if (!is_string($repo) || !preg_match(GitHubReleases::REPO, $repo) || str_contains($repo, '..')
                || !is_string($tag) || !is_string($asset) || $tag === '' || $asset === '') {
                $result['state'] = 'unknown'; $result['message'] = 'The recorded release or asset is incomplete.';
            } else {
                try { $result = array_merge($result, $this->compare($repo, $tag, $asset)); }
                catch (ImageSourceException $error) {
                    $result['state'] = 'error'; $result['message'] = mb_substr($error->getMessage(), 0, 500);
                }
                catch (\Throwable $unexpected) {
                    $result['state'] = 'error'; $result['message'] = 'The release source returned an unexpected response. Retry or review its releases manually.';
                }
            }
        }
        Cache::put($this->key($image), $result, 86400);
        return $result;
    }

    private function compare(string $repo, string $tag, string $asset): array
    {
        $releases = $this->github->releases($repo);
        $current = collect($releases)->firstWhere('tag', $tag);
        if (!$current || ($published = $this->timestamp($current)) === null
            || !collect($current['assets'])->contains('name', $asset))
            return ['state'=>'unknown', 'message'=>'The current release/asset or its publication date is not in the recent stable list; no update can be inferred.'];
        $family = $this->family($asset, $tag);
        $newer = array_filter($releases, fn ($release) => $release['tag'] !== $tag
            && ($this->timestamp($release) ?? 0) > $published);
        usort($newer, fn ($a, $b) => $this->timestamp($b) <=> $this->timestamp($a));
        foreach ($newer as $release) {
            $matches = array_values(array_filter($release['assets'], fn ($candidate) => $this->family($candidate['name'], $release['tag']) === $family));
            if (count($matches) > 1) {
                $extension = pathinfo($asset, PATHINFO_EXTENSION);
                $matches = array_values(array_filter($matches, fn ($candidate) => pathinfo($candidate['name'], PATHINFO_EXTENSION) === $extension));
            }
            if (count($matches) > 1) return ['state'=>'unknown', 'message'=>'The newer release has several matching assets. Review its variants manually.'];
            if (count($matches) === 1) return ['state'=>'available', 'message'=>'Newer matching image available. Review compatibility and checksums before using it.',
                'candidate'=>['tag'=>$release['tag'], 'asset'=>$matches[0]['name'],
                    'url'=>'https://github.com/'.$repo.'/releases/tag/'.rawurlencode($release['tag'])]];
        }
        return ['state'=>'current', 'message'=>'No newer matching image in the recent stable releases.'];
    }
}
