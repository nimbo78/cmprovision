<?php

namespace Tests\Feature;

use App\Http\Livewire\Projects;
use App\Jobs\ComputeSHA256;
use App\Models\Image;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** An image added from the internet is not ready while it is fetched, repacked or checked: no project takes it and no module writes it */
class ImageReadyTest extends TestCase
{
    use RefreshDatabase;

    const START = '/scriptexecute?serial=10000000e611110c&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3';

    protected function image(array $attributes = []): Image
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'ready-'.uniqid().'.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_sha256 = str_repeat('b', 64);
        $image->uncompressed_size = 6000000000;
        foreach ($attributes as $key => $value) {
            $image->$key = $value;
        }
        $image->save();

        return $image;
    }

    protected function project(Image $image, array $attributes = []): Project
    {
        $project = Project::create(array_merge(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false], $attributes));
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);

        return $project;
    }

    public function test_an_uploaded_image_is_ready_and_a_fetched_one_only_when_nothing_is_left_to_do()
    {
        $this->assertTrue($this->image()->isReady());
        foreach (['queued', 'downloading', 'converting', 'checking', 'failed'] as $state) {
            $this->assertFalse($this->image(['download_state' => $state])->isReady(), $state);
        }
        $this->assertFalse($this->image(['download_error' => 'The checksums disagree'])->isReady());
        $this->assertTrue($this->image(['source_type' => 'github', 'download_state' => null])->isReady());
    }

    public function test_the_page_says_what_an_image_is_waiting_for()
    {
        $this->assertSame('', $this->image()->downloadLabel());
        $this->assertSame('Waiting', $this->image(['download_state' => 'queued'])->downloadLabel());
        $this->assertSame('Downloading 34 % (544 MB of 1.6 GB)', $this->image(['download_state' => 'downloading', 'download_bytes' => 544000000, 'download_total' => 1600000000])->downloadLabel());
        $this->assertSame('Downloading', $this->image(['download_state' => 'downloading'])->downloadLabel());
        $this->assertSame('Repacking zip to gz 52 %', $this->image(['download_state' => 'converting', 'download_bytes' => 52, 'download_total' => 100])->downloadLabel());
        $this->assertSame('Checking', $this->image(['download_state' => 'checking'])->downloadLabel());
        $this->assertSame('Failed: no route to host', $this->image(['download_state' => 'failed', 'download_error' => 'no route to host'])->downloadLabel());
    }

    public function test_the_project_form_offers_only_ready_images_and_defaults_to_a_ready_one()
    {
        $ready = $this->image(['filename' => 'ready.img.gz']);
        $fetching = $this->image(['filename' => 'fetching.img.gz', 'download_state' => 'downloading']);

        Livewire::test(Projects::class)
            ->call('create')
            ->assertSet('image_id', $ready->id)
            ->assertSee('ready.img.gz')
            ->assertDontSee('fetching.img.gz');
        $this->assertGreaterThan($ready->id, $fetching->id, 'the fetched image is the newest: Image::max(id) would have picked it');
    }

    public function test_a_project_keeps_showing_its_own_image_even_if_that_one_is_not_ready()
    {
        $image = $this->image(['filename' => 'mine.img.gz', 'download_state' => 'failed', 'download_error' => 'x']);
        $project = $this->project($image);

        Livewire::test(Projects::class)->call('edit', $project->id)->assertSee('mine.img.gz');
    }

    public function test_a_not_ready_image_cannot_be_saved_into_a_project_and_nothing_is_queued_for_it()
    {
        Queue::fake();
        $fetching = $this->image(['download_state' => 'checking', 'uncompressed_sha256' => null]);

        Livewire::test(Projects::class)
            ->call('create')
            ->set('name', 'x')
            ->set('image_id', $fetching->id)
            ->set('verify', true)
            ->call('store')
            ->assertHasErrors(['image_id']);

        Queue::assertNothingPushed();
        $this->assertSame(0, Project::count());
    }

    public function test_a_module_start_with_a_not_ready_image_is_refused_with_the_reason()
    {
        foreach ([['queued', 'is still waiting to be downloaded'], ['downloading', 'is still being downloaded'], ['converting', 'is still being repacked'],
                  ['checking', 'is still being checked'], ['failed', 'failed to download']] as $n => [$state, $text]) {
            $this->project($this->image(['download_state' => $state, 'filename' => $state.'.img.gz']), ['name' => 'p'.$n]);
            $serial = '10000000e61111'.sprintf('%02x', $n);

            $this->get('/scriptexecute?serial='.$serial.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d'.$n)
                ->assertOk()->assertSee($text, false)->assertSee($state.'.img.gz', false);
        }
    }

    public function test_a_ready_image_is_still_written()
    {
        $this->project($this->image());

        $this->get(self::START)->assertOk()->assertDontSee('is still being', false)->assertDontSee('failed to download', false);
    }

    public function test_the_api_refuses_a_not_ready_image_for_a_project_and_never_shows_the_source_address()
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $ready = $this->image();
        $fetching = $this->image(['download_state' => 'downloading', 'download_url' => 'https://example.org/a.img.gz?token=secret']);
        $project = $this->project($ready);

        $this->patchJson('/api/projects/'.$project->id, ['image_id' => $fetching->id])->assertStatus(422);
        $this->patchJson('/api/projects/'.$project->id, ['image_id' => $ready->id])->assertOk();

        $listing = $this->getJson('/api/images')->assertOk()->getContent();
        $this->assertStringNotContainsString('secret', $listing);
        $this->assertStringNotContainsString('download_url', $listing);
        $this->assertStringNotContainsString('secret', $this->getJson('/api/images/'.$fetching->id)->assertOk()->getContent());
    }

    public function test_the_source_address_is_stored_encrypted()
    {
        $image = $this->image(['source_type' => 'url', 'download_url' => 'https://example.org/a.img.gz?dl=1&token=secret']);

        $raw = (string) DB::table('images')->where('id', $image->id)->value('download_url');
        $this->assertStringNotContainsString('secret', $raw);
        $this->assertStringNotContainsString('example.org', $raw);
        $this->assertSame('https://example.org/a.img.gz?dl=1&token=secret', Image::find($image->id)->download_url);
    }
    public function test_deleting_an_image_over_the_api_cancels_its_fetch_and_removes_what_it_left()
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $image = $this->image(['download_state' => 'downloading', 'source_type' => 'url', 'download_url' => 'https://example.org/a.img.gz']);
        file_put_contents($image->imagepath().'.part', 'x');

        $this->deleteJson('/api/images/'.$image->id)->assertOk();

        $this->assertNull(Image::find($image->id));
        $this->assertFileDoesNotExist($image->imagepath().'.part');
    }
}
