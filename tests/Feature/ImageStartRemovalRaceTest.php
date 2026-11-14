<?php
namespace Tests\Feature;

use App\Models\Image;
use App\Models\Project;
use App\Models\Setting;
use App\Services\NetworkChangeGate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** A second real SQLite connection writes at the barrier after image selection, before the run is saved. */
class ImageStartRemovalRaceTest extends TestCase
{
    public function test_image_selection_and_run_claim_share_a_database_write_transaction(): void
    {
        $directory=sys_get_temp_dir().'/cmprovision-start-race-'.bin2hex(random_bytes(6)); mkdir($directory,0700);
        $database=$directory.'/db.sqlite'; touch($database);
        config(['database.connections.sqlite.database'=>$database]); DB::purge('sqlite');
        $this->app->instance(NetworkChangeGate::class,new NetworkChangeGate(false));
        $file=null;
        try {
            Artisan::call('migrate:fresh',['--force'=>true]);
            $image=new Image; $image->filename='race.img.gz'; $image->filename_extension='gz';
            $image->filename_on_server='race-'.bin2hex(random_bytes(8)).'.gz'; $image->sha256=str_repeat('a',64); $image->save();
            file_put_contents($file=$image->imagepath(),'fixture');
            $project=Project::create(['name'=>'race','device'=>'cm4','storage'=>'/dev/mmcblk0','image_id'=>$image->id,'label_moment'=>'never','verify'=>false]);
            Setting::updateOrCreate(['key'=>'active_project'],['value'=>$project->id]);
            $fired=$blocked=false;
            DB::listen(function ($query) use (&$fired,&$blocked,$database,$project) {
                if ($fired || !str_starts_with($query->sql,'select * from "images"')) return;
                $fired=true;
                $other=new \PDO('sqlite:'.$database); $other->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
                $other->exec('PRAGMA busy_timeout=1');
                try { $other->exec('UPDATE projects SET image_id=NULL WHERE id='.(int)$project->id); }
                catch (\PDOException $error) { $blocked=str_contains($error->getMessage(),'locked'); }
            });
            $this->get('/scriptexecute?serial=1000000000000a11&model=CM4&mac=e4:5f:01:00:0a:11&storagesize=61071360')->assertOk();
            $this->assertTrue($fired,'The competing connection must run after image selection.');
            $this->assertTrue($blocked,'A competing project/image change must wait until the run records its image.');
            $this->assertSame($image->id,$project->fresh()->image_id);
        } finally {
            DB::disconnect('sqlite');
            if ($file) @unlink($file);
            File::deleteDirectory($directory);
        }
    }
}
