<?php

namespace Tests\Feature;

use App\Services\Snmp\SnmpError;
use App\Services\Switch\SnapshotCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Every open Ports page shares one switch snapshot: asked once per freshness window, one poll at a
 * time, and a switch that stops answering leaves its last snapshot with the error.
 */
class SnapshotCacheTest extends TestCase
{
    protected $polls = 0, $duration = 0.2, $silent = false, $slow = 0;

    protected function cache()
    {
        return new SnapshotCache('192.0.2.10', function () {
            $this->polls++;
            if ($this->slow)
                Carbon::setTestNow(Carbon::now()->addSeconds($this->slow));
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
        Carbon::setTestNow('2026-10-12 10:00:34');
        $this->assertNotNull($this->cache()->current()['error'], 'asked again only 30 s after the failure');
        Carbon::setTestNow('2026-10-12 10:00:35');
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

    public function test_a_poll_that_fails_slowly_holds_twice_its_time_when_that_is_longer()
    {
        $this->silent = true;
        $this->slow = 20;
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->cache()->current();
        $this->slow = 0;
        Carbon::setTestNow('2026-10-12 10:00:39');
        $this->cache()->current();
        $this->assertSame(1, $this->polls, 'a failed poll that took 20 s holds for 40 s');
        Carbon::setTestNow('2026-10-12 10:00:40');
        $this->cache()->current();
        $this->assertSame(2, $this->polls);
    }

    public function test_a_state_given_without_waiting_for_another_poll_says_so()
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->assertArrayNotHasKey('busy', $this->cache()->current());
        Carbon::setTestNow('2026-10-14 10:00:10');
        Sleep::fake(syncWithCarbon: true);                               // the wait for the lock passes in test time
        Cache::lock('switch-snapshot-lock:192.0.2.10', 60)->get();      // another request is asking the switch
        $state = $this->cache()->current();
        $this->assertTrue($state['busy']);
        $this->assertSame(1, $this->polls);
    }

    public function test_a_failed_poll_is_not_repeated_for_thirty_seconds()
    {
        $this->silent = true;
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->cache()->current();
        Carbon::setTestNow('2026-10-14 10:00:20');
        $state = $this->cache()->current();
        $this->assertSame(1, $this->polls, 'a silent switch is not asked again on every page poll');
        $this->assertSame('No response from 192.0.2.10', $state['error']['message']);
        Carbon::setTestNow('2026-10-14 10:00:30');
        $this->cache()->current();
        $this->assertSame(2, $this->polls);
    }

    public function test_a_snapshot_older_than_asked_for_is_taken_again_unless_the_switch_is_silent()
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->cache()->current();
        Carbon::setTestNow('2026-10-14 10:00:02');
        $this->cache()->current();
        $this->assertSame(1, $this->polls, 'fresh for five seconds');
        $this->cache()->current(Carbon::now()->getTimestamp() - 1);
        $this->assertSame(2, $this->polls, 'a module that came after the snapshot needs a newer one');

        $this->silent = true;
        Carbon::setTestNow('2026-10-14 10:00:10');
        $this->cache()->current();
        $this->assertSame(3, $this->polls);
        Carbon::setTestNow('2026-10-14 10:00:12');
        $this->cache()->current(Carbon::now()->getTimestamp());
        $this->assertSame(3, $this->polls, 'a silent switch keeps its pause even for a module');
    }

    public function test_any_other_failure_of_the_snmp_extension_is_a_switch_error_too()
    {
        Log::spy();
        $state = (new SnapshotCache('switch.invalid', function () {
            // php-snmp warns about a host it cannot resolve; Laravel makes the warning an ErrorException
            throw new \ErrorException('SNMP::__construct(): Unknown host switch.invalid');
        }))->current();

        $this->assertNull($state['snapshot']);
        $this->assertSame('SNMP::__construct(): Unknown host switch.invalid', $state['error']['message']);
        Log::shouldHaveReceived('warning')->once();
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
