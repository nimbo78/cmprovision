<?php

namespace Tests\Feature;

use App\Jobs\ComputeSHA256;
use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The checks that end the chain of a fetched image: what it is must be what was published; only then is it ready */
class ImageVerifyTest extends TestCase
{
    use RefreshDatabase;

    protected $files = [];
    protected $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = str_repeat('disk image ', 100000);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    protected function checking(array $attributes = [], ?string $content = null): Image
    {
        $image = new Image;
        $image->filename = 'os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'verify-'.uniqid().'.gz';
        $image->sha256 = '';
        $image->source_type = 'github';
        $image->download_state = 'checking';
        foreach ($attributes as $key => $value) {
            $image->$key = $value;
        }
        $image->save();
        $this->files[] = $image->imagepath();
        file_put_contents($image->imagepath(), $content ?? gzencode($this->disk));

        return $image;
    }

    protected function check(Image $image): Image
    {
        (new ComputeSHA256($image))->handle();

        return Image::find($image->id);
    }

    public function test_what_matches_what_was_published_is_ready_with_both_hashes()
    {
        $content = gzencode($this->disk);
        $image = $this->checking(['expected_sha256' => hash('sha256', $content), 'expected_uncompressed_sha256' => hash('sha256', $this->disk)], $content);

        $fresh = $this->check($image);

        $this->assertTrue($fresh->isReady());
        $this->assertNull($fresh->download_state);
        $this->assertSame([hash('sha256', $content), hash('sha256', $this->disk), strlen($this->disk)], [$fresh->sha256, $fresh->uncompressed_sha256, $fresh->uncompressed_size]);
    }

    public function test_an_image_without_a_published_checksum_is_ready_once_it_has_its_hashes()
    {
        $fresh = $this->check($this->checking(['checksum_source' => 'none']));

        $this->assertTrue($fresh->isReady());
        $this->assertSame(hash('sha256', $this->disk), $fresh->uncompressed_sha256);
    }

    public function test_a_file_that_is_not_the_published_one_fails_at_once_and_is_deleted()
    {
        $content = gzencode($this->disk);
        $image = $this->checking(['expected_sha256' => str_repeat('0', 64)], $content);

        $fresh = $this->check($image);

        $this->assertSame('failed', $fresh->download_state);
        $this->assertStringContainsString(str_repeat('0', 64), $fresh->download_error);
        $this->assertStringContainsString(hash('sha256', $content), $fresh->download_error, 'it says what the file really is');
        $this->assertFileDoesNotExist($image->imagepath(), 'a wrong file is not kept');
        $this->assertNull($fresh->uncompressed_sha256, 'the decompression did not even start');
        $this->assertFalse($fresh->isReady());
    }

    public function test_an_unpacked_image_that_is_not_the_published_one_fails_and_is_deleted()
    {
        $image = $this->checking(['expected_uncompressed_sha256' => str_repeat('1', 64)]);

        $fresh = $this->check($image);

        $this->assertSame('failed', $fresh->download_state);
        $this->assertStringContainsString('unpacked image does not match', $fresh->download_error);
        $this->assertStringContainsString(str_repeat('1', 64), $fresh->download_error);
        $this->assertFileDoesNotExist($image->imagepath());
    }

    public function test_an_error_in_the_check_fails_the_image_and_never_leaves_it_ready_without_hashes()
    {
        $image = $this->checking(['filename_extension' => 'xz', 'filename_on_server' => 'verify-'.uniqid().'.xz'], 'not an xz file at all');
        try {
            (new ComputeSHA256($image))->handle();
            $this->fail('xz cannot read that');
        } catch (\Exception $e) {
            (new ComputeSHA256($image))->failed($e);
        }

        $fresh = Image::find($image->id);
        $this->assertSame('failed', $fresh->download_state);
        $this->assertStringContainsString('Checking the image failed', $fresh->download_error);
        $this->assertFalse($fresh->isReady());
    }

    public function test_an_uploaded_image_is_not_touched_by_any_of_this()
    {
        $image = $this->checking(['source_type' => null, 'download_state' => null, 'expected_sha256' => str_repeat('0', 64)]);

        $fresh = $this->check($image);

        $this->assertNull($fresh->download_state);
        $this->assertNull($fresh->download_error);
        $this->assertFileExists($image->imagepath());
        $this->assertSame(hash('sha256', $this->disk), $fresh->uncompressed_sha256);
    }

    public function test_a_failure_of_the_job_of_an_uploaded_image_changes_nothing()
    {
        $image = $this->checking(['source_type' => null, 'download_state' => null]);

        (new ComputeSHA256($image))->failed(new \RuntimeException('x'));

        $this->assertNull(Image::find($image->id)->download_state);
    }

    public function test_a_job_for_an_image_that_was_deleted_meanwhile_is_dropped()
    {
        $this->assertTrue((new ComputeSHA256(new Image))->deleteWhenMissingModels);
    }
}
