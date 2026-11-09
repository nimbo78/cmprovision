<?php

namespace Tests\Feature;

use App\Services\StationUpdateQueueGate;
use App\Services\StationUpdatesControl;
use Tests\TestCase;

class StationUpdateQueueGateTest extends TestCase
{
    public function test_reservation_holds_admission_until_processing_event_then_releases_it(): void
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
}
