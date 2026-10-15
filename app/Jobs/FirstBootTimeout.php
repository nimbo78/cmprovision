<?php

namespace App\Jobs;

use App\Events\CmProvisioningFailed;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\NotificationBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/* The OS check (2.6.0): Cm::FIRSTBOOT_SECONDS after a module rebooted into its new system nothing has reported. Queued
   when the module reports its image written (ScriptExecuteController); a report that comes later still counts. A run
   started since, or one that has its report, is left alone. */
class FirstBootTimeout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $cmId, $runStartedAt;
    public $timeout = 80;   // under the database queue's retry_after (90 s)

    public function __construct(int $cmId, int $runStartedAt)
    {
        $this->cmId = $cmId;
        $this->runStartedAt = $runStartedAt;
        $this->onQueue('boards');
    }

    public function handle(): void
    {
        $cm = Cm::find($this->cmId);
        if (!$cm || $cm->phase !== 'firstboot' || !$cm->provisioning_started_at
            || $cm->provisioning_started_at->getTimestamp() !== $this->runStartedAt)
            return;
        // early: only the tests' sync queue runs a delayed job at once
        if ($cm->phase_started_at && $cm->phase_started_at->getTimestamp() + Cm::FIRSTBOOT_SECONDS > now()->getTimestamp())
            return;

        $cm->os_check = 'failed';
        $cm->markFailed(Cm::OS_NO_REPORT)->save();
        Cmlog::create(['cm' => $cm->serial, 'board' => $cm->provisioning_board, 'loglevel' => 'error',
            'msg' => Cm::OS_NO_REPORT.': the new system did not start, has no network to the server, or the module was unplugged.']);
        if ($cm->notification_batch_id)
            NotificationBatch::whereKey($cm->notification_batch_id)->update(['last_event_at' => now()]);
        CmProvisioningFailed::dispatch($cm);
    }
}
