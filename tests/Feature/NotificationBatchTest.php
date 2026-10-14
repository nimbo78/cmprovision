<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** 40-48 modules start at once: one of them opens the batch, the others join it */
class NotificationBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function project(): Project
    {
        $image = new Image;
        $image->filename = 'os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->save();
        return Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
                                'label_moment' => 'never', 'verify' => false]);
    }

    public function test_a_start_waits_while_another_chooses_and_joins_its_batch()
    {
        $project = $this->project();
        $other = Cache::lock('notification-batch', 10);
        $other->get();                                           // another start is choosing the batch
        $made = null;
        Sleep::fake();
        Sleep::whenFakingSleep(function () use ($other, $project, &$made) {
            if ($made)
                return;
            $made = NotificationBatch::create(['project_id' => $project->id, 'started_at' => now(), 'last_event_at' => now()]);
            $other->release();                                   // ...and is done
        });

        $batch = NotificationBatch::current($project);

        $this->assertNotNull($made, 'it waited for the other start');
        $this->assertSame($made->id, $batch->id);
        $this->assertSame(1, NotificationBatch::count());
    }

    public function test_starts_one_after_another_share_the_batch()
    {
        $project = $this->project();
        $this->assertSame(NotificationBatch::current($project)->id, NotificationBatch::current($project)->id);
        $this->assertSame(1, NotificationBatch::count());
    }
}
