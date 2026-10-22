<?php

namespace Tests\Feature;

use App\Http\Livewire\ImageSources;
use App\Http\Livewire\Images;
use App\Jobs\ComputeSHA256;
use App\Jobs\DownloadImage;
use App\Models\Image;
use App\Models\User;
use App\Services\ImageFetcher;
use App\Services\ImageSpace;
use App\Support\FetchSpeed;
use App\Support\ImageSourceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeImageFetcher;
use Tests\TestCase;

/** The Images page with images from the internet: the window that takes a link, and the rows that say how a fetch is going */
class ImageSourcesPageTest extends TestCase
{
    use RefreshDatabase;

    const GB = 1000000000;
    const URL = 'https://example.org/downloads/os.img.gz?token=secret';
    const SUM = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    protected $fetcher;
    protected $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fetcher = new FakeImageFetcher;
        $this->fetcher->probeAnswer = ['size' => 1600000000, 'ranges' => true, 'name' => 'os.img.gz', 'final' => 'https://cdn.example.org/os.img.gz'];
        $this->app->instance(ImageFetcher::class, $this->fetcher);
        $this->app->instance(ImageSpace::class, new ImageSpace(fn () => 100 * self::GB));
        Queue::fake();
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    protected function row(array $attributes = []): Image
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'row-'.uniqid().'.gz';
        $image->sha256 = '';
        $image->source_type = 'url';
        foreach ($attributes as $key => $value) {
            $image->$key = $value;
        }
        $image->save();
        $this->files[] = $image->imagepath();

        return $image;
    }

    // --- the page

    public function test_the_page_offers_an_image_from_the_internet_and_says_how_much_room_there_is()
    {
        Livewire::test(Images::class)
            ->assertSee('Add from the internet')
            ->assertSee('Free disk space for images: 100.0 GB')
            ->assertDontSeeLivewire(ImageSources::class)
            ->call('openSources')
            ->assertSet('sourcesOpen', true)
            ->assertSeeLivewire(ImageSources::class)
            ->dispatch('image-sources-closed')
            ->assertSet('sourcesOpen', false)
            ->assertDontSeeLivewire(ImageSources::class);
    }

    public function test_a_fetch_shows_where_it_is_and_the_page_keeps_asking_while_there_is_one()
    {
        $this->row(['filename' => 'fetching.img.gz', 'source_type' => 'github', 'download_state' => 'downloading', 'download_bytes' => 544000000, 'download_total' => 1600000000,
            'checksum_source' => 'github-digest', 'source' => json_encode(['repo' => 'WLAN-Pi/pi-gen', 'tag' => '26.10-DeadEye', 'url' => 'https://github.com/a'])]);
        $this->row(['filename' => 'waiting.img.gz', 'download_state' => 'queued', 'download_total' => 1000, 'download_bytes' => 0, 'checksum_source' => 'none']);
        $this->row(['filename' => 'checking.img.gz', 'download_state' => 'checking', 'download_total' => 1000, 'download_bytes' => 1000, 'checksum_source' => 'user']);

        Livewire::test(Images::class)
            ->assertSee('Downloading 34 % (544 MB of 1.6 GB)')
            ->assertSee('Waiting')
            ->assertSee('Checking')
            ->assertSee('WLAN-Pi/pi-gen 26.10-DeadEye')
            ->assertSee('verified against the GitHub digest')
            ->assertSee('no published checksum')
            ->assertSee('verified against the checksum you gave')
            ->assertSeeHtml('wire:poll.5s')
            ->assertSeeHtml('width: 34%');
    }

    public function test_a_failed_fetch_says_why_and_can_be_tried_again_or_deleted()
    {
        $image = $this->row(['filename' => 'failed.img.gz', 'download_state' => 'failed', 'download_error' => 'The download stalled: no data for 120 seconds.',
            'download_total' => 1000, 'download_bytes' => 300]);
        file_put_contents($image->imagepath().'.part', str_repeat('x', 300));

        $page = Livewire::test(Images::class)
            ->assertSee('Failed: The download stalled: no data for 120 seconds.')
            ->assertSeeHtml('wire:click="retry('.$image->id.')"')
            ->assertDontSeeHtml('wire:poll');

        $page->call('retry', $image->id);
        $this->assertSame(['queued', null], [Image::find($image->id)->download_state, Image::find($image->id)->download_error]);
        Queue::assertPushed(DownloadImage::class, fn ($job) => $job->image->is($image));

        $page->call('delete', $image->id);
        $this->assertNull(Image::find($image->id));
        $this->assertFileDoesNotExist($image->imagepath().'.part');
    }

    public function test_a_page_without_fetches_does_not_ask_the_server_and_an_uploaded_image_looks_as_before()
    {
        $uploaded = $this->row(['source_type' => null, 'filename' => 'uploaded.img.gz', 'sha256' => str_repeat('a', 64)]);

        Livewire::test(Images::class)
            ->assertSee('uploaded.img.gz')
            ->assertDontSeeHtml('wire:poll')
            ->assertDontSee('verified against')
            ->assertDontSee('Failed');
        $this->assertNotNull($uploaded);
    }

    public function test_a_fetch_that_runs_shows_its_speed_and_what_is_left()
    {
        $image = $this->row(['download_state' => 'downloading', 'download_bytes' => 100, 'download_total' => 1000000100]);

        $this->assertNull(FetchSpeed::note($image, 10.0));
        $image->download_bytes = 100 + 24 * 1000000;   // 12 MB/s over 2 s
        $this->assertEqualsWithDelta(12000000, FetchSpeed::note($image, 12.0), 1);
        $image->download_bytes = 100 + 24 * 1000000 + 28 * 1000000;   // 14 MB/s next: smoothed with the last
        $this->assertEqualsWithDelta(13000000, FetchSpeed::note($image, 14.0), 1);

        $left = function (int $bytes) {
            $image = new Image;
            $image->download_bytes = 0;
            $image->download_total = $bytes;

            return $image;
        };
        $this->assertSame(' · 13 MB/s · 5 min left', $left(3900000000)->downloadRate(13000000.0));
        $this->assertSame(' · 13 MB/s · 40 s left', $left(520000000)->downloadRate(13000000.0));
        $this->assertSame(' · 800 kB/s · 3 h 5 min left', $left(8880000000)->downloadRate(800000.0));
        $this->assertSame('', $left(1000)->downloadRate(null));
    }

    // --- the window

    protected function window(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(ImageSources::class);
    }

    public function test_a_checked_address_shows_what_it_is_and_what_it_takes()
    {
        $this->window()->set('url', self::URL)->call('check')
            ->assertSet('error', null)
            ->assertSet('filename', 'os.img.gz')
            ->assertSee('os.img.gz')
            ->assertSee('1.6 GB')
            ->assertSee('Needs about 3.7 GB, 100.0 GB free.')
            ->assertDontSee('secret')
            ->assertDontSee('does not resume');
    }

    public function test_a_server_that_does_not_resume_is_said_so()
    {
        $this->fetcher->probeAnswer['ranges'] = false;

        $this->window()->set('url', self::URL)->call('check')->assertSee('does not resume interrupted downloads');
    }

    public function test_a_refused_address_a_missing_size_and_no_room_each_say_so_and_offer_no_download()
    {
        $this->fetcher->probeAnswer = new ImageSourceException('This address is not allowed: it points into a private or local network.', true);
        $this->window()->set('url', 'https://10.0.0.1/os.img.gz')->call('check')->assertSee('This address is not allowed')->assertSet('probe', null);

        $this->fetcher->probeAnswer = new ImageSourceException('The server does not tell the size of the file, so it cannot be fetched.', true);
        $this->window()->set('url', self::URL)->call('check')->assertSee('does not tell the size');

        $this->fetcher->probeAnswer = ['size' => 1600000000, 'ranges' => true, 'name' => 'os.img.gz', 'final' => 'x'];
        $this->app->instance(ImageSpace::class, new ImageSpace(fn () => 2 * self::GB));
        $this->window()->set('url', self::URL)->call('check')->assertSee('Needs about 3.7 GB, 2.0 GB free.')->assertSee('free space first');
        $this->window()->call('check')->assertSee('Paste the address');
    }

    public function test_a_new_address_forgets_what_was_checked_of_the_old_one()
    {
        $this->window()->set('url', self::URL)->call('check')->assertNotSet('probe', null)
            ->set('url', 'https://example.org/other.img.gz')->assertSet('probe', null)->assertSet('error', null);
    }

    public function test_a_name_the_server_does_not_give_is_asked_for()
    {
        $this->fetcher->probeAnswer['name'] = 'download';

        $this->window()->set('url', self::URL)->call('check')->assertSet('filename', '')
            ->call('download')->assertSee('Only .gz, .xz, .bz2 and .zip');
        Queue::assertNothingPushed();
    }

    public function test_without_a_checksum_the_image_is_taken_only_on_purpose()
    {
        $this->window()->set('url', self::URL)->call('check')->call('download')
            ->assertSee('No published checksum')
            ->assertNotDispatched('image-sources-closed');
        $this->assertSame(0, Image::count());

        $this->window()->set('url', self::URL)->call('check')->set('unverified', true)->call('download')
            ->assertSet('error', null)->assertDispatched('image-sources-closed');
        $image = Image::first();
        $this->assertSame(['url', 'none', 'queued', 'os.img.gz'], [$image->source_type, $image->checksum_source, $image->download_state, $image->filename]);
        $this->assertSame(self::URL, $image->download_url);
        Queue::assertPushedOn('images', DownloadImage::class);
    }

    public function test_a_typed_checksum_is_what_the_file_must_be()
    {
        $this->window()->set('url', self::URL)->call('check')->set('sum', '  '.strtoupper(self::SUM)."\n")->call('download')
            ->assertDispatched('image-sources-closed');

        $image = Image::first();
        $this->assertSame([self::SUM, 'user'], [$image->expected_sha256, $image->checksum_source]);
    }

    public function test_the_address_of_a_checksum_file_is_read_through_the_fetcher_and_the_line_of_the_file_is_taken()
    {
        $this->fetcher->textAnswer = str_repeat('d', 64)."  other.img.gz\n".self::SUM."  os.img.gz\n";

        $this->window()->set('url', self::URL)->call('check')->set('sum', 'https://example.org/downloads/SHA256SUMS?x=1')->call('download')
            ->assertDispatched('image-sources-closed');

        $this->assertSame(['https://example.org/downloads/SHA256SUMS?x=1'], $this->fetcher->texts);
        $image = Image::first();
        $this->assertSame([self::SUM, 'sha256-file'], [$image->expected_sha256, $image->checksum_source]);
    }

    public function test_a_checksum_file_that_has_no_line_for_the_file_or_cannot_be_read_stops_the_download()
    {
        $this->fetcher->textAnswer = str_repeat('d', 64)."  other.img.gz\n";
        $this->window()->set('url', self::URL)->call('check')->set('sum', 'https://example.org/SHA256SUMS')->call('download')
            ->assertSee('has no checksum for os.img.gz');

        $this->fetcher->textAnswer = new ImageSourceException('The server answered 404 (not found) for https://example.org/SHA256SUMS.', true);
        $this->window()->set('url', self::URL)->call('check')->set('sum', 'https://example.org/SHA256SUMS')->call('download')
            ->assertSee('404');

        $this->window()->set('url', self::URL)->call('check')->set('sum', 'what is this')->call('download')
            ->assertSee('64 hex digits or the address');
        $this->assertSame(0, Image::count());
    }

    public function test_what_the_server_refuses_to_take_is_shown_and_nothing_is_queued()
    {
        $this->app->instance(ImageSpace::class, new ImageSpace(fn () => 100 * self::GB));
        $window = $this->window()->set('url', self::URL)->call('check');
        $this->app->instance(ImageSpace::class, new ImageSpace(fn () => 2 * self::GB));   // the disk filled meanwhile

        $window->set('sum', self::SUM)->call('download')->assertSee('Needs about')->assertNotDispatched('image-sources-closed');
        $this->assertSame(0, Image::count());
        Queue::assertNothingPushed();
        $this->assertNotNull(ComputeSHA256::class);
    }
}
