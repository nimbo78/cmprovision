<?php

namespace Tests\Feature;

use App\Jobs\ComputeSHA256;
use App\Jobs\ConvertImageArchive;
use App\Jobs\DownloadImage;
use App\Models\Image;
use App\Services\ImageDownloads;
use App\Services\ImageFetcher;
use App\Services\ImageSpace;
use App\Support\ImageSourceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeImageFetcher;
use Tests\TestCase;

/** The chain that fetches an image: a request becomes a queued image, slices of about a minute fetch it, then the checks take over */
class ImageDownloadTest extends TestCase
{
    use RefreshDatabase;

    const GB = 1000000000;
    const URL = 'https://example.org/downloads/os.img.gz?dl=1&token=secret';

    protected $files = [];
    protected $fetcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fetcher = new FakeImageFetcher;
        $this->app->instance(ImageFetcher::class, $this->fetcher);
        $this->space(100 * self::GB);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
            @unlink($file.'.part');
        }
        parent::tearDown();
    }

    protected function space(int $free): void
    {
        $this->app->instance(ImageSpace::class, new ImageSpace(fn () => $free));
    }

    protected function fetched(array $attributes = []): Image
    {
        $image = new Image;
        $image->filename = 'os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'dl-'.uniqid().'.gz';
        $image->sha256 = '';
        $image->source_type = 'url';
        $image->download_url = self::URL;
        $image->download_state = 'queued';
        $image->download_total = 1000;
        $image->download_bytes = 0;
        foreach ($attributes as $key => $value) {
            $image->$key = $value;
        }
        $image->save();
        $this->files[] = $image->imagepath();

        return $image;
    }

    protected function runSlice(Image $image, int $failures = 0): void
    {
        $job = new DownloadImage($image, $failures);
        app()->call([$job, 'handle']);
    }

    protected function checksums(array $over = []): array
    {
        return array_merge(['sha256' => str_repeat('a', 64), 'uncompressed' => null, 'source' => 'user'], $over);
    }

    protected function start(array $over = [], int $size = 1000000000, bool $unverifiedOk = false, string $url = self::URL, string $filename = 'os.img.gz'): Image
    {
        $image = app(ImageDownloads::class)->start('url', $url, $filename, ['host' => 'example.org'], $size, $this->checksums($over), $unverifiedOk);
        $this->files[] = $image->imagepath();

        return $image;
    }

    // --- the request

    public function test_a_request_becomes_a_queued_image_and_a_fetch_on_the_images_queue()
    {
        $image = $this->start(['uncompressed' => str_repeat('b', 64)]);

        $this->assertSame(['url', 'os.img.gz', 'gz', '', 'queued', 1000000000, 0], [$image->source_type, $image->filename, $image->filename_extension,
            $image->sha256, $image->download_state, $image->download_total, $image->download_bytes]);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.gz$/', $image->filename_on_server);
        $this->assertSame([str_repeat('a', 64), str_repeat('b', 64), 'user'], [$image->expected_sha256, $image->expected_uncompressed_sha256, $image->checksum_source]);
        $this->assertSame(self::URL, Image::find($image->id)->download_url);
        $raw = DB::table('images')->where('id', $image->id)->first();
        $this->assertStringNotContainsString('secret', json_encode($raw), 'the token is in no column in the open');
        $this->assertSame('https://example.org/downloads/os.img.gz', json_decode($raw->source, true)['url']);
        Queue::assertPushedOn('images', DownloadImage::class, fn ($job) => $job->image->is($image) && $job->failures === 0);
    }

    public function test_what_cannot_be_taken_is_refused_before_anything_is_queued()
    {
        $refusals = [
            'a file that is no image' => [fn () => $this->start([], 1000, false, self::URL, 'os.img'), 'Only .gz, .xz, .bz2 and .zip'],
            'a file over the limit' => [function () {
                config(['cmprovision.image_download_max_gb' => 1]);

                return $this->start([], 2 * self::GB);
            }, 'larger than the 1 GB limit'],
            'a file without a checksum' => [fn () => $this->start(['sha256' => null, 'uncompressed' => null, 'source' => 'none']), 'No published checksum'],
            'no room' => [function () {
                $this->space(self::GB);

                return $this->start([], 2 * self::GB);
            }, 'Needs about'],
        ];
        foreach ($refusals as $what => [$try, $text]) {
            try {
                $try();
                $this->fail($what);
            } catch (ImageSourceException $e) {
                $this->assertStringContainsString($text, $e->getMessage(), $what);
            }
            $this->space(100 * self::GB);
            config(['cmprovision.image_download_max_gb' => 40]);
        }
        $this->assertSame(0, Image::count());
        Queue::assertNothingPushed();
    }

    public function test_an_image_without_a_published_checksum_is_added_only_on_purpose()
    {
        $image = $this->start(['sha256' => null, 'source' => 'none'], 1000000, true);

        $this->assertSame('none', $image->checksum_source);
        $this->assertNull($image->expected_sha256);
    }

    public function test_the_same_file_is_not_fetched_twice_at_once_but_may_be_again_after_a_failure()
    {
        $first = $this->start();
        try {
            $this->start();
            $this->fail('already on its way');
        } catch (ImageSourceException $e) {
            $this->assertStringContainsString('already being fetched', $e->getMessage());
        }

        $first->download_state = 'failed';
        $first->download_error = 'x';
        $first->save();
        $this->assertNotNull($this->start());
    }

    // --- the slices

    public function test_a_slice_ends_at_its_deadline_and_the_next_one_waits_behind_the_other_jobs()
    {
        $image = $this->fetched();
        $this->fetcher->script = [FakeImageFetcher::writes(300)];

        $this->runSlice($image);

        $call = $this->fetcher->calls[0];
        $this->assertSame(self::URL, $call['url']);
        $this->assertSame(1000, $call['total']);
        $this->assertEqualsWithDelta(microtime(true) + config('cmprovision.image_download_slice'), $call['deadline'], 2);
        $fresh = Image::find($image->id);
        $this->assertSame(['downloading', 300, null], [$fresh->download_state, $fresh->download_bytes, $fresh->download_error]);
        Queue::assertPushedOn('images', DownloadImage::class, fn ($job) => $job->failures === 0 && $job->delay === null);
        Queue::assertPushed(DownloadImage::class, 1);
    }

    public function test_the_last_slice_makes_the_file_and_hands_over_to_the_checks()
    {
        $image = $this->fetched(['download_bytes' => 600]);
        file_put_contents($image->imagepath().'.part', str_repeat('x', 600));
        $this->fetcher->script = [FakeImageFetcher::writes(400, true)];

        $this->runSlice($image);

        $this->assertFileDoesNotExist($image->imagepath().'.part');
        $this->assertSame(1000, filesize($image->imagepath()));
        $fresh = Image::find($image->id);
        $this->assertSame(['checking', 1000], [$fresh->download_state, $fresh->download_bytes]);
        Queue::assertPushedOn('images', ComputeSHA256::class, fn ($job) => $job->image->is($image));
        Queue::assertNotPushed(DownloadImage::class);
    }

    public function test_the_progress_goes_to_the_database_and_a_deleted_image_stops_the_fetch_and_leaves_nothing()
    {
        $image = $this->fetched();
        $this->fetcher->script = [function (string $part, ?int $total, callable $progress) use ($image) {
            file_put_contents($part, str_repeat('x', 500));
            $this->assertTrue($progress(500));
            $this->assertSame(500, Image::find($image->id)->download_bytes);
            Image::find($image->id)->delete();   // Delete on the Images page
            $this->assertFalse($progress(600), 'the fetch is told to stop');

            return ['bytes' => 600, 'done' => false, 'restarted' => false, 'cancelled' => true];
        }];

        $this->runSlice($image);

        $this->assertFileDoesNotExist($image->imagepath().'.part');
        $this->assertFileDoesNotExist($image->imagepath());
        Queue::assertNothingPushed();
    }

    public function test_a_broken_transfer_is_tried_again_later_and_later_and_twelve_in_a_row_fail_the_image()
    {
        $image = $this->fetched();
        $this->fetcher->script = [new ImageSourceException('The connection was lost during the download.')];

        $this->runSlice($image, 0);

        Queue::assertPushed(DownloadImage::class, fn ($job) => $job->failures === 1 && $job->delay === 5);
        $this->assertSame('downloading', Image::find($image->id)->download_state);
        $this->assertSame('The connection was lost during the download.', Cache::get('image-retry:'.$image->id)['message'], 'the page says why it waits');

        // the pauses grow to two minutes: about a quarter of an hour of patience for a router that restarts or a Wi-Fi that reconnects
        foreach ([1 => 15, 2 => 30, 3 => 60, 4 => 120, 5 => 120, 10 => 120] as $failures => $delay) {
            Queue::fake();
            $this->fetcher->script = [new ImageSourceException('The connection was lost during the download.')];
            $this->runSlice($image, $failures);
            Queue::assertPushed(DownloadImage::class, fn ($job) => $job->failures === $failures + 1 && $job->delay === $delay);
        }
        Queue::fake();
        $this->fetcher->script = [new ImageSourceException('The connection was lost during the download.')];
        $this->runSlice($image, 11);
        $fresh = Image::find($image->id);
        $this->assertSame('failed', $fresh->download_state);
        $this->assertStringContainsString('The connection was lost during the download.', $fresh->download_error);
        Queue::assertNotPushed(DownloadImage::class);
    }

    public function test_a_server_that_does_not_resume_fails_the_image_at_once_unless_the_file_arrived_whole_in_the_slice()
    {
        $image = $this->fetched(['download_bytes' => 300]);
        file_put_contents($image->imagepath().'.part', str_repeat('x', 300));
        $this->fetcher->script = [['bytes' => 120, 'done' => false, 'restarted' => true, 'cancelled' => false, 'ignoredRange' => true]];

        $this->runSlice($image);

        $fresh = Image::find($image->id);
        $this->assertSame('failed', $fresh->download_state);
        $this->assertStringContainsString('does not resume', $fresh->download_error);
        Queue::assertNothingPushed();

        $whole = $this->fetched(['download_bytes' => 300]);
        file_put_contents($whole->imagepath().'.part', str_repeat('x', 300));
        $this->fetcher->script = [function (string $part) {
            file_put_contents($part, str_repeat('x', 1000));

            return ['bytes' => 1000, 'done' => true, 'restarted' => true, 'cancelled' => false, 'ignoredRange' => true];
        }];
        $this->runSlice($whole);
        $this->assertSame('checking', Image::find($whole->id)->download_state, 'it came whole in one go: nothing was wrong');
    }

    public function test_a_transfer_that_broke_after_bringing_data_is_a_failure_of_one_not_of_the_chain()
    {
        $image = $this->fetched();
        $this->fetcher->script = [function (string $part) {
            file_put_contents($part, str_repeat('x', 200), FILE_APPEND);

            throw new ImageSourceException('The connection was lost during the download.');
        }];

        $this->runSlice($image, 3);   // three in a row before, but this one got 200 bytes further

        Queue::assertPushed(DownloadImage::class, fn ($job) => $job->failures === 1 && $job->delay === 5);
        $this->assertSame('downloading', Image::find($image->id)->download_state);
    }

    public function test_a_slice_that_brings_nothing_counts_like_a_failure_and_one_that_brings_something_does_not()
    {
        $image = $this->fetched(['download_bytes' => 100]);
        file_put_contents($image->imagepath().'.part', str_repeat('x', 100));

        $this->fetcher->script = [['bytes' => 100, 'done' => false, 'restarted' => false, 'cancelled' => false]];
        $this->runSlice($image, 2);
        Queue::assertPushed(DownloadImage::class, fn ($job) => $job->failures === 3 && $job->delay === 30);

        Queue::fake();
        $this->fetcher->script = [FakeImageFetcher::writes(50)];
        $this->runSlice($image, 3);
        Queue::assertPushed(DownloadImage::class, fn ($job) => $job->failures === 0 && $job->delay === null);
        $this->assertNull(Cache::get('image-retry:'.$image->id), 'the way is clear again: no word of trouble on the page');

        $this->fetcher->script = [['bytes' => 150, 'done' => false, 'restarted' => false, 'cancelled' => false]];
        $this->runSlice($image, 11);
        $fresh = Image::find($image->id);
        $this->assertSame('failed', $fresh->download_state);
        $this->assertStringContainsString('No data', $fresh->download_error);
    }

    public function test_an_error_that_will_not_mend_fails_the_image_at_once_without_the_address_query()
    {
        $image = $this->fetched();
        $this->fetcher->script = [new ImageSourceException('The server answered 404 (not found) for https://example.org/downloads/os.img.gz.', true)];

        $this->runSlice($image);

        $fresh = Image::find($image->id);
        $this->assertSame('failed', $fresh->download_state);
        $this->assertSame('The server answered 404 (not found) for https://example.org/downloads/os.img.gz.', $fresh->download_error);
        $this->assertStringNotContainsString('secret', $fresh->download_error);
        Queue::assertNothingPushed();
    }

    public function test_a_slice_cut_off_by_a_killed_worker_is_run_again_by_the_queue()
    {
        // checked live on the provisioner: with one try a killed worker left the image failed after 90 seconds and Retry was needed
        $this->assertGreaterThan(1, (new DownloadImage($this->fetched()))->tries);
    }

    public function test_a_chain_that_dies_fails_the_image_instead_of_leaving_it_downloading_for_ever()
    {
        $image = $this->fetched(['download_state' => 'downloading']);

        (new DownloadImage($image))->failed(new \RuntimeException('the worker was killed'));

        $fresh = Image::find($image->id);
        $this->assertSame('failed', $fresh->download_state);
        $this->assertStringContainsString('the worker was killed', $fresh->download_error);
    }

    public function test_a_slice_for_an_image_that_is_gone_or_no_longer_fetching_does_nothing()
    {
        $gone = $this->fetched();
        $gone->delete();
        $failed = $this->fetched(['download_state' => 'failed', 'download_error' => 'x']);

        $this->runSlice($gone);
        $this->runSlice($failed);

        $this->assertSame([], $this->fetcher->calls);
        Queue::assertNothingPushed();
    }

    public function test_the_space_is_checked_again_before_every_slice()
    {
        $image = $this->fetched(['download_total' => 2 * self::GB, 'download_bytes' => 500000000]);
        $this->space(1 * self::GB);   // the rest, 1.5 GB, and the reserve no longer fit

        $this->runSlice($image);

        $fresh = Image::find($image->id);
        $this->assertSame('failed', $fresh->download_state);
        $this->assertStringContainsString('Needs about', $fresh->download_error);
        $this->assertSame([], $this->fetcher->calls);
    }

    // --- cancel and retry

    public function test_deleting_an_image_takes_its_part_with_it()
    {
        $image = $this->fetched(['download_state' => 'downloading']);
        file_put_contents($image->imagepath().'.part', 'x');

        app(ImageDownloads::class)->cancel($image);

        $this->assertFileDoesNotExist($image->imagepath().'.part');
        $this->assertNull(Image::find($image->id));
    }

    public function test_retry_goes_on_from_what_is_there()
    {
        $withPart = $this->fetched(['download_state' => 'failed', 'download_error' => 'stalled', 'download_bytes' => 300]);
        file_put_contents($withPart->imagepath().'.part', str_repeat('x', 300));
        $whole = $this->fetched(['download_state' => 'failed', 'download_error' => 'Checking the image failed: x', 'download_bytes' => 1000]);
        file_put_contents($whole->imagepath(), str_repeat('x', 1000));
        $nothing = $this->fetched(['download_state' => 'failed', 'download_error' => 'The checksums disagree', 'download_bytes' => 1000]);

        foreach ([$withPart, $whole, $nothing] as $image) {
            app(ImageDownloads::class)->retry($image);
        }

        $this->assertSame(['queued', null, 300], [Image::find($withPart->id)->download_state, Image::find($withPart->id)->download_error, Image::find($withPart->id)->download_bytes]);
        $this->assertSame(['checking', null], [Image::find($whole->id)->download_state, Image::find($whole->id)->download_error]);
        $this->assertSame(['queued', 0], [Image::find($nothing->id)->download_state, Image::find($nothing->id)->download_bytes], 'a fetch of its own, from nothing');
        Queue::assertPushed(DownloadImage::class, fn ($job) => $job->image->is($withPart));
        Queue::assertPushed(DownloadImage::class, fn ($job) => $job->image->is($nothing));
        Queue::assertPushed(ComputeSHA256::class, fn ($job) => $job->image->is($whole));
        Queue::assertPushed(DownloadImage::class, 2);
    }

    public function test_retry_of_an_image_that_is_not_failed_does_nothing()
    {
        $image = $this->fetched(['download_state' => 'downloading']);

        app(ImageDownloads::class)->retry($image);

        Queue::assertNothingPushed();
        $this->assertSame('downloading', Image::find($image->id)->download_state);
    }
    // --- an archive

    public function test_an_archive_asks_for_room_for_its_repacked_file_too()
    {
        $this->space(3 * self::GB);
        try {
            $this->start([], self::GB, false, self::URL, 'os.zip');
            $this->fail('1 GB, 1.3 GB for the repacked file and the reserve do not fit in 3 GB');
        } catch (ImageSourceException $e) {
            $this->assertStringContainsString('Needs about 4.4 GB, 3.0 GB free.', $e->getMessage());
        }

        $this->space(100 * self::GB);
        $image = $this->start([], self::GB, false, self::URL, 'os.zip');
        $this->assertSame(['zip', 'queued'], [$image->filename_extension, $image->download_state]);
        $this->assertStringEndsWith('.zip', $image->filename_on_server);
    }

    public function test_the_last_slice_of_an_archive_hands_over_to_the_repacking_not_to_the_checks()
    {
        $image = $this->fetched(['filename' => 'os.zip', 'filename_extension' => 'zip', 'filename_on_server' => 'dl-'.uniqid().'.zip', 'download_bytes' => 600]);
        file_put_contents($image->imagepath().'.part', str_repeat('x', 600));
        $this->fetcher->script = [FakeImageFetcher::writes(400, true)];

        $this->runSlice($image);

        $fresh = Image::find($image->id);
        $this->assertSame(['converting', 0], [$fresh->download_state, $fresh->download_bytes]);
        Queue::assertPushedOn('images', ConvertImageArchive::class, fn ($job) => $job->image->is($image));
        Queue::assertNotPushed(ComputeSHA256::class);
        @unlink($image->imagepath());
    }
}
