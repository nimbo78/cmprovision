<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Image;
use App\Models\Project;
use App\Support\ImagePieces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 2.6.1: the columns of the image resume, the pieces arithmetic, the pieces copy of an image, and the speed of a write that
    went on from where it stopped */
class ImageResumeDataTest extends TestCase
{
    use RefreshDatabase;

    const MIB = 1048576;

    protected $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file)
            if ($file && is_file($file))
                unlink($file);
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function image(array $attributes = [])
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'resume-'.uniqid().'.gz';
        $image->sha256 = str_repeat('a', 64);
        foreach ($attributes as $key => $value)
            $image->$key = $value;
        $image->save();
        return $image;
    }

    protected function project(Image $image, bool $split)
    {
        return Project::create(['name' => 'p'.uniqid(), 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false, 'os_check' => false, 'split_image' => $split]);
    }

    public function test_the_new_columns_start_empty()
    {
        $image = $this->image();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false, 'os_check' => false]);
        $cm = Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3']);

        $this->assertFalse($project->fresh()->split_image);
        $image = $image->fresh();
        $this->assertSame([null, null, null, null, null],
            [$image->split_filename, $image->split_offsets, $image->split_piece_mb, $image->split_error, $image->split_queued_at]);
        $cm = $cm->fresh();
        $this->assertSame([null, null, null], [$cm->durable_bytes, $cm->durable_image_id, $cm->progress_base]);
    }

    public function test_the_pieces_arithmetic()
    {
        $this->assertSame(512, ImagePieces::pieceMb());
        $this->assertSame(0, ImagePieces::count(self::MIB, 1), 'the first MiB alone has no pieces');
        $this->assertSame(1, ImagePieces::count(self::MIB + 1, 1));
        $this->assertSame(4, ImagePieces::count(4 * self::MIB + self::MIB / 2, 1));
        $this->assertSame(12, ImagePieces::count(6200000000, 512));
        $this->assertSame(0, ImagePieces::resumePiece(100, 1));
        $this->assertSame(1, ImagePieces::resumePiece(2 * self::MIB + 10, 1));
        $this->assertSame(2, ImagePieces::resumePiece(3 * self::MIB, 1));
        $this->assertSame(self::MIB + 2 * 512 * self::MIB, ImagePieces::pieceStart(2, 512));
        $this->assertSame('3.5 GB', ImagePieces::gb(3500000000));
        $this->assertSame('Resumed after a restart at 3.5 GB of 6.2 GB', ImagePieces::resumedDetail(3500000000, 6200000000));
    }

    public function test_a_copy_is_ready_only_with_its_offsets_and_its_file()
    {
        $image = $this->image(['split_filename' => 'pieces-'.uniqid().'.gz', 'split_offsets' => json_encode([0, 40, 90, 120]),
            'split_piece_mb' => 1]);
        $this->files[] = $image->splitPath();
        $this->assertFalse($image->splitReady(), 'no file');

        file_put_contents($image->splitPath(), str_repeat('x', 120));
        $this->assertTrue($image->splitReady());
        $this->assertSame([0, 40, 90, 120], $image->splitOffsets());

        $image->split_offsets = json_encode([0, 120]);
        $this->assertFalse($image->splitReady(), 'not even one piece');
    }

    public function test_a_copy_on_its_way_counts_only_within_the_job_timeout()
    {
        Carbon::setTestNow('2026-10-16 10:00:00');
        $image = $this->image(['split_queued_at' => now()->subSeconds(ImagePieces::SPLIT_TIMEOUT - 60)]);
        $this->assertTrue($image->splitPreparing());

        $image->split_queued_at = now()->subSeconds(ImagePieces::SPLIT_TIMEOUT + 60);
        $this->assertFalse($image->splitPreparing());
    }

    public function test_removing_the_pieces_or_the_image_removes_the_copy()
    {
        $image = $this->image(['split_filename' => 'pieces-'.uniqid().'.gz', 'split_offsets' => json_encode([0, 4, 8]),
            'split_piece_mb' => 1]);
        $this->files[] = $copy = $image->splitPath();
        file_put_contents($copy, 'xxxxxxxx');

        $image->removeSplit();

        $this->assertFileDoesNotExist($copy);
        $image = $image->fresh();
        $this->assertSame([null, null, null], [$image->split_filename, $image->split_offsets, $image->split_piece_mb]);

        $image->split_filename = 'pieces-'.uniqid().'.gz';
        $image->save();
        $this->files[] = $copy = $image->splitPath();
        file_put_contents($copy, 'x');

        $image->delete();

        $this->assertFileDoesNotExist($copy);
    }

    public function test_a_project_needs_the_copy_of_its_image_only_with_the_checkbox()
    {
        $image = $this->image();
        $this->project($image, false);
        $this->assertFalse($image->splitNeeded());

        $this->project($image, true);
        $this->assertTrue($image->splitNeeded());
    }

    public function test_a_write_that_went_on_counts_its_speed_from_where_it_went_on()
    {
        Carbon::setTestNow('2026-10-16 10:00:00');
        $cm = new Cm(['serial' => '10000000e611110c', 'phase' => 'write', 'progress_total' => 6000000000,
            'progress_base' => 3000000000, 'progress_bytes' => 4000000000,
            'phase_started_at' => now()->subSeconds(100), 'progress_updated_at' => now()]);

        $this->assertSame(66, $cm->progressPercent(), 'of the whole image');
        $this->assertEqualsWithDelta(10000000, $cm->bytesPerSecond(), 0.001, 'only what this run wrote');
        $this->assertSame(200, $cm->secondsLeft());
    }

    public function test_entering_a_phase_forgets_where_the_write_went_on_from()
    {
        $cm = new Cm(['serial' => '10000000e611110c', 'phase' => 'write', 'progress_base' => 3000000000, 'progress_bytes' => 4000000000]);

        $cm->setPhase('verify');

        $this->assertSame([null, null], [$cm->progress_base, $cm->progress_bytes]);
    }
}
