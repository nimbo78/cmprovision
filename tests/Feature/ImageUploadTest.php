<?php

namespace Tests\Feature;

use App\Http\Livewire\Images;
use App\Jobs\ComputeSHA256;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class ImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (Image::all() as $image) {
            @unlink($image->imagepath());
        }
        parent::tearDown();
    }

    /** The browser-side uploader talks JSON to the same endpoint the plain form posts to. */
    public function test_xhr_upload_gets_the_new_image_as_json()
    {
        Bus::fake();
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/addImage', [
            'image' => UploadedFile::fake()->createWithContent('raspios.img.gz', 'not really gzip'),
        ]);

        $response->assertStatus(201)->assertJsonPath('filename', 'raspios.img.gz');
        $image = Image::first();
        $this->assertFileExists($image->imagepath());
        Bus::assertDispatched(ComputeSHA256::class);
    }

    public function test_xhr_upload_of_an_unsupported_file_gets_a_validation_error_as_json()
    {
        Bus::fake();
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/addImage', [
            'image' => UploadedFile::fake()->createWithContent('raspios.img', 'raw image'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['image']);
        $this->assertSame(0, Image::count());
    }

    public function test_images_page_refreshes_itself_while_a_hash_is_still_being_computed()
    {
        $pending = new Image;
        $pending->filename = 'a.img.gz'; $pending->filename_extension = 'gz';
        $pending->filename_on_server = 'pending.gz'; $pending->sha256 = '';
        $pending->save();
        File::put($pending->imagepath(), 'x');

        Livewire::test(Images::class)->assertSeeHtml('wire:poll');

        $pending->sha256 = str_repeat('a', 64);
        $pending->uncompressed_sha256 = str_repeat('b', 64);
        $pending->save();

        Livewire::test(Images::class)->assertDontSeeHtml('wire:poll');
    }

    public function test_images_page_does_not_poll_while_the_upload_dialog_is_open()
    {
        $pending = new Image;
        $pending->filename = 'a.img.gz'; $pending->filename_extension = 'gz';
        $pending->filename_on_server = 'pending2.gz'; $pending->sha256 = '';
        $pending->save();
        File::put($pending->imagepath(), 'x');

        Livewire::test(Images::class)->call('create')->assertDontSeeHtml('wire:poll');
    }
}
