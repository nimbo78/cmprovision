<?php

namespace Tests\Feature;

use App\Http\Livewire\ImageSources;
use App\Jobs\DownloadImage;
use App\Models\Image;
use App\Models\User;
use App\Services\ImageFetcher;
use App\Services\ImageSpace;
use App\Services\RaspberryPiCatalog;
use App\Support\ImageSourceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeImageFetcher;
use Tests\TestCase;

/** Raspberry Pi OS from the official list the Imager uses: what it offers and how the images are checked */
class RaspberryPiCatalogTest extends TestCase
{
    use RefreshDatabase;

    const LITE = 'https://downloads.raspberrypi.com/raspios_lite_arm64/images/raspios_lite_arm64-2026-10-06/2026-10-06-raspios-trixie-arm64-lite.img.xz';
    const EXTRACT = 'b8a393dc1c3701450b5fffbfe74368a98fb6eab7415ce456cc0cead886ea8780';
    const COMPRESSED = '483db18a48da399b5b7022ffae9a07bc6d99daab30e593aedac09b69ff422843';
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

    protected function fixture(?string $body = null, int $status = 200)
    {
        Http::swap(new \Illuminate\Http\Client\Factory);   // a new answer replaces the one before: fakes are tried in the order they were set
        Http::fake([RaspberryPiCatalog::URL => Http::response($body ?? file_get_contents(__DIR__.'/../fixtures/raspberrypi/os-list.json'), $status)]);
    }

    protected function catalog(): RaspberryPiCatalog
    {
        return app(RaspberryPiCatalog::class);
    }

    protected function lite(): array
    {
        foreach ($this->catalog()->variants() as $variant) {
            if ($variant['name'] === 'Raspberry Pi OS Lite (64-bit)') {
                return $variant;
            }
        }
        $this->fail('no Lite');
    }

    public function test_the_variants_are_the_raspberry_pi_os_images_of_the_list_and_nothing_else()
    {
        $names = array_column($this->catalog()->variants(), 'name');

        $this->assertContains('Raspberry Pi OS (64-bit)', $names);
        $this->assertContains('Raspberry Pi OS (32-bit)', $names);
        $this->assertContains('Raspberry Pi OS Lite (64-bit)', $names);
        $this->assertContains('Raspberry Pi OS (Legacy, 64-bit) Lite', $names);
        $this->assertNotContains('SD Card Boot', $names);
        $this->assertSame([], array_filter($names, fn ($name) => !str_starts_with($name, 'Raspberry Pi OS')), 'Ubuntu and the boot-only entries belong to other sources');
        $this->assertSame(array_values(array_unique($names)), $names);
    }

    public function test_a_variant_says_what_the_page_and_the_fetch_need()
    {
        $lite = $this->lite();

        $this->assertSame([self::LITE, 550466056, 'xz', '2026-10-06', self::EXTRACT, 3078619136],
            [$lite['url'], $lite['size'], $lite['ext'], $lite['date'], $lite['uncompressed'], $lite['uncompressed_size']]);
        $this->assertSame('2026-10-06-raspios-trixie-arm64-lite.img.xz', $lite['filename']);
    }

    public function test_the_list_is_kept_for_an_hour_and_a_failed_answer_is_not()
    {
        $this->catalog()->variants();
        $this->catalog()->variants();
        Http::assertSentCount(1);

        Cache::flush();
        $this->fixture('', 503);
        try {
            $this->catalog()->variants();
            $this->fail('503');
        } catch (ImageSourceException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
            $this->assertFalse($e->fatal);
        }
        $this->fixture();
        $this->assertNotEmpty($this->catalog()->variants());
    }

    public function test_an_answer_that_is_no_list_or_too_big_or_no_network_is_an_error_in_words()
    {
        foreach (['<html>', '{"os_list": 5}', '{}', str_repeat('x', 5242881)] as $body) {
            Cache::flush();
            $this->fixture($body);
            try {
                $this->catalog()->variants();
                $this->fail(substr($body, 0, 20));
            } catch (ImageSourceException $e) {
                $this->assertStringContainsString('downloads.raspberrypi.com', $e->getMessage());
            }
        }
        Cache::flush();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([RaspberryPiCatalog::URL => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6')]);
        $this->expectException(ImageSourceException::class);
        $this->catalog()->variants();
    }

    public function test_the_checksums_are_the_one_of_the_unpacked_image_and_the_file_next_to_the_download()
    {
        $this->fetcher->textAnswer = self::COMPRESSED.'  2026-10-06-raspios-trixie-arm64-lite.img.xz'."\n";

        $found = $this->catalog()->checksums($this->lite());

        $this->assertSame(['sha256' => self::COMPRESSED, 'uncompressed' => self::EXTRACT, 'source' => 'catalog'], $found);
        $this->assertSame([self::LITE.'.sha256'], $this->fetcher->texts);
    }

    public function test_a_download_without_a_sha256_file_is_still_checked_by_the_unpacked_checksum_but_any_other_failure_is_not_hidden()
    {
        $this->fetcher->textAnswer = new ImageSourceException('The server answered 404 (not found) for x.', true, 404);
        $this->assertSame(['sha256' => null, 'uncompressed' => self::EXTRACT, 'source' => 'catalog'], $this->catalog()->checksums($this->lite()));

        $this->fetcher->textAnswer = new ImageSourceException('The server answered 503 (unavailable) for x.', false, 503);
        $this->expectException(ImageSourceException::class);
        $this->catalog()->checksums($this->lite());
    }

    public function test_a_variant_without_the_unpacked_checksum_is_not_offered_unverified()
    {
        $body = json_decode(file_get_contents(__DIR__.'/../fixtures/raspberrypi/os-list.json'), true);
        unset($body['os_list'][0]['extract_sha256']);
        $this->fixture(json_encode($body));

        $this->assertNotContains('Raspberry Pi OS (64-bit)', array_column($this->catalog()->variants(), 'name'), 'every Raspberry Pi OS image of the list has one: one without is not what the list promises');
    }

    // --- the window

    protected function window()
    {
        return Livewire::test(ImageSources::class)->set('tab', 'raspberrypi');
    }

    public function test_the_window_lists_the_variants_with_their_size_and_date()
    {
        $this->window()
            ->assertSee('Raspberry Pi OS Lite (64-bit)')
            ->assertSee('550 MB')
            ->assertSee('2026-10-06')
            ->assertSee('Raspberry Pi OS (Legacy, 64-bit) Lite')
            ->assertDontSee('Ubuntu');
    }

    public function test_a_list_that_cannot_be_had_is_said_in_the_window()
    {
        Cache::flush();
        $this->fixture('', 503);

        $this->window()->assertSee('503')->assertDontSee('Raspberry Pi OS Lite (64-bit)');
    }

    public function test_download_queues_the_image_with_its_checksums()
    {
        Queue::fake();
        $this->fetcher->textAnswer = self::COMPRESSED."  2026-10-06-raspios-trixie-arm64-lite.img.xz\n";
        $window = $this->window();
        $index = array_search('Raspberry Pi OS Lite (64-bit)', array_column($this->catalog()->variants(), 'name'));

        $window->call('fetchVariant', $index)->assertSet('error', null)->assertDispatched('image-sources-closed');

        $image = Image::first();
        $this->assertSame(['raspberrypi', '2026-10-06-raspios-trixie-arm64-lite.img.xz', 'xz', 'queued', 550466056, self::COMPRESSED, self::EXTRACT, 'catalog'],
            [$image->source_type, $image->filename, $image->filename_extension, $image->download_state, $image->download_total, $image->expected_sha256, $image->expected_uncompressed_sha256, $image->checksum_source]);
        $this->assertSame(self::LITE, $image->download_url);
        $this->assertSame('Raspberry Pi OS catalog: Raspberry Pi OS Lite (64-bit)', $image->sourceLabel());
        Queue::assertPushedOn('images', DownloadImage::class);
    }

    public function test_a_variant_that_is_no_longer_in_the_list_is_refused()
    {
        Queue::fake();

        $this->window()->call('fetchVariant', 999)->assertSee('no longer in the list');
        $this->assertSame(0, Image::count());
    }

    public function test_the_list_cannot_be_changed_from_the_browser()
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        $this->window()->set('catalog', [['url' => 'https://example.org/x.img.gz']]);
    }
}
