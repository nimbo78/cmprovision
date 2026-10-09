<?php

namespace Tests\Feature;

use App\Http\Livewire\Images;
use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** An image whose file disappeared from disk must not take the whole Images page down. */
class ImagesPageRobustnessTest extends TestCase
{
    use RefreshDatabase;

    protected function imageWithoutFile()
    {
        $image = new Image;
        $image->filename = 'vanished.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'vanished-'.uniqid().'.gz';
        $image->sha256 = str_repeat('c', 64);
        $image->save();
        return $image;
    }

    public function test_images_page_renders_when_an_image_file_is_missing()
    {
        $this->imageWithoutFile();

        Livewire::test(Images::class)
            ->assertSee('vanished.img.gz')
            ->assertSee('file missing');
    }

    public function test_an_image_whose_file_is_missing_can_still_be_deleted()
    {
        $image = $this->imageWithoutFile();

        Livewire::test(Images::class)->call('delete', $image->id);

        $this->assertSame(0, Image::count());
    }
}
