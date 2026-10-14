<?php

namespace Tests\Feature;

use App\Http\Livewire\Settings;
use App\Jobs\ComputeSHA256;
use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** An image's checksum takes minutes: it runs on a queue of its own, so switch ports and notifications never wait */
class ImageQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_checksums_go_to_the_images_queue()
    {
        Queue::fake();
        $image = new Image;
        $image->filename = 'os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->save();

        ComputeSHA256::dispatch($image);

        Queue::assertPushedOn('images', ComputeSHA256::class);
    }

    public function test_service_status_lists_both_queue_workers()
    {
        Livewire::test(Settings::class)
            ->assertSee('queue worker (switch ports, notifications)')
            ->assertSee('queue worker (image checksums)')
            ->assertSeeHtml('wire:click="viewLogQueue()"')
            ->assertSeeHtml('wire:click="viewLogImagesQueue()"');
    }
}
