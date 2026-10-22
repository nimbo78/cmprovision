<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Image;
use App\Models\Project;
use App\Services\ImageSpace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** What a fetch needs from the disk of the provisioner, and what can be given back */
class ImageSpaceTest extends TestCase
{
    use RefreshDatabase;

    const GB = 1000000000;
    const GIB = 1073741824;

    protected function image(array $attributes = []): Image
    {
        $image = new Image;
        $image->filename = 'os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'space-'.uniqid().'.gz';
        $image->sha256 = str_repeat('a', 64);
        foreach ($attributes as $key => $value) {
            $image->$key = $value;
        }
        $image->save();

        return $image;
    }

    protected function space(int $free): ImageSpace
    {
        return new ImageSpace(fn () => $free);
    }

    public function test_a_file_needs_its_own_size_and_the_reserve_and_a_zip_the_room_for_the_repacked_file_too()
    {
        $space = $this->space(0);

        $this->assertSame(self::GB + 2 * self::GIB, $space->need(self::GB, false));
        $this->assertSame(self::GB + 1300000000 + 2 * self::GIB, $space->need(self::GB, true));
        config(['cmprovision.image_reserve_gb' => 5]);
        $this->assertSame(self::GB + 5 * self::GIB, $space->need(self::GB, false));
    }

    public function test_there_is_a_refusal_in_words_only_when_it_does_not_fit()
    {
        $this->assertNull($this->space(10 * self::GB)->refusal(self::GB));
        $this->assertSame(self::GB + 2 * self::GIB, $this->space(self::GB + 2 * self::GIB)->need(self::GB));
        $this->assertNull($this->space(self::GB + 2 * self::GIB)->refusal(self::GB), 'exactly enough');

        $text = $this->space(2 * self::GB)->refusal(self::GB);

        $this->assertStringStartsWith('Needs about 3.1 GB, 2.0 GB free.', $text);
        $this->assertStringContainsString('free space first', $text);
    }

    public function test_the_refusal_names_what_can_be_deleted()
    {
        $this->image(['filename' => 'old.img.gz']);   // in no project
        $text = $this->space(2 * self::GB)->refusal(self::GB);

        $this->assertStringContainsString('Delete unused images (1 image', $text);
    }

    public function test_fetches_already_on_their_way_count_for_what_they_still_need()
    {
        $this->image(['download_state' => 'downloading', 'download_total' => 4 * self::GB, 'download_bytes' => self::GB]);   // 3 GB to come
        $this->image(['download_state' => 'queued', 'download_total' => self::GB, 'download_bytes' => 0, 'filename_extension' => 'zip']);   // 1 GB and the room for its repacked file
        $this->image(['download_state' => 'failed', 'download_total' => 9 * self::GB, 'download_bytes' => 0, 'download_error' => 'x']);   // stopped: needs nothing
        $space = $this->space(100 * self::GB);

        $this->assertSame(3 * self::GB + self::GB + 1300000000, $space->committed());
        $own = $this->image(['download_state' => 'downloading', 'download_total' => 2 * self::GB, 'download_bytes' => 0]);
        $this->assertSame(3 * self::GB + self::GB + 1300000000 + 2 * self::GB, $space->committed());
        $this->assertSame(3 * self::GB + self::GB + 1300000000, $space->committed($own), 'a fetch does not count against itself');
    }

    public function test_a_refusal_counts_the_other_fetches_in_the_free_space()
    {
        $this->image(['download_state' => 'downloading', 'download_total' => 4 * self::GB, 'download_bytes' => 0]);

        $this->assertNotNull($this->space(5 * self::GB)->refusal(self::GB), '4 GB are promised to the other fetch: 1 GB and the reserve do not fit in what is left');
        $this->assertNull($this->space(8 * self::GB)->refusal(self::GB));
    }

    public function test_unused_images_are_the_ones_no_project_takes_and_nothing_is_fetching_or_writing()
    {
        $used = $this->image(['filename' => 'used.img.gz']);
        $unused = $this->image(['filename' => 'unused.img.gz']);
        $fetching = $this->image(['filename' => 'fetching.img.gz', 'download_state' => 'downloading']);
        $failed = $this->image(['filename' => 'failed.img.gz', 'download_state' => 'failed', 'download_error' => 'x']);
        $written = $this->image(['filename' => 'written.img.gz']);
        Project::create(['name' => 'p', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $used->id, 'label_moment' => 'never', 'verify' => false]);
        Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'durable_image_id' => $written->id, 'phase' => 'write']);

        $names = $this->space(0)->unused()->pluck('filename')->sort()->values()->all();

        $this->assertSame(['failed.img.gz', 'unused.img.gz'], $names, 'a failed fetch has nothing worth keeping; one a module is writing stays');
    }

    public function test_the_free_space_is_that_of_the_uploads_directory_unless_told_otherwise()
    {
        $this->assertSame((int) disk_free_space(public_path('uploads')), (new ImageSpace)->free());
        $this->assertSame(123, $this->space(123)->free());
    }
}
