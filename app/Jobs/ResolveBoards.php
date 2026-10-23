<?php

namespace App\Jobs;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Services\Switch\PortLookup;
use App\Services\Switch\Stand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;

/* The boards of the modules whose start waits for one (cms.board_pending_since): one poll of the stand for all of
   them, the 2.4 rules for each MAC (PortLookup::resolve). A module not on the map yet is looked up again
   RETRY_SECONDS later; after GIVE_UP_SECONDS it gets no board and the warning. Unique until a worker takes it: a
   start during a run queues the next run, and one run at a time waits in the queue. Runs may overlap (both workers
   take boards): settle() records each module once, and whoever records it queues the preinstall label */
class ResolveBoards implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable;

    const RETRY_SECONDS = 3;
    const GIVE_UP_SECONDS = 60;
    const STALE_MINUTES = 10;

    public $uniqueFor = 120;
    public $timeout = 80;   // under the database queue's retry_after (90 s): never run twice at once

    public function __construct()
    {
        $this->onQueue('boards');
    }

    public function handle(): void
    {
        $this->dropStale();
        $pending = Cm::whereNotNull('board_pending_since')->orderBy('board_pending_since')->get();
        if ($pending->isEmpty())
            return;
        // a snapshot taken after the latest start; a later run asks for a newer one than the run before it saw
        $notOlderThan = max($pending->max('board_pending_since')->getTimestamp(), now()->getTimestamp() - 1);
        $trouble = null;
        try
        {
            $stand = Stand::current($notOlderThan, PortLookup::WAIT);
        }
        catch (\Throwable $e)
        {
            $stand = null;
            $trouble = 'Switch port unknown: '.$e->getMessage();
        }

        $waiting = false;
        foreach ($pending as $cm)
        {
            // one module the database will not take must not leave the rest of the wave without boards
            try
            {
                $found = $stand ? PortLookup::resolve($stand, $cm->mac)
                                : ['switch' => null, 'port' => null, 'board' => null, 'warning' => $trouble];
                $late = now()->getTimestamp() - $cm->board_pending_since->getTimestamp() >= self::GIVE_UP_SECONDS;
                // no switch left since the start: nothing to wait for
                if ($found['board'] === null && ($stand || $trouble) && !$late)
                {
                    $waiting = true;
                    continue;
                }
                self::settle($cm, $found);
            }
            catch (\Throwable $e)
            {
                report($e);
                $waiting = true;
            }
        }
        // the sync queue of the tests runs a dispatch at once: no next run there
        if ($waiting && !$this->job instanceof SyncJob)
            self::dispatch()->delay(now()->addSeconds(self::RETRY_SECONDS));
    }

    /* The run failed or ran out of time: nothing else would look the waiting modules up before the next start */
    public function failed(\Throwable $e): void
    {
        if (Cm::whereNotNull('board_pending_since')->exists())
            self::dispatch()->delay(now()->addSeconds(self::RETRY_SECONDS));
    }

    /** Record what the lookup found for this start of the module; false when the module started again meanwhile or
        another run recorded it first. A module refused at its start (failed by now) gets no label, as before 2.5 */
    public static function settle(Cm $cm, array $found): bool
    {
        $since = $cm->board_pending_since;
        $recorded = Cm::whereKey($cm->id)->where('board_pending_since', $since)->update([
            'provisioning_board' => $found['board'],
            'switch_id' => $found['switch']->id ?? null,
            'switch_name' => $found['switch'] ? $found['switch']->hostname() : null,
            'switch_port' => $found['port'],
            'board_pending_since' => null,
        ]);
        if (!$recorded)
            return false;
        // the lines this start wrote before its board was known
        if ($found['board'] !== null)
            $cm->logLines()->whereNull('board')->where('created_at', '>=', $since)->update(['board' => $found['board']]);
        $cm->refresh();
        if ($found['warning'])
            Cmlog::create(['cm' => $cm->mac, 'board' => $cm->provisioning_board, 'loglevel' => 'warning', 'msg' => $found['warning']]);
        // a job of its own: a slow or silent printer never holds up the boards of the wave
        if ($cm->phase !== 'failed' && $cm->project && $cm->project->label_moment === 'preinstall' && $cm->project->label)
            PrintLabel::dispatch($cm->id);
        return true;
    }

    /* A start whose lookup never ran (the workers were down, or the package went back and forth): too late for its
       board and its label now, the module may be long gone */
    protected function dropStale(): void
    {
        $stale = Cm::whereNotNull('board_pending_since')->where('board_pending_since', '<', now()->subMinutes(self::STALE_MINUTES))->get();
        foreach ($stale as $cm)
            if (Cm::whereKey($cm->id)->where('board_pending_since', $cm->board_pending_since)->update(['board_pending_since' => null]))
                Cmlog::create(['cm' => $cm->mac, 'board' => null, 'loglevel' => 'warning',
                               'msg' => 'Switch port unknown: the lookup did not run within '.self::STALE_MINUTES.' minutes of the start']);
    }
}
