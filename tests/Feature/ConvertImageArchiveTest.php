<?php

namespace Tests\Feature;

use App\Jobs\ComputeSHA256;
use App\Jobs\ConvertImageArchive;
use App\Models\Image;
use App\Services\ImageDownloads;
use App\Services\ImageSpace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** A .zip image is repacked into an .img.gz on the server: the module can only stream gz, xz and bz2 */
class ConvertImageArchiveTest extends TestCase
{
    use RefreshDatabase;

    const GB = 1000000000;

    protected $files = [];
    protected $disk;
    protected $work;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(ImageSpace::class, new ImageSpace(fn () => 100 * self::GB));
        // an "image" of 3 MiB: a partition table's worth of structure, then something that compresses and something that does not
        $this->disk = str_repeat("\x55\xaa", 256).str_repeat("\0", 524288 - 512).random_bytes(262144).str_repeat('MARKER-of-the-data ', 100000);
        $this->work = sys_get_temp_dir().'/cmprovision-zip-'.uniqid();
        mkdir($this->work);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
            @unlink($file.'.part');
            @unlink($file.'.gz.part');
        }
        exec('rm -rf '.escapeshellarg($this->work));
        parent::tearDown();
    }

    /** an archive with the given entries (name => content, null for a directory); stored, not deflated, when $store */
    protected function archive(array $entries, bool $store = false): string
    {
        $path = $this->work.'/a-'.uniqid().'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            if ($content === null) {
                $zip->addEmptyDir($name);
            } else {
                $zip->addFromString($name, $content);
                if ($store) {
                    $zip->setCompressionName($name, \ZipArchive::CM_STORE);
                }
            }
        }
        $zip->close();

        return $path;
    }

    /** an image being repacked: the archive is its file */
    protected function repacking(string $archive, array $attributes = []): Image
    {
        $image = new Image;
        $image->filename = 'wlanpi-os-v3.4.4.zip';
        $image->filename_extension = 'zip';
        $image->filename_on_server = 'zip-'.uniqid().'.zip';
        $image->sha256 = '';
        $image->source_type = 'github';
        $image->download_state = 'converting';
        $image->download_bytes = 0;
        $image->download_total = filesize($archive);
        foreach ($attributes as $key => $value) {
            $image->$key = $value;
        }
        $image->save();
        copy($archive, $image->imagepath());
        $this->files[] = $image->imagepath();

        return $image;
    }

    protected function convert(Image $image): Image
    {
        app()->call([new ConvertImageArchive($image), 'handle']);
        $fresh = Image::find($image->id);
        if ($fresh && $fresh->filename_extension === 'gz') {
            $this->files[] = $fresh->imagepath();
        }

        return $fresh;
    }

    public function test_the_image_in_the_archive_becomes_an_img_gz_that_unpacks_to_the_same_bytes_and_the_archive_goes()
    {
        $archive = $this->archive(['wlanpi-os-v3.4.4.img' => $this->disk]);
        $image = $this->repacking($archive);
        $zipPath = $image->imagepath();

        $fresh = $this->convert($image);

        $this->assertSame(['wlanpi-os-v3.4.4.img.gz', 'gz', 'checking', ''], [$fresh->filename, $fresh->filename_extension, $fresh->download_state, $fresh->sha256]);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.gz$/', $fresh->filename_on_server);
        $this->assertFileExists($fresh->imagepath());
        $this->assertSame($this->disk, gzdecode(file_get_contents($fresh->imagepath())));
        $this->assertFileDoesNotExist($zipPath, 'the archive is deleted');
        $this->assertFileDoesNotExist($zipPath.'.gz.part');
        $this->assertNull($fresh->expected_sha256, 'the sum of the archive is spent: the next checks are of the new file');
        $this->assertSame(filesize($fresh->imagepath()), $fresh->download_total);
        Queue::assertPushedOn('images', ComputeSHA256::class, fn ($job) => $job->image->is($image));
    }

    public function test_the_archive_is_checked_against_its_published_checksum_before_anything_is_unpacked()
    {
        $archive = $this->archive(['os.img' => $this->disk]);
        $wrong = $this->repacking($archive, ['expected_sha256' => str_repeat('0', 64)]);
        $wrongPath = $wrong->imagepath();

        $failed = $this->convert($wrong);

        $this->assertSame('failed', $failed->download_state);
        $this->assertStringContainsString(str_repeat('0', 64), $failed->download_error);
        $this->assertStringContainsString(hash_file('sha256', $archive), $failed->download_error);
        $this->assertFileDoesNotExist($wrongPath, 'a wrong archive is not kept');
        Queue::assertNothingPushed();

        $right = $this->convert($this->repacking($archive, ['expected_sha256' => strtoupper(hash_file('sha256', $archive))]));
        $this->assertSame('checking', $right->download_state);
    }

    public function test_exactly_one_image_must_be_in_the_archive()
    {
        $cases = [
            'two' => [['a.img' => 'x', 'b.img' => 'y'], 'exactly one .img file (found: a.img, b.img)'],
            'none' => [['readme.txt' => 'x', 'folder/' => null], 'exactly one .img file (found: none)'],
        ];
        foreach ($cases as $name => [$entries, $text]) {
            $image = $this->repacking($this->archive($entries));
            $path = $image->imagepath();

            $failed = $this->convert($image);

            $this->assertSame('failed', $failed->download_state, $name);
            $this->assertStringContainsString($text, $failed->download_error, $name);
            $this->assertFileExists($path, 'the archive stays: it may be a mistake of the source');
        }
    }

    public function test_folders_and_the_clutter_of_a_mac_are_not_images_and_the_name_may_be_in_a_folder_in_any_case()
    {
        $archive = $this->archive(['__MACOSX/' => null, '__MACOSX/._os.img' => 'resource fork', 'os/' => null, 'os/DISK.IMG' => $this->disk, 'os/readme.txt' => 'x']);

        $fresh = $this->convert($this->repacking($archive));

        $this->assertSame('checking', $fresh->download_state, (string) $fresh->download_error);
        $this->assertSame($this->disk, gzdecode(file_get_contents($fresh->imagepath())));
    }

    public function test_an_image_whose_bytes_do_not_match_the_checksum_of_the_archive_entry_is_never_taken()
    {
        $archive = $this->archive(['os.img' => $this->disk], true);   // stored: the bytes are in the file as they are
        $bytes = file_get_contents($archive);
        $at = strpos($bytes, 'MARKER-of-the-data');
        $this->assertNotFalse($at);
        file_put_contents($archive, substr_replace($bytes, 'X', $at, 1));   // one byte of the data is damaged
        $image = $this->repacking($archive);

        $failed = $this->convert($image);

        $this->assertSame('failed', $failed->download_state);
        $this->assertStringContainsString('does not match its checksum', $failed->download_error);
        $this->assertFileDoesNotExist($image->imagepath().'.gz.part');
        $this->assertFileDoesNotExist($image->imagepath(), 'a damaged archive is not kept: Retry fetches it again');
        $this->assertSame('zip', $failed->filename_extension);
        Queue::assertNothingPushed();
    }

    public function test_something_that_is_no_archive_is_said_so()
    {
        $path = $this->work.'/not.zip';
        file_put_contents($path, 'this is not a zip file at all');

        $image = $this->repacking($path);
        $failed = $this->convert($image);

        $this->assertSame('failed', $failed->download_state);
        $this->assertStringContainsString('not a readable zip archive', $failed->download_error);
        $this->assertFileDoesNotExist($image->imagepath());
    }

    public function test_no_room_for_the_repacked_file_stops_it_before_it_starts_and_keeps_the_archive()
    {
        $this->app->instance(ImageSpace::class, new ImageSpace(fn () => 1000));
        $image = $this->repacking($this->archive(['os.img' => $this->disk]));

        $failed = $this->convert($image);

        $this->assertSame('failed', $failed->download_state);
        $this->assertStringContainsString('Needs about', $failed->download_error);
        $this->assertFileExists($image->imagepath());
        $this->assertFileDoesNotExist($image->imagepath().'.gz.part');
    }

    public function test_a_job_for_a_deleted_or_no_longer_repacking_image_does_nothing()
    {
        $archive = $this->archive(['os.img' => $this->disk]);
        $gone = $this->repacking($archive);
        $gone->delete();
        $done = $this->repacking($archive, ['download_state' => null, 'source_type' => null]);

        app()->call([new ConvertImageArchive($gone), 'handle']);
        app()->call([new ConvertImageArchive($done), 'handle']);

        $this->assertSame('zip', Image::find($done->id)->filename_extension);
        Queue::assertNothingPushed();
    }

    public function test_a_job_that_dies_fails_the_image_instead_of_leaving_it_repacking()
    {
        $image = $this->repacking($this->archive(['os.img' => $this->disk]));

        (new ConvertImageArchive($image))->failed(new \RuntimeException('killed'));

        $fresh = Image::find($image->id);
        $this->assertSame('failed', $fresh->download_state);
        $this->assertStringContainsString('killed', $fresh->download_error);
    }

    public function test_deleting_the_image_takes_the_half_made_file_with_it()
    {
        $image = $this->repacking($this->archive(['os.img' => $this->disk]));
        file_put_contents($image->imagepath().'.gz.part', 'half');

        $image->delete();

        $this->assertFileDoesNotExist($image->imagepath().'.gz.part');
        $this->assertFileDoesNotExist($image->imagepath());
    }

    public function test_retry_of_a_failed_repacking_starts_it_again_from_the_archive_that_stayed()
    {
        $image = $this->repacking($this->archive(['a.img' => 'x', 'b.img' => 'y']));
        $this->convert($image);   // fails: two images

        app(ImageDownloads::class)->retry(Image::find($image->id));

        $fresh = Image::find($image->id);
        $this->assertSame(['converting', null], [$fresh->download_state, $fresh->download_error]);
        Queue::assertPushedOn('images', ConvertImageArchive::class, fn ($job) => $job->image->is($image));
        Queue::assertNotPushed(ComputeSHA256::class);
    }

    public function test_the_progress_is_kept_as_a_share_of_the_archives_size()
    {
        $archive = $this->archive(['os.img' => $this->disk]);
        $image = $this->repacking($archive);
        ConvertImageArchive::$progressEvery = 0.0;
        try {
            $seen = [];
            ConvertImageArchive::$onProgress = function (int $bytes, int $total) use (&$seen, $image) {
                $seen[] = Image::find($image->id)->download_bytes;
            };
            $this->convert($image);
        } finally {
            ConvertImageArchive::$progressEvery = 2.0;
            ConvertImageArchive::$onProgress = null;
        }

        $this->assertNotEmpty($seen);
        $this->assertSame($seen, collect($seen)->sort()->values()->all(), 'it only grows');
        $this->assertLessThanOrEqual(filesize($archive), max($seen));
    }
}
