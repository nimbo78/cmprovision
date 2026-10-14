<?php

namespace App\Services\Switch;

use App\Services\Snmp\SnmpError;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The switch snapshot shared by every open Ports page: taken at most once per FRESH_SECONDS (twice
 * the poll's duration for a slow switch), one poll at a time; when the switch stops answering, the
 * last good snapshot stays together with the error and the time it began.
 */
class SnapshotCache
{
    const FRESH_SECONDS = 5;
    const SLOW_POLL = 2.5;
    const LOCK_WAIT = 1;
    const LOCK_SECONDS = 60;
    const KEEP_SECONDS = 86400;
    /* A switch whose last poll failed is asked again after this long: every page poll and module start would wait for its timeout */
    const ERROR_SECONDS = 30;

    protected $id, $take;

    /** @param callable $take takes a new snapshot (SwitchSnapshot::take()), throws SnmpError */
    public function __construct(string $id, callable $take)
    {
        $this->id = $id;
        $this->take = $take;
    }

    /** ['snapshot' => the last good one or null, 'error' => null or ['message', 'since'], 'checked_at' => unix time or null].
        $notOlderThan: a snapshot taken before this unix time is taken again (unless the switch is in its pause after an
        error); $wait: how long to wait for another request's poll before taking the stored state */
    public function current(?int $notOlderThan = null, float $wait = self::LOCK_WAIT): array
    {
        $state = $this->stored();
        if ($this->fresh($state, $notOlderThan))
            return $state;

        $lock = Cache::lock('switch-snapshot-lock:'.$this->id, self::LOCK_SECONDS);
        try
        {
            $lock->block($wait);
        }
        catch (LockTimeoutException $e)
        {
            return $state;   // another page is asking the switch right now
        }
        try
        {
            $state = $this->stored();
            return $this->fresh($state, $notOlderThan) ? $state : $this->poll($state);
        }
        finally
        {
            $lock->release();
        }
    }

    protected function poll(array $state): array
    {
        $started = Carbon::now();
        $now = $started->getTimestamp();
        try
        {
            $snapshot = ($this->take)();
            if ($state['error'])
                Log::info('Switch '.$this->id.' answers again');
            $state = ['snapshot' => $snapshot, 'error' => null, 'checked_at' => $now];
        }
        catch (\Throwable $e)
        {
            // mostly SnmpError; php-snmp also warns outside it, e.g. about a host name it cannot resolve
            if (!$state['error'])
                Log::warning('Switch '.$this->id.' does not answer: '.$e->getMessage(), $e instanceof SnmpError ? [] : ['exception' => $e]);
            $state['error'] = ['message' => $e->getMessage(), 'since' => $state['error']['since'] ?? $now];
            $state['checked_at'] = $now;
        }
        // a poll that fails slowly holds the switch back as long as a slow good one
        $state['took'] = round((Carbon::now()->getPreciseTimestamp(3) - $started->getPreciseTimestamp(3)) / 1000, 3);
        Cache::put('switch-snapshot:'.$this->id, $state, self::KEEP_SECONDS);
        return $state;
    }

    protected function stored(): array
    {
        $state = Cache::get('switch-snapshot:'.$this->id);
        return is_array($state) ? $state : ['snapshot' => null, 'error' => null, 'checked_at' => null];
    }

    /* Checked less than FRESH_SECONDS ago, or twice the poll's duration (the good one's or the last attempt's) for a slow
       switch, and not before $notOlderThan; a failed poll holds ERROR_SECONDS at least */
    protected function fresh(array $state, ?int $notOlderThan = null): bool
    {
        if (!$state['checked_at'])
            return false;
        $age = Carbon::now()->getTimestamp() - $state['checked_at'];
        $duration = max((float) ($state['snapshot']['duration'] ?? 0), (float) ($state['took'] ?? 0));
        $life = $duration > self::SLOW_POLL ? 2 * $duration : self::FRESH_SECONDS;
        if ($state['error'])
            return $age < max(self::ERROR_SECONDS, $life);
        if ($notOlderThan !== null && $state['checked_at'] < $notOlderThan)
            return false;
        return $age < $life;
    }
}
