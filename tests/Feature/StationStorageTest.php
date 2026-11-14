<?php
namespace Tests\Feature;

use App\Http\Livewire\StationStorage;
use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StationStorageTest extends TestCase
{
    use RefreshDatabase;
    private array $files = [];
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->withSession(['auth.password_confirmed_at'=>time()]);
    }
    protected function tearDown(): void
    {
        foreach ($this->files as $file) @unlink($file);
        parent::tearDown();
    }
    private function image(string $name): Image
    {
        $image = new Image;
        $image->filename=$name; $image->filename_extension='gz';
        $image->filename_on_server='storage-'.bin2hex(random_bytes(8)).'.gz';
        $image->sha256=str_repeat('a',64); $image->save();
        file_put_contents($image->imagepath(),'fixture'); $this->files[]=$image->imagepath();
        return $image;
    }
    public function test_preview_is_read_only_and_confirm_does_not_include_newly_unused_images(): void
    {
        $old=$this->image('old.img.gz');
        $page=Livewire::test(StationStorage::class)->call('previewCleanup')->assertSee('old.img.gz');
        $new=$this->image('new.img.gz');
        $this->assertFileExists($old->imagepath());
        $page->call('clean');
        $this->assertDatabaseMissing('images',['id'=>$old->id]);
        $this->assertFileExists($new->imagepath());
    }
    public function test_image_claimed_after_preview_is_skipped(): void
    {
        $image=$this->image('claimed.img.gz');
        $page=Livewire::test(StationStorage::class)->call('previewCleanup');
        Project::create(['name'=>'new project','device'=>'cm4','storage'=>'/dev/mmcblk0','image_id'=>$image->id,'label_moment'=>'never']);
        $page->call('clean')->assertSee('protected');
        $this->assertFileExists($image->imagepath());
    }
    public function test_cleanup_requires_password_confirmation_and_a_server_preview(): void
    {
        $image=$this->image('protected.img.gz');
        Livewire::test(StationStorage::class)->call('clean')->assertForbidden();
        $this->withSession(['auth.password_confirmed_at'=>1]);
        Livewire::test(StationStorage::class)->call('previewCleanup')->call('clean')->assertForbidden();
        $this->assertFileExists($image->imagepath());
    }

    public function test_measurements_load_only_on_request_and_unknown_is_not_zero(): void
    {
        $control = new class extends \App\Services\StationStorage {
            public int $calls = 0;
            public function status(bool $refresh = false): array {
                $this->calls++;
                return ['schema'=>1, 'measured_at'=>time(), 'filesystem'=>['free'=>2000000000,'total'=>32000000000,'used'=>30000000000],
                    'categories'=>array_fill_keys(array_keys(self::CATEGORIES), ['bytes'=>null,'state'=>'unknown']),
                    'snapshot'=>['required'=>5000000000,'free'=>2000000000,'sufficient'=>false]];
            }
        };
        $this->app->instance(\App\Services\StationStorage::class, $control);
        $page = Livewire::test(StationStorage::class);
        $this->assertSame(0, $control->calls);
        $page->call('refreshStatus')->assertSee('Unknown')->assertSee('5.0 GB required');
        $this->assertSame(1, $control->calls);
    }
}
