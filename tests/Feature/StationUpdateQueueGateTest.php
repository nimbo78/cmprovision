<?php

namespace Tests\Feature;

use App\Services\StationUpdateQueueGate;
use App\Services\StationUpdatesControl;
use Tests\TestCase;

class StationUpdateQueueGateTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    public function test_reservation_holds_admission_until_its_guard_is_released(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'queue-update-lock-');
        $this->beforeApplicationDestroyed(fn () => unlink($path));
        $control = new class extends StationUpdatesControl {
            public bool $paused = false;
            public function available(): bool { return true; }
            public function pending(): bool { return $this->paused; }
        };
        $gate = new StationUpdateQueueGate($control, $path, false);
        $other = fopen($path, 'r');
        $this->assertTrue($gate->reserve());
        $this->assertFalse(flock($other, LOCK_EX | LOCK_NB));
        $gate->release();
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB));
        flock($other, LOCK_UN);
        $control->paused = true;
        $this->assertFalse($gate->reserve());
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB));
        fclose($other);
    }

    public function test_empty_and_reserved_database_pops_release_admission_before_sleep_or_processing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'queue-pop-lock-');
        $this->beforeApplicationDestroyed(fn () => unlink($path));
        $control = new class extends StationUpdatesControl {
            public function available(): bool { return true; }
            public function pending(): bool { return false; }
        };
        $gate = new StationUpdateQueueGate($control, $path, false);
        $this->app->instance(StationUpdatesControl::class, $control);
        $this->app->instance(StationUpdateQueueGate::class, $gate);
        $queue = \Illuminate\Support\Facades\Queue::connection('database');
        $this->assertInstanceOf(\App\Services\StationUpdateDatabaseQueue::class, $queue);
        $other = fopen($path, 'r');
        $this->assertNull($queue->pop('images'));
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB));
        flock($other, LOCK_UN);
        $queue->pushRaw(json_encode(['uuid' => 'test', 'displayName' => 'Fixture', 'job' => 'unused', 'data' => []]), 'images');
        $job = $queue->pop('images');
        $this->assertNotNull($job);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('jobs')->whereNotNull('reserved_at')->count());
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB));
        $job->delete();
        fclose($other);
    }
}
