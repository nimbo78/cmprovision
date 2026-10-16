<?php

namespace Tests\Feature;

use App\Jobs\SplitImage;
use App\Models\Image;
use App\Support\ImagePieces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The pieces copy of an image (2.6.1): the first MiB and then each piece as a gzip member of its own, checked against the
    image before it counts; the original stays as it is */
class SplitImageTest extends TestCase
{
    use RefreshDatabase;

    const MIB = 1048576;

    protected $images = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['bash', 'split', 'stat', 'gzip', 'xz'] as $tool)
            if (trim((string) shell_exec('command -v '.$tool.' 2>/dev/null')) === '')
                $this->markTestSkipped('needs '.$tool);
        config(['cmprovision.split_piece_mb' => 1]);
    }

    protected function tearDown(): void
    {
        foreach ($this->images as $image)
        {
            $image = Image::find($image->id) ?: $image;
            foreach ([$image->imagepath(), $image->splitPath(), SplitImage::temp($image), SplitImage::temp($image).'.index'] as $file)
                if ($file && is_file($file))
                    unlink($file);
        }
        parent::tearDown();
    }

    protected static function diskImage(int $size): string
    {
        $data = str_repeat("\x55", 512).str_repeat("\0", self::MIB - 512);
        for ($i = 0; strlen($data) < $size; $i++)
            $data .= $i % 64 < 48 ? hash('sha256', 'block '.$i, true) : str_repeat("\0", 32);
        return substr($data, 0, $size);
    }

    protected function image(string $data, string $kind = 'gz', array $attributes = [])
    {
        $image = new Image;
        $image->filename = 'os.img.'.$kind;
        $image->filename_extension = $kind;
        $image->filename_on_server = 'split-'.uniqid().'.'.$kind;
        $image->sha256 = str_repeat('a', 64);
        foreach ($attributes as $key => $value)
            $image->$key = $value;
        $image->save();
        $this->images[] = $image;
        if ($kind === 'gz')
            file_put_contents($image->imagepath(), gzencode($data, 6));
        else
        {
            file_put_contents($image->imagepath().'.raw', $data);
            exec('/usr/bin/xz -c '.escapeshellarg($image->imagepath().'.raw').' > '.escapeshellarg($image->imagepath()));
            unlink($image->imagepath().'.raw');
        }
        return $image;
    }

    /** handle() as the worker runs it: an exception goes to failed() */
    protected function runJob(SplitImage $job)
    {
        try
        {
            $job->handle();
        }
        catch (\RuntimeException $e)
        {
            $job->failed($e);
        }
    }

    public function test_the_copy_holds_the_first_mib_and_then_each_piece_as_a_gzip_member_of_its_own()
    {
        $data = self::diskImage(4 * self::MIB + self::MIB / 2);
        $image = $this->image($data);

        $this->runJob(new SplitImage($image));

        $image = $image->fresh();
        $this->assertTrue($image->splitReady());
        $this->assertSame(1, $image->split_piece_mb);
        $copy = file_get_contents($image->splitPath());
        $offsets = $image->splitOffsets();
        $this->assertCount(6, $offsets, 'the first MiB, four pieces and the size');
        $this->assertSame(strlen($copy), end($offsets));
        $members = [];
        for ($i = 0; $i < count($offsets) - 1; $i++)
            $members[] = gzdecode(substr($copy, $offsets[$i], $offsets[$i + 1] - $offsets[$i]));
        $this->assertSame(substr($data, 0, self::MIB), $members[0]);
        $this->assertSame([self::MIB, self::MIB, self::MIB, self::MIB / 2], array_map('strlen', array_slice($members, 1)));
        $this->assertSame($data, implode('', $members));
        $this->assertSame($data, shell_exec('gzip -dc '.escapeshellarg($image->splitPath())), 'one stream for gzip too');
        $this->assertSame([hash('sha256', $data), strlen($data)], [$image->uncompressed_sha256, $image->uncompressed_size], 'filled in');
        $this->assertSame([null, null], [$image->split_error, $image->split_queued_at]);
        $this->assertFileDoesNotExist(SplitImage::temp($image));
        $this->assertFileExists($image->imagepath(), 'the original stays');
    }

    public function test_an_xz_image_gets_a_gzip_copy_of_the_same_image()
    {
        $data = self::diskImage(2 * self::MIB + 100);
        $image = $this->image($data, 'xz');

        $this->runJob(new SplitImage($image));

        $image = $image->fresh();
        $this->assertTrue($image->splitReady());
        $this->assertStringEndsWith('.gz', $image->split_filename);
        $this->assertSame($data, shell_exec('gzip -dc '.escapeshellarg($image->splitPath())));
    }

    public function test_an_image_that_changed_since_its_sha256_gets_no_copy()
    {
        $image = $this->image(self::diskImage(2 * self::MIB), 'gz', ['uncompressed_sha256' => str_repeat('f', 64)]);

        $this->runJob(new SplitImage($image));

        $image = $image->fresh();
        $this->assertFalse($image->splitReady());
        $this->assertSame('the image file changed since its SHA256 was computed', $image->split_error);
        $this->assertSame([], glob(public_path('uploads/.split-'.$image->id.'*')) ?: [], 'no work files left');
    }

    public function test_without_room_for_the_copy_the_job_does_not_start()
    {
        config(['cmprovision.split_space_factor' => 1e15]);
        $image = $this->image(self::diskImage(2 * self::MIB));

        $this->runJob(new SplitImage($image));

        $this->assertStringStartsWith('not enough free space: need about ', $image->fresh()->split_error);
    }

    public function test_a_run_cut_off_by_a_worker_restart_is_queued_again_a_few_times()
    {
        Queue::fake();
        $image = $this->image(self::diskImage(2 * self::MIB));
        file_put_contents(SplitImage::temp($image), 'half');

        (new SplitImage($image))->failed(new MaxAttemptsExceededException('cut off'));

        $this->assertFileDoesNotExist(SplitImage::temp($image));
        Queue::assertPushed(SplitImage::class, function ($job) use ($image) {
            return $job->image->id === $image->id && $job->restarts === 1;
        });
        $this->assertNull($image->fresh()->split_error);

        (new SplitImage($image, SplitImage::RESTARTS))->failed(new MaxAttemptsExceededException('cut off'));

        $this->assertSame('cut off', $image->fresh()->split_error);
    }

    public function test_a_run_that_timed_out_is_not_queued_again()
    {
        Queue::fake();
        $image = $this->image(self::diskImage(2 * self::MIB));

        (new SplitImage($image))->failed(new TimeoutExceededException('App\Jobs\SplitImage has timed out.'));

        Queue::assertNothingPushed();
        $this->assertSame('App\Jobs\SplitImage has timed out.', $image->fresh()->split_error);
    }

    public function test_a_run_queued_again_counts_as_preparing_from_then()
    {
        Queue::fake();
        $image = $this->image(self::diskImage(2 * self::MIB));
        $image->split_queued_at = now()->subSeconds(ImagePieces::SPLIT_TIMEOUT + 60);
        $image->save();

        (new SplitImage($image))->failed(new MaxAttemptsExceededException('cut off'));

        $this->assertTrue($image->fresh()->splitPreparing(), 'no Retry while the next run waits for its turn');
    }

    public function test_the_copy_is_queued_once_on_the_images_queue()
    {
        Queue::fake();
        $image = $this->image(self::diskImage(2 * self::MIB));

        $image->queueSplit();
        $image->fresh()->queueSplit();

        Queue::assertPushedOn('images', SplitImage::class);
        Queue::assertPushed(SplitImage::class, 1);
        $this->assertNotNull($image->fresh()->split_queued_at);
    }
}
