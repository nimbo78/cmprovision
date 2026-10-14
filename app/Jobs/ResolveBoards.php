<?php

namespace App\Jobs;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Services\LabelPrinter;
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
   take boards): settle() records each module once, and whoever records it prints the preinstall label */
class ResolveBoards implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable;

    const RETRY_SECONDS = 3;
    const GIVE_UP_SECONDS = 60;

    public $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue('boards');
    }

    public function handle(): void
    {
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
        // the sync queue of the tests runs a dispatch at once: no next run there
        if ($waiting && !$this->job instanceof SyncJob)
            self::dispatch()->delay(now()->addSeconds(self::RETRY_SECONDS));
    }

    /** Record what the lookup found for this start of the module; false when the module started again meanwhile or
        another run recorded it first. A module refused at its start (failed by now) gets no label, as before 2.5 */
    public static function settle(Cm $cm, array $found): bool
    {
        $recorded = Cm::whereKey($cm->id)->where('board_pending_since', $cm->board_pending_since)->update([
            'provisioning_board' => $found['board'],
            'switch_id' => $found['switch']->id ?? null,
            'switch_name' => $found['switch'] ? $found['switch']->hostname() : null,
            'switch_port' => $found['port'],
            'board_pending_since' => null,
        ]);
        if (!$recorded)
            return false;
        $cm->refresh();
        if ($found['warning'])
            Cmlog::create(['cm' => $cm->serial, 'board' => $cm->provisioning_board, 'loglevel' => 'warning', 'msg' => $found['warning']]);
        if ($cm->phase !== 'failed' && $cm->project && $cm->project->label_moment === 'preinstall' && $cm->project->label)
            LabelPrinter::print($cm);
        return true;
    }
}
