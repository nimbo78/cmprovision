<?php

namespace Tests\Feature;

use App\Http\Livewire\Images;
use App\Models\Cm;
use App\Models\Image;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImageRemovalTest extends TestCase
{
    use RefreshDatabase;
    private array $files = [];
    protected function tearDown(): void
    {
        foreach ($this->files as $file) @unlink($file);
        parent::tearDown();
    }
    private function image(): Image
    {
        $image = new Image;
        $image->filename = 'protected.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'removal-'.bin2hex(random_bytes(8)).'.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->split_filename = 'removal-pieces-'.bin2hex(random_bytes(8)).'.gz';
        $image->save();
        foreach ([$image->imagepath(), $image->splitPath()] as $file) {
            file_put_contents($file, 'fixture');
            $this->files[] = $file;
        }
        return $image;
    }
    private function project(Image $image): Project
    {
        return Project::create(['name'=>'p'.$image->id, 'device'=>'cm4', 'storage'=>'/dev/mmcblk0', 'image_id'=>$image->id, 'label_moment'=>'never']);
    }
    public function test_manual_delete_protects_an_image_used_by_any_project(): void
    {
        $image = $this->image();
        $project = $this->project($image);
        Livewire::test(Images::class)->call('delete', $image->id)->assertSee('used by a project');
        $this->assertFileExists($image->imagepath());
        $this->assertSame($image->id, $project->fresh()->image_id);
    }
    public function test_manual_delete_and_piece_removal_protect_current_runs_even_without_a_project(): void
    {
        $image = $this->image();
        Cm::create(['serial'=>'1000000000000a01', 'mac'=>'e4:5f:01:00:0a:01', 'phase'=>'write', 'image_filename'=>$image->filename]);
        Livewire::test(Images::class)->call('delete', $image->id)->assertSee('current module run');
        Livewire::test(Images::class)->call('removeSplit', $image->id)->assertSee('current module run');
        $this->assertFileExists($image->imagepath());
        $this->assertFileExists($image->splitPath());
    }
    public function test_a_split_job_remains_protected_after_its_project_was_removed(): void
    {
        $image = $this->image();
        $image->split_queued_at = now(); $image->save();
        Livewire::test(Images::class)->call('delete', $image->id)->assertSee('pieces are being prepared');
        Livewire::test(Images::class)->call('removeSplit', $image->id)->assertSee('pieces are being prepared');
        $this->assertFileExists($image->imagepath());
    }
    public function test_unreferenced_images_can_be_deleted_and_repeat_is_harmless(): void
    {
        $image = $this->image();
        Livewire::test(Images::class)->call('delete', $image->id)->call('delete', $image->id);
        $this->assertDatabaseMissing('images', ['id'=>$image->id]);
        $this->assertFileDoesNotExist($image->imagepath());
    }
}
