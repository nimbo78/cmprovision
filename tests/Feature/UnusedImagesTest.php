<?php

namespace Tests\Feature;

use App\Http\Livewire\Images;
use App\Models\Cm;
use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The disk of the provisioner is small: images no project takes can be deleted all at once, never one that is in use */
class UnusedImagesTest extends TestCase
{
    use RefreshDatabase;

    protected $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    protected function image(string $name, int $bytes = 1000, array $attributes = []): Image
    {
        $image = new Image;
        $image->filename = $name;
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'unused-'.uniqid().'.gz';
        $image->sha256 = str_repeat('a', 64);
        foreach ($attributes as $key => $value) {
            $image->$key = $value;
        }
        $image->save();
        file_put_contents($image->imagepath(), str_repeat('x', $bytes));
        $this->files[] = $image->imagepath();

        return $image;
    }

    protected function project(Image $image): Project
    {
        return Project::create(['name' => 'p'.$image->id, 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false]);
    }

    public function test_the_page_offers_to_delete_the_unused_images_with_their_number_and_size_and_nothing_when_all_are_in_use()
    {
        $used = $this->image('used.img.gz', 1000);
        $this->project($used);
        Livewire::test(Images::class)->assertDontSee('Delete unused images');

        $this->image('old-1.img.gz', 1500000);
        $second = $this->image('old-2.img.gz', 500000);
        $second->split_filename = 'pieces-'.uniqid().'.gz';
        $second->save();
        file_put_contents($second->splitPath(), str_repeat('y', 2000000));
        $this->files[] = $second->splitPath();

        Livewire::test(Images::class)
            ->assertSee('Delete unused images (2, 4 MB)')
            ->assertSeeHtml('wire:confirm="Delete 2 unused images (4 MB)?');
    }

    public function test_it_deletes_exactly_the_unused_ones_with_their_files_and_pieces_and_says_how_much_it_freed()
    {
        $used = $this->image('used.img.gz');
        $this->project($used);
        $unusedA = $this->image('old-a.img.gz');
        $unusedB = $this->image('old-b.img.gz', 1000, ['split_filename' => 'pieces-'.uniqid().'.gz']);
        file_put_contents($unusedB->splitPath(), 'pieces');
        $this->files[] = $unusedB->splitPath();
        $failed = $this->image('failed.img.gz', 10, ['download_state' => 'failed', 'download_error' => 'x', 'source_type' => 'url']);
        file_put_contents($failed->imagepath().'.part', 'part');
        $fetching = $this->image('fetching.img.gz', 10, ['download_state' => 'downloading', 'source_type' => 'url']);
        $writing = $this->image('writing.img.gz');
        Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'phase' => 'write', 'durable_image_id' => $writing->id]);
        $verifying = $this->image('verifying.img.gz');
        Cm::create(['serial' => '10000000e611110d', 'mac' => 'e4:5f:01:62:9f:d4', 'phase' => 'verify', 'image_filename' => 'verifying.img.gz']);

        Livewire::test(Images::class)->call('deleteUnused')->assertSee('Deleted 3 unused images');

        $this->assertSame(['fetching.img.gz', 'used.img.gz', 'verifying.img.gz', 'writing.img.gz'], Image::orderBy('filename')->pluck('filename')->all());
        $this->assertFileDoesNotExist($unusedA->imagepath());
        $this->assertFileDoesNotExist($unusedB->imagepath());
        $this->assertFileDoesNotExist($unusedB->splitPath());
        $this->assertFileDoesNotExist($failed->imagepath().'.part');
        $this->assertFileExists($used->imagepath());
        $this->assertFileExists($writing->imagepath());
        $this->assertFileExists($verifying->imagepath());
        $this->assertFileExists($fetching->imagepath());
    }

    public function test_what_is_unused_is_decided_when_the_button_is_pressed_not_when_the_page_was_drawn()
    {
        $image = $this->image('old.img.gz');
        $page = Livewire::test(Images::class)->assertSee('Delete unused images (1,');
        $this->project($image);   // a project takes it in another tab meanwhile

        $page->call('deleteUnused')->assertSee('Nothing unused to delete');

        $this->assertNotNull(Image::find($image->id));
        $this->assertFileExists($image->imagepath());
    }

    public function test_nothing_unused_deletes_nothing_and_says_so()
    {
        $this->project($this->image('used.img.gz'));

        Livewire::test(Images::class)->call('deleteUnused')->assertSee('Nothing unused to delete');

        $this->assertSame(1, Image::count());
    }

    public function test_the_singular_is_the_singular()
    {
        $this->image('old.img.gz', 2000000);

        Livewire::test(Images::class)->assertSee('Delete unused images (1, 2 MB)')->call('deleteUnused')->assertSee('Deleted 1 unused image (2 MB)');
    }
}
