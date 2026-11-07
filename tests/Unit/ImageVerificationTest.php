<?php

namespace Tests\Unit;

use App\Services\ImageVerification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

class ImageVerificationTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        if (DIRECTORY_SEPARATOR === '\\') $this->markTestSkipped('requires POSIX decompressors');
        $this->path = tempnam(sys_get_temp_dir(), 'cmprov-verify-');
    }

    protected function tearDown(): void
    {
        if (isset($this->path)) @unlink($this->path);
        parent::tearDown();
    }

    #[DataProvider('formats')]
    public function test_verifies_the_unpacked_bytes_and_size(string $extension, string $command): void
    {
        $bytes = str_repeat('image bytes', 1000);
        $compress = new Process([$command, '-c']);
        $compress->setInput($bytes);
        $compress->mustRun();
        file_put_contents($this->path, $compress->getOutput());
        $result = (new ImageVerification)->uncompressed($this->path, $extension);
        $this->assertSame(hash('sha256', $bytes), $result['sha256']);
        $this->assertSame(strlen($bytes), $result['size']);
    }

    public static function formats(): array
    {
        return [['gz', 'gzip'], ['xz', 'xz'], ['bz2', 'bzip2']];
    }

    public function test_a_truncated_compressed_stream_is_refused_despite_readable_prefix_bytes(): void
    {
        file_put_contents($this->path, substr(gzencode('image bytes'), 0, -5));
        $this->expectException(RuntimeException::class);
        (new ImageVerification)->uncompressed($this->path, 'gz');
    }

    public function test_expansion_stops_at_the_verification_budget(): void
    {
        file_put_contents($this->path, gzencode(str_repeat('x', 100000)));
        $this->expectException(RuntimeException::class);
        (new ImageVerification)->uncompressed($this->path, 'gz', 1024);
    }

    public function test_xz_dictionary_cannot_exhaust_the_station_memory(): void
    {
        $compress = new Process(['xz', '--threads=1', '-0', '-c']);
        $compress->setInput('small image');
        $compress->mustRun();
        $bytes = $compress->getOutput();
        $length = (ord($bytes[12]) + 1) * 4;
        $header = substr($bytes, 12, $length);
        $this->assertSame("\x00\x21\x01", substr($header, 1, 3), 'the fixture has one LZMA2 filter and no optional size fields');
        // Increasing the permitted dictionary leaves the tiny payload valid and avoids building a huge compressor.
        $header[4] = "\x20"; // 256 MiB LZMA2 dictionary
        $header = substr($header, 0, -4).pack('V', crc32(substr($header, 0, -4)));
        file_put_contents($this->path, substr($bytes, 0, 12).$header.substr($bytes, 12 + $length));
        $this->expectException(RuntimeException::class);
        (new ImageVerification)->uncompressed($this->path, 'xz');
    }
}
