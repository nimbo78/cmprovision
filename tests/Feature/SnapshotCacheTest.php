<?php

namespace Tests\Feature;

use App\Services\Snmp\SnmpError;
use App\Services\Switch\SnapshotCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Every open Ports page shares one switch snapshot: asked once per freshness window, one poll at a
 * time, and a switch that stops answering leaves its last snapshot with the error.
 */
class SnapshotCacheTest extends TestCase
{
    protected $polls = 0, $duration = 0.2, $silent = false;

    protected function cache()
    {
        return new SnapshotCache('192.0.2.10', function () {
            $this->polls++;
            if ($this->silent)
                throw new SnmpError('No response from 192.0.2.10', true);
            return ['ports' => [], 'duration' => $this->duration, 'taken_at' => Carbon::now()->getTimestamp()];
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_second_look_within_five_seconds_does_not_poll()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->cache()->current();
        Carbon::setTestNow('2026-10-12 10:00:04');
        $state = $this->cache()->current();

        $this->assertSame(1, $this->polls);
        $this->assertNotNull($state['snapshot']);
        $this->assertNull($state['error']);
    }

    public function test_after_five_seconds_it_polls_again()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->cache()->current();
        Carbon::setTestNow('2026-10-12 10:00:05');
        $this->cache()->current();

        $this->assertSame(2, $this->polls);
    }

    public function test_a_slow_switch_is_asked_twice_its_poll_time_apart()
    {
        $this->duration = 4.0;
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->cache()->current();
        Carbon::setTestNow('2026-10-12 10:00:07');
        $this->cache()->current();
        $this->assertSame(1, $this->polls, 'fresh for 8 s');
        Carbon::setTestNow('2026-10-12 10:00:08');
        $this->cache()->current();
        $this->assertSame(2, $this->polls);
    }

    public function test_a_switch_that_stops_answering_keeps_its_last_snapshot_with_the_error()
    {
        Log::spy();
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->cache()->current();
        $this->silent = true;
        Carbon::setTestNow('2026-10-12 10:00:05');
        $state = $this->cache()->current();
        $this->assertNotNull($state['snapshot']);
        $this->assertSame(['message' => 'No response from 192.0.2.10', 'since' => Carbon::parse('2026-10-12 10:00:05')->getTimestamp()], $state['error']);

        Carbon::setTestNow('2026-10-12 10:00:07');
        $this->cache()->current();
        $this->assertSame(2, $this->polls, 'a silent switch is not asked more often either');

        Carbon::setTestNow('2026-10-12 10:00:10');
        $state = $this->cache()->current();
        $this->assertSame(Carbon::parse('2026-10-12 10:00:05')->getTimestamp(), $state['error']['since'], 'since the first failure');

        $this->silent = false;
        Carbon::setTestNow('2026-10-12 10:00:15');
        $this->assertNull($this->cache()->current()['error']);

        Log::shouldHaveReceived('warning')->once();
        Log::shouldHaveReceived('info')->once();
    }

    public function test_no_snapshot_yet_and_a_silent_switch_gives_the_error_only()
    {
        $this->silent = true;
        $state = $this->cache()->current();
        $this->assertNull($state['snapshot']);
        $this->assertSame('No response from 192.0.2.10', $state['error']['message']);
    }

    public function test_a_page_waiting_for_another_pages_poll_shows_what_there_is()
    {
        // real time here: a lock waits by the clock
        $lock = Cache::lock('switch-snapshot-lock:192.0.2.10', 60);
        $this->assertTrue($lock->get());
        $state = $this->cache()->current();
        $lock->release();

        $this->assertSame(0, $this->polls);
        $this->assertNull($state['snapshot']);
    }
}
