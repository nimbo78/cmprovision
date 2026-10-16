<?php

namespace Tests\Feature;

use App\Http\Livewire\Images;
use App\Http\Livewire\Projects;
use App\Jobs\SplitImage;
use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** 2.6.1: the project's checkbox "Split the image into pieces" (form and API) and the pieces copy on the Images page */
class ImagePiecesPagesTest extends TestCase
{
    use RefreshDatabase;

    protected $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file)
            if ($file && is_file($file))
                unlink($file);
        parent::tearDown();
    }

    protected function image(string $extension = 'gz')
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.'.$extension;
        $image->filename_extension = $extension;
        $image->filename_on_server = 'pages-'.uniqid().'.'.$extension;
        $image->sha256 = str_repeat('a', 64);
        $image->save();
        return $image;
    }

    protected function copy(Image $image)
    {
        $image->split_filename = 'pieces-'.uniqid().'.gz';
        $image->split_offsets = json_encode([0, 4, 8]);
        $image->split_piece_mb = 1;
        $image->split_queued_at = null;
        $image->save();
        $this->files[] = $image->splitPath();
        file_put_contents($image->splitPath(), 'xxxxxxxx');
    }

    protected function project(Image $image, bool $split = true)
    {
        return Project::create(['name' => 'p'.uniqid(), 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false, 'os_check' => false, 'split_image' => $split]);
    }

    public function test_the_project_form_keeps_the_checkbox_and_has_the_copy_made()
    {
        Queue::fake();
        $image = $this->image();

        Livewire::test(Projects::class)->call('create')->set('name', '78')->set('active', false)
            ->set('image_id', $image->id)->set('split_image', true)->call('store')->assertHasNoErrors();

        $project = Project::where('name', '78')->firstOrFail();
        $this->assertTrue($project->split_image);
        Queue::assertPushedOn('images', SplitImage::class);
        Livewire::test(Projects::class)->call('edit', $project->id)->assertSet('split_image', true)
            ->assertSee('Split the image into 512 MB pieces')
            ->assertSee('A module restarted during the write continues from the last piece it wrote.')
            ->assertSee('Pieces of wlanpi-os.img.gz: preparing…');
    }

    public function test_an_xz_image_says_its_pieces_are_a_gzip_copy()
    {
        $image = $this->image('xz');

        Livewire::test(Projects::class)->call('create')->set('image_id', $image->id)->set('split_image', true)
            ->assertSee('The pieces are a gzip copy of this xz image: the download grows.');
    }

    public function test_the_api_turns_the_checkbox_on_and_has_the_copy_made()
    {
        Queue::fake();
        $project = $this->project($this->image(), false);
        Sanctum::actingAs(User::factory()->create(), ['update']);

        $this->patchJson('/api/projects/'.$project->id, ['split_image' => 'yes'])->assertStatus(422);
        $this->patchJson('/api/projects/'.$project->id, ['split_image' => true])->assertOk();

        $this->assertTrue($project->fresh()->split_image);
        Queue::assertPushed(SplitImage::class);
    }

    public function test_the_images_page_says_how_the_copy_is_and_offers_remove_and_retry()
    {
        $image = $this->image();
        Livewire::test(Images::class)->assertDontSee('Pieces:');

        $this->project($image);
        $image->split_error = 'not enough free space: need about 2.1 GB, 1.0 GB free';
        $image->save();
        Livewire::test(Images::class)->assertSee('Pieces: failed: not enough free space: need about 2.1 GB, 1.0 GB free')
            ->assertSeeHtml('wire:click="retrySplit('.$image->id.')"');

        Queue::fake();
        Livewire::test(Images::class)->call('retrySplit', $image->id)
            ->assertSee('Pieces: preparing…')->assertSeeHtml('wire:poll.5s');
        Queue::assertPushed(SplitImage::class);

        $this->copy($image->fresh());
        Livewire::test(Images::class)->assertSee('Pieces: ready, 0.0 GB')->assertDontSeeHtml('removeSplit(');

        Project::where('image_id', $image->id)->update(['split_image' => false]);
        Livewire::test(Images::class)->assertSeeHtml('wire:click="removeSplit('.$image->id.')"')
            ->call('removeSplit', $image->id)->assertSee('Pieces removed.');
        $this->assertFalse($image->fresh()->splitReady());
    }

    public function test_a_copy_a_project_needs_is_not_removed()
    {
        $image = $this->image();
        $this->copy($image);
        $this->project($image);

        Livewire::test(Images::class)->call('removeSplit', $image->id);

        $this->assertTrue($image->fresh()->splitReady());
    }
}
