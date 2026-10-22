<?php

namespace Tests\Feature;

use App\Services\ImageFetcher;
use App\Support\ImageSourceException;
use App\Support\ImageUrlGuard;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The transport of an image fetch, against a real HTTP server (php -S on loopback): resuming, the answers of servers that do
 * not resume, broken and stalled transfers, redirects. Without php-curl or php -S the tests are skipped.
 */
class ImageFetcherTest extends TestCase
{
    const TOTAL = 3145728;

    protected static $server;
    protected static $port;
    protected $dir;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!extension_loaded('curl') || !function_exists('proc_open')) {
            return;
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if (!$probe) {
            return;
        }
        self::$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $router = realpath(__DIR__.'/../Support/image-server.php');
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.self::$port, $router], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes,
            null, ['PHP_CLI_SERVER_WORKERS' => '4']);
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.2);
            if ($socket) {
                fclose($socket);

                return;
            }
            usleep(100000);
        }
        proc_terminate(self::$server);
        self::$server = null;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$server) {
            $this->markTestSkipped('needs php-curl and a php -S test server on loopback');
        }
        config(['cmprovision.image_test_loopback' => true]);
        $this->dir = sys_get_temp_dir().'/cmprovision-fetch-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function url(string $path): string
    {
        return 'http://127.0.0.1:'.self::$port.$path;
    }

    /** the bytes the test server holds, from $from for $length bytes */
    protected function bytes(int $from = 0, ?int $length = null): string
    {
        return substr(str_repeat(hash('sha512', 'image-server', true), self::TOTAL / 64), $from, $length ?? self::TOTAL - $from);
    }

    protected function fetcher(int $stall = 120, float $progressEvery = 0.05): ImageFetcher
    {
        return new ImageFetcher(null, $stall, $progressEvery);
    }

    protected function download(string $path, ?string $part = null, ?int $total = self::TOTAL, ?callable $progress = null, ?float $deadline = null, ?ImageFetcher $fetcher = null): array
    {
        return ($fetcher ?? $this->fetcher())->download($this->url($path), $part ?? $this->dir.'/image.part', $total, $progress ?? fn () => true, $deadline ?? microtime(true) + 30);
    }

    // --- probe

    public function test_the_probe_reads_the_size_from_a_one_byte_range_and_the_name_from_the_path()
    {
        $info = $this->fetcher()->probe($this->url('/img/wlanpi-os-26.10.img.gz'));

        $this->assertSame(self::TOTAL, $info['size']);
        $this->assertTrue($info['ranges']);
        $this->assertSame('wlanpi-os-26.10.img.gz', $info['name']);
    }

    public function test_the_probe_follows_redirects_and_a_server_that_does_not_resume_gives_its_length()
    {
        $info = $this->fetcher()->probe($this->url('/redirect/3'));

        $this->assertSame(self::TOTAL, $info['size']);
        $this->assertSame('file', $info['name']);
        $this->assertStringEndsWith('/file?token=secret', $info['final'], 'the final address keeps its query for the fetch');

        $plain = $this->fetcher()->probe($this->url('/no-range'));
        $this->assertSame(self::TOTAL, $plain['size']);
        $this->assertFalse($plain['ranges']);
        $this->assertSame('/file', parse_url($this->fetcher()->probe($this->url('/relative'))['final'], PHP_URL_PATH));
    }

    public function test_the_name_may_come_from_the_servers_content_disposition()
    {
        $this->assertSame('named-by-server.img.xz', $this->fetcher()->probe($this->url('/disposition'))['name']);
    }

    public function test_a_file_of_unknown_size_is_refused()
    {
        $this->expectException(ImageSourceException::class);
        $this->expectExceptionMessage('does not tell the size');
        $this->fetcher()->probe($this->url('/chunked'));
    }

    public function test_a_redirect_into_the_stand_or_loopback_over_https_is_refused_and_so_is_a_loop()
    {
        foreach (['/to-private' => 'not allowed', '/to-loopback-https' => 'not allowed', '/loop' => 'Too many redirects', '/redirect/9' => 'Too many redirects'] as $path => $text) {
            try {
                $this->fetcher()->probe($this->url($path));
                $this->fail($path);
            } catch (ImageSourceException $e) {
                $this->assertStringContainsString($text, $e->getMessage(), $path);
                $this->assertTrue($e->fatal, $path);
            }
        }
    }

    public function test_http_errors_are_named_and_only_a_server_error_is_worth_another_try()
    {
        foreach ([404 => true, 403 => true, 410 => true, 500 => false, 503 => false] as $status => $fatal) {
            try {
                $this->fetcher()->probe($this->url('/status/'.$status));
                $this->fail((string) $status);
            } catch (ImageSourceException $e) {
                $this->assertStringContainsString((string) $status, $e->getMessage());
                $this->assertSame($fatal, $e->fatal, (string) $status);
            }
        }
    }

    public function test_a_message_has_no_query_even_if_the_server_refuses_a_signed_address()
    {
        try {
            $this->fetcher()->probe($this->url('/status/403?X-Amz-Signature=secret'));
            $this->fail();
        } catch (ImageSourceException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function test_the_connection_goes_to_the_address_the_guard_checked()
    {
        // the name is not in any DNS: only the resolver says where it is, and the fetch must use exactly that
        $guard = new ImageUrlGuard(fn (string $host) => $host === 'files.example.test' ? ['127.0.0.1'] : []);
        $fetcher = new ImageFetcher($guard);

        $result = $fetcher->download('http://files.example.test:'.self::$port.'/file', $this->dir.'/p.part', self::TOTAL, fn () => true, microtime(true) + 30);

        $this->assertTrue($result['done']);
        $this->assertSame($this->bytes(), file_get_contents($this->dir.'/p.part'));
    }

    // --- a small text file (a published checksum)

    public function test_a_small_text_file_comes_back_as_text_through_the_same_checks()
    {
        $this->assertSame(str_repeat('c', 64)."  os.img.gz\n", $this->fetcher()->text($this->url('/sum.sha256'), 65536));
    }

    public function test_a_text_file_over_the_limit_or_behind_a_private_redirect_is_refused()
    {
        foreach (['/big-text' => 'too large', '/to-private' => 'not allowed', '/status/404' => '404'] as $path => $text) {
            try {
                $this->fetcher()->text($this->url($path), 65536);
                $this->fail($path);
            } catch (ImageSourceException $e) {
                $this->assertStringContainsString($text, $e->getMessage(), $path);
            }
        }
    }

    // --- download

    public function test_a_file_comes_whole()
    {
        $result = $this->download('/file');

        $this->assertSame(['bytes' => self::TOTAL, 'done' => true, 'restarted' => false, 'cancelled' => false, 'ignoredRange' => false], $result);
        $this->assertSame($this->bytes(), file_get_contents($this->dir.'/image.part'));
    }

    public function test_a_fetch_goes_on_from_the_bytes_it_has()
    {
        file_put_contents($this->dir.'/image.part', $this->bytes(0, 1000000));

        $result = $this->download('/file');

        $this->assertTrue($result['done']);
        $this->assertFalse($result['restarted']);
        $this->assertSame($this->bytes(), file_get_contents($this->dir.'/image.part'));
    }

    public function test_a_server_that_ignores_the_range_starts_the_file_again_in_the_same_answer()
    {
        file_put_contents($this->dir.'/image.part', $this->bytes(0, 1000000));

        $result = $this->download('/no-range');

        $this->assertTrue($result['restarted']);
        $this->assertTrue($result['ignoredRange'], 'it says the server did not resume: the slices of a job cannot add up');
        $this->assertTrue($result['done']);
        $this->assertSame($this->bytes(), file_get_contents($this->dir.'/image.part'));
    }

    public function test_a_file_that_changed_size_starts_over_in_the_next_slice()
    {
        file_put_contents($this->dir.'/image.part', $this->bytes(0, 1000000));

        $result = $this->download('/changed');

        $this->assertSame(['bytes' => 0, 'done' => false, 'restarted' => true, 'cancelled' => false, 'ignoredRange' => false], $result);
        $this->assertFileExists($this->dir.'/image.part');
        $this->assertSame(0, filesize($this->dir.'/image.part'));
    }

    public function test_a_part_longer_than_the_file_starts_over()
    {
        file_put_contents($this->dir.'/image.part', str_repeat('x', self::TOTAL + 10));

        $result = $this->download('/file');

        $this->assertTrue($result['restarted']);
        $this->assertTrue($result['done']);
        $this->assertSame($this->bytes(), file_get_contents($this->dir.'/image.part'));
    }

    public function test_a_transfer_cut_short_keeps_what_arrived_and_the_next_try_goes_on()
    {
        try {
            $this->download('/truncate');
            $this->fail('the connection closed with bytes owed');
        } catch (ImageSourceException $e) {
            $this->assertFalse($e->fatal);
        }
        $kept = filesize($this->dir.'/image.part');
        $this->assertGreaterThan(0, $kept);
        $this->assertLessThan(self::TOTAL, $kept);
        $this->assertSame($this->bytes(0, $kept), file_get_contents($this->dir.'/image.part'));

        $result = $this->download('/file');

        $this->assertTrue($result['done']);
        $this->assertSame($this->bytes(), file_get_contents($this->dir.'/image.part'));
    }

    public function test_a_slice_ends_at_its_deadline_without_an_error_and_the_next_one_finishes()
    {
        $first = $this->download('/slowfile', null, self::TOTAL, null, microtime(true) + 0.3);

        $this->assertFalse($first['done']);
        $this->assertFalse($first['restarted']);
        $this->assertGreaterThan(0, $first['bytes']);
        $this->assertLessThan(self::TOTAL, $first['bytes']);
        $this->assertSame($first['bytes'], filesize($this->dir.'/image.part'));

        $second = $this->download('/file');
        $this->assertTrue($second['done']);
        $this->assertSame($this->bytes(), file_get_contents($this->dir.'/image.part'));
    }

    public function test_a_deadline_already_passed_does_no_harm()
    {
        $result = $this->download('/file', null, self::TOTAL, null, microtime(true) - 1);

        $this->assertFalse($result['done']);
        $this->assertSame(filesize($this->dir.'/image.part'), $result['bytes']);
    }

    public function test_a_transfer_that_stops_sending_is_an_error_after_the_stall_time()
    {
        $started = microtime(true);
        try {
            $this->download('/stall', null, 1000000, null, null, $this->fetcher(1));
            $this->fail('no data for longer than the stall time');
        } catch (ImageSourceException $e) {
            $this->assertStringContainsString('stalled', $e->getMessage());
            $this->assertFalse($e->fatal);
        }
        $this->assertLessThan(4, microtime(true) - $started);
        $this->assertSame(100000, filesize($this->dir.'/image.part'), 'what arrived stays for the next try');
    }

    public function test_the_progress_is_reported_and_can_stop_the_fetch()
    {
        $seen = [];
        $result = $this->download('/slowfile', null, self::TOTAL, function (int $bytes) use (&$seen) {
            $seen[] = $bytes;

            return count($seen) < 2;
        });

        $this->assertTrue($result['cancelled']);
        $this->assertFalse($result['done']);
        $this->assertGreaterThan(0, count($seen));
        $this->assertSame($seen, array_values(array_unique($seen)));
        $this->assertSame($seen, collect($seen)->sort()->values()->all(), 'it only grows');
    }

    public function test_an_http_error_while_fetching_is_not_hidden()
    {
        $this->expectException(ImageSourceException::class);
        $this->expectExceptionMessage('404');
        $this->download('/status/404');
    }

    public function test_an_unreachable_server_is_an_error_worth_another_try()
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $closed = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        try {
            $this->fetcher()->download('http://127.0.0.1:'.$closed.'/file', $this->dir.'/x.part', self::TOTAL, fn () => true, microtime(true) + 5);
            $this->fail('nothing listens there');
        } catch (ImageSourceException $e) {
            $this->assertFalse($e->fatal);
            $this->assertStringContainsString('connect', strtolower($e->getMessage()));
        }
    }
    public function test_a_server_that_sends_more_than_it_announced_is_stopped_before_it_fills_the_disk()
    {
        try {
            $this->download('/chunked', null, 150);   // 200 bytes come, 150 were announced
            $this->fail('more than announced');
        } catch (ImageSourceException $e) {
            $this->assertStringContainsString('more data than the size it announced', $e->getMessage());
            $this->assertTrue($e->fatal);
        }
        $this->assertLessThanOrEqual(150, filesize($this->dir.'/image.part'));
    }
}
