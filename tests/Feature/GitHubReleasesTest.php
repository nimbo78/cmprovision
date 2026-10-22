<?php

namespace Tests\Feature;

use App\Http\Livewire\ImageSources;
use App\Jobs\DownloadImage;
use App\Models\Image;
use App\Models\Setting;
use App\Models\User;
use App\Services\GitHubReleases;
use App\Services\ImageFetcher;
use App\Services\ImageSpace;
use App\Support\ImageSourceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeImageFetcher;
use Tests\TestCase;

/** Images published as GitHub release assets: the list of releases, how each file is checked, and Download from the window */
class GitHubReleasesTest extends TestCase
{
    use RefreshDatabase;

    const DIGEST = '04c4cd5c63b63f484c97d00fbc1bb39ce27c8f9e47bdf88e37f9a02520312f8b';
    const GB = 1000000000;

    protected $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->fixture();
        $this->fetcher = new FakeImageFetcher;
        $this->app->instance(ImageFetcher::class, $this->fetcher);
        $this->app->instance(ImageSpace::class, new ImageSpace(fn () => 100 * self::GB));
        $this->actingAs(User::factory()->create());
    }

    protected function fixture(array $over = [])
    {
        Http::fake(array_merge(['api.github.com/repos/WLAN-Pi/pi-gen/releases*' => Http::response(file_get_contents(__DIR__.'/../fixtures/github/pi-gen-releases.json'), 200)], $over));
    }

    protected function releases(): GitHubReleases
    {
        return app(GitHubReleases::class);
    }

    // --- the list

    public function test_the_releases_come_newest_first_without_drafts_and_prereleases_unless_asked()
    {
        $this->assertSame(['26.10-DeadEye', 'v3.4.4', 'v3.3.0'], array_column($this->releases()->releases('WLAN-Pi/pi-gen'), 'tag'));
        $this->assertSame(['26.10-DeadEye', '26.11-RC1', 'v3.4.4', 'v3.3.0'], array_column($this->releases()->releases('WLAN-Pi/pi-gen', true), 'tag'), 'GitHub lists them newest first: so do we, as it answers');
    }

    public function test_only_images_are_listed_with_what_the_page_needs_to_know_of_each()
    {
        $releases = $this->releases()->releases('WLAN-Pi/pi-gen');

        $deadEye = $releases[0];
        $this->assertSame('2026-10-06', $deadEye['published']);
        $this->assertSame(['wlanpi-os-26.10-DeadEye-full.img.gz'], array_column($deadEye['assets'], 'name'), '.sha256, .info, .sbom.xz and the checksum list are no images');
        $asset = $deadEye['assets'][0];
        $this->assertSame([1644477876, 'gz', self::DIGEST, 'github-digest'], [$asset['size'], $asset['ext'], $asset['digest'], $asset['hint']]);
        $this->assertSame('https://github.com/WLAN-Pi/pi-gen/releases/download/26.10-DeadEye/wlanpi-os-26.10-DeadEye-full.img.gz', $asset['url']);

        $zip = $releases[1]['assets'][0];
        $this->assertSame(['wlanpi-os-v3.4.4-2026-02-12.zip', 'zip', 1234009803, 'github-digest'], [$zip['name'], $zip['ext'], $zip['size'], $zip['hint']]);
        $this->assertNotNull($zip['sibling'], 'and a .sha256 next to it, which the digest does not need');
        $this->assertSame('sha256-file', $releases[2]['assets'][0]['hint'], 'a release of 2025 has no digest, only the file');
    }

    public function test_the_hint_names_what_is_there_to_check_with()
    {
        $this->fixture(['api.github.com/repos/o/r/releases*' => Http::response([
            ['tag_name' => 'a', 'name' => 'a', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-01-01T00:00:00Z', 'assets' => [
                ['name' => 'os.img.xz', 'size' => 5, 'state' => 'uploaded', 'digest' => null, 'browser_download_url' => 'https://github.com/o/r/releases/download/a/os.img.xz'],
                ['name' => 'os-SHA256SUMS', 'size' => 5, 'state' => 'uploaded', 'digest' => null, 'browser_download_url' => 'https://github.com/o/r/releases/download/a/os-SHA256SUMS'],
            ]],
            ['tag_name' => 'b', 'name' => 'b', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-01-02T00:00:00Z', 'assets' => [
                ['name' => 'bare.img.bz2', 'size' => 5, 'state' => 'uploaded', 'digest' => null, 'browser_download_url' => 'https://github.com/o/r/releases/download/b/bare.img.bz2'],
                ['name' => 'half.img.gz', 'size' => 5, 'state' => 'starter', 'digest' => null, 'browser_download_url' => 'https://github.com/o/r/releases/download/b/half.img.gz'],
            ]],
        ], 200)]);

        $releases = $this->releases()->releases('o/r');

        $this->assertSame('sums-file', $releases[0]['assets'][0]['hint']);
        $this->assertSame('none', $releases[1]['assets'][0]['hint']);
        $this->assertCount(1, $releases[1]['assets'], 'a file that is still being uploaded is not offered');
    }

    public function test_the_answer_is_kept_for_five_minutes()
    {
        $this->releases()->releases('WLAN-Pi/pi-gen');
        $this->releases()->releases('wlan-pi/PI-GEN', true);

        Http::assertSentCount(1);
    }

    public function test_a_repository_is_written_as_owner_and_name_and_nothing_else_is_asked()
    {
        foreach (['', 'WLAN-Pi', 'a/b/c', 'a b/c', '../etc/passwd', 'https://github.com/a/b', 'a/b?x=1', 'a/..'] as $bad) {
            try {
                $this->releases()->releases($bad);
                $this->fail("'$bad' is no repository");
            } catch (ImageSourceException $e) {
                $this->assertStringContainsString('owner/name', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_a_missing_repository_a_rate_limit_and_no_network_each_say_so()
    {
        $this->fixture(['api.github.com/repos/no/such/releases*' => Http::response(['message' => 'Not Found'], 404)]);
        try {
            $this->releases()->releases('no/such');
            $this->fail();
        } catch (ImageSourceException $e) {
            $this->assertStringContainsString('no repository no/such', $e->getMessage());
        }

        $reset = strtotime('2026-10-22 21:30:00 UTC');
        $this->fixture(['api.github.com/repos/busy/repo/releases*' => Http::response(['message' => 'API rate limit exceeded'], 403, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) $reset])]);
        try {
            $this->releases()->releases('busy/repo');
            $this->fail();
        } catch (ImageSourceException $e) {
            $this->assertStringContainsString('GitHub rate limit reached, try again after ', $e->getMessage());
            $this->assertMatchesRegularExpression('/after \d\d:\d\d\./', $e->getMessage());
        }

        $this->fixture(['api.github.com/repos/down/repo/releases*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6')]);
        try {
            $this->releases()->releases('down/repo');
            $this->fail();
        } catch (ImageSourceException $e) {
            $this->assertStringContainsString('api.github.com', $e->getMessage());
            $this->assertFalse($e->fatal);
        }
        $this->fixture(['api.github.com/repos/err/repo/releases*' => Http::response('', 500)]);
        $this->expectException(ImageSourceException::class);
        $this->releases()->releases('err/repo');
    }

    public function test_a_failed_answer_is_not_kept()
    {
        $this->fixture(['api.github.com/repos/err/repo/releases*' => Http::sequence()->push('', 500)->push('[]', 200)]);
        try {
            $this->releases()->releases('err/repo');
        } catch (ImageSourceException $e) {
        }

        $this->assertSame([], $this->releases()->releases('err/repo'));
    }

    // --- the checksum of an asset

    protected function asset(string $name, array $over = []): array
    {
        return array_merge(['name' => $name, 'size' => 1000, 'ext' => 'gz', 'url' => 'https://github.com/o/r/releases/download/t/'.$name, 'digest' => null, 'sibling' => null, 'sums' => null, 'hint' => 'none'], $over);
    }

    public function test_the_digest_is_enough_by_itself()
    {
        $found = $this->releases()->checksum($this->asset('os.img.gz', ['digest' => self::DIGEST]));

        $this->assertSame(['sha256' => self::DIGEST, 'source' => 'github-digest'], $found);
        $this->assertSame([], $this->fetcher->texts);
    }

    public function test_the_file_next_to_the_asset_and_the_list_of_the_release_are_read_through_the_guarded_fetcher()
    {
        $this->fetcher->textAnswer = self::DIGEST."  os.img.gz\n";
        $found = $this->releases()->checksum($this->asset('os.img.gz', ['sibling' => 'https://github.com/o/r/releases/download/t/os.img.gz.sha256']));
        $this->assertSame(['sha256' => self::DIGEST, 'source' => 'sha256-file'], $found);
        $this->assertSame(['https://github.com/o/r/releases/download/t/os.img.gz.sha256'], $this->fetcher->texts);

        $this->fetcher->textAnswer = str_repeat('a', 64)."  other.img.gz\n".self::DIGEST."  os.img.gz\n";
        $found = $this->releases()->checksum($this->asset('os.img.gz', ['sums' => 'https://github.com/o/r/releases/download/t/SHA256SUMS']));
        $this->assertSame(['sha256' => self::DIGEST, 'source' => 'sums-file'], $found);
    }

    public function test_published_checksums_that_disagree_stop_the_download_and_agreeing_ones_do_not()
    {
        $this->fetcher->textAnswer = str_repeat('a', 64)."  os.img.gz\n";
        try {
            $this->releases()->checksum($this->asset('os.img.gz', ['digest' => self::DIGEST, 'sibling' => 'https://github.com/o/r/releases/download/t/os.img.gz.sha256']));
            $this->fail('two different sums');
        } catch (ImageSourceException $e) {
            $this->assertStringContainsString('Published checksums disagree', $e->getMessage());
            $this->assertTrue($e->fatal);
        }

        $this->fetcher->textAnswer = strtoupper(self::DIGEST)."  os.img.gz\n";
        $found = $this->releases()->checksum($this->asset('os.img.gz', ['digest' => self::DIGEST, 'sibling' => 'https://github.com/o/r/releases/download/t/os.img.gz.sha256']));
        $this->assertSame('github-digest', $found['source']);
    }

    public function test_an_asset_with_nothing_published_has_no_sum_and_an_unreadable_file_is_not_silently_ignored()
    {
        $this->assertSame(['sha256' => null, 'source' => 'none'], $this->releases()->checksum($this->asset('os.img.gz')));

        $this->fetcher->textAnswer = new ImageSourceException('The server answered 404 (not found) for https://github.com/x.', true);
        $this->expectException(ImageSourceException::class);
        $this->releases()->checksum($this->asset('os.img.gz', ['sibling' => 'https://github.com/o/r/releases/download/t/os.img.gz.sha256']));
    }

    public function test_a_list_that_has_no_line_for_the_asset_counts_as_not_published()
    {
        $this->fetcher->textAnswer = str_repeat('a', 64)."  another.img.gz\n";

        $this->assertSame(['sha256' => null, 'source' => 'none'], $this->releases()->checksum($this->asset('os.img.gz', ['sums' => 'https://github.com/o/r/releases/download/t/SHA256SUMS'])));
    }

    // --- the window

    protected function window()
    {
        return Livewire::test(ImageSources::class)->set('tab', 'github');
    }

    public function test_the_window_lists_releases_and_their_images_and_remembers_the_repository()
    {
        $this->window()->set('repo', 'WLAN-Pi/pi-gen')->call('loadReleases')
            ->assertSet('error', null)
            ->assertSee('26.10-DeadEye')
            ->assertSee('wlanpi-os-26.10-DeadEye-full.img.gz')
            ->assertSee('1.6 GB')
            ->assertSee('GitHub digest')
            ->assertSee('wlanpi-os-v3.4.4-2026-02-12.zip')
            ->assertSee('repacked')
            ->assertDontSee('wlanpi-os-26.10-DeadEye-full.sbom.xz')
            ->assertDontSee('26.11-RC1')
            ->set('prereleases', true)->assertSee('26.11-RC1');

        $this->assertSame(['WLAN-Pi/pi-gen'], json_decode(Setting::find('image_source_repos')->value, true));
    }

    public function test_the_repositories_used_before_are_offered_and_can_be_forgotten_and_the_first_time_pi_gen_is_suggested()
    {
        $this->window()->assertSee('WLAN-Pi/pi-gen');

        Setting::create(['key' => 'image_source_repos', 'value' => json_encode(['a/b', 'c/d'])]);
        $this->window()->assertSee('a/b')->assertSee('c/d')->assertDontSee('WLAN-Pi/pi-gen')
            ->call('useRepo', 'c/d')->assertSet('repo', 'c/d')
            ->call('forgetRepo', 'a/b')->assertDontSee('a/b');
        $this->assertSame(['c/d'], json_decode(Setting::find('image_source_repos')->value, true));
    }

    public function test_an_error_of_github_is_shown_in_the_window()
    {
        $this->fixture(['api.github.com/repos/no/such/releases*' => Http::response([], 404)]);

        $this->window()->set('repo', 'no/such')->call('loadReleases')->assertSee('no repository no/such')->assertSet('releases', []);
        $this->window()->set('repo', 'nonsense')->call('loadReleases')->assertSee('owner/name');
    }

    public function test_download_queues_the_asset_with_its_digest_and_closes_the_window()
    {
        Queue::fake();

        $this->window()->set('repo', 'WLAN-Pi/pi-gen')->call('loadReleases')->call('fetchAsset', 0, 0)
            ->assertSet('error', null)->assertDispatched('image-sources-closed');

        $image = Image::first();
        $this->assertSame(['github', 'wlanpi-os-26.10-DeadEye-full.img.gz', 'gz', 'queued', 1644477876, self::DIGEST, 'github-digest'],
            [$image->source_type, $image->filename, $image->filename_extension, $image->download_state, $image->download_total, $image->expected_sha256, $image->checksum_source]);
        $this->assertSame('https://github.com/WLAN-Pi/pi-gen/releases/download/26.10-DeadEye/wlanpi-os-26.10-DeadEye-full.img.gz', $image->download_url);
        $this->assertSame(['repo' => 'WLAN-Pi/pi-gen', 'tag' => '26.10-DeadEye', 'asset' => 'wlanpi-os-26.10-DeadEye-full.img.gz'], array_intersect_key(json_decode($image->source, true), array_flip(['repo', 'tag', 'asset'])));
        Queue::assertPushedOn('images', DownloadImage::class);
        $this->assertSame('WLAN-Pi/pi-gen 26.10-DeadEye', $image->sourceLabel());
    }

    public function test_a_file_without_a_published_checksum_needs_the_tick_and_disagreeing_ones_stop_it()
    {
        Queue::fake();
        $this->fixture(['api.github.com/repos/o/r/releases*' => Http::response([['tag_name' => 'a', 'name' => 'a', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-01-01T00:00:00Z', 'assets' => [
            ['name' => 'os.img.xz', 'size' => 5000, 'state' => 'uploaded', 'digest' => null, 'browser_download_url' => 'https://github.com/o/r/releases/download/a/os.img.xz']]]], 200)]);

        $window = $this->window()->set('repo', 'o/r')->call('loadReleases')->assertSee('no published checksum')
            ->call('fetchAsset', 0, 0)->assertSee('No published checksum');
        $this->assertSame(0, Image::count());

        $window->set('unverified', true)->call('fetchAsset', 0, 0)->assertDispatched('image-sources-closed');
        $this->assertSame('none', Image::first()->checksum_source);
    }

    public function test_the_release_list_cannot_be_changed_from_the_browser()
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        $this->window()->set('repo', 'WLAN-Pi/pi-gen')->call('loadReleases')->set('releases', [['tag' => 'x', 'assets' => []]]);
    }

    public function test_a_fetch_of_a_zip_is_refused_until_the_repacking_is_there()
    {
        $this->window()->set('repo', 'WLAN-Pi/pi-gen')->call('loadReleases')->call('fetchAsset', 1, 0)->assertSee('Repacking .zip');
        $this->assertSame(0, Image::count());
    }
}
