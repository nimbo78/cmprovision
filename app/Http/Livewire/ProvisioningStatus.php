<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\NotificationBatch;
use App\Services\Switch\PoeControl;
use App\Services\Switch\PoeRestart;
use App\Services\SwitchPortFinder;
use App\Support\BatchSummary;
use Livewire\Component;

/* Dashboard panel: the batch on the stand (its totals and modules), modules running outside it, and modules
   from before batches existed that finished recently */
class ProvisioningStatus extends Component
{
    const RECENT_HOURS = 2;      // modules outside any batch stay visible this long after they finish
    const ACTIVE_HOURS = Cm::ACTIVE_HOURS;     // a module still "in progress" after this long was unplugged
    const MAX_ROWS = 50;

    public $restartMessage = null, $restartError = false;

    /* The module blinks for the operator the next time it asks the server */
    public function identify($id)
    {
        $cm = Cm::find($id);
        if ($cm)
            $cm->identify();
    }

    public function stopIdentify($id)
    {
        $cm = Cm::find($id);
        if ($cm)
            $cm->stopIdentify();
    }

    /* Close the batch: the next module starts a new one, here and in the bots' threads */
    public function newBatch()
    {
        NotificationBatch::startNew();
    }

    /* Switch the PoE of a module's port off and on (PoeRestart); its port is looked up on a fresh snapshot */
    public function restart($id)
    {
        $cm = Cm::find($id);
        if ($cm)
            $this->report(PoeRestart::modules([$cm], auth()->user()->name ?? null));
    }

    /* The failed and silent modules of the open batch */
    public function restartFailed()
    {
        $summary = BatchSummary::current();
        $cms = $summary ? $summary->modules->filter(function ($cm) { return $cm->phase === 'failed' || $cm->silentFor() !== null; }) : [];
        $this->report(PoeRestart::modules($cms, auth()->user()->name ?? null));
    }

    protected function report(array $result): void
    {
        ['text' => $this->restartMessage, 'error' => $this->restartError] = PoeRestart::message($result);
    }

    public function render()
    {
        $summary = BatchSummary::forDashboard();
        $recent = now()->subHours(self::RECENT_HOURS);
        $modules = Cm::whereNotNull('phase')
            ->where(function ($q) use ($summary, $recent) {
                if ($summary)
                    $q->where('notification_batch_id', $summary->batch->id);
                $q->orWhere(function ($q) {
                      $q->whereIn('phase', Cm::ACTIVE_PHASES)
                        ->where('provisioning_started_at', '>=', now()->subHours(self::ACTIVE_HOURS));
                  })
                  ->orWhere(function ($q) use ($recent) {
                      $q->whereNull('notification_batch_id')->whereIn('phase', ['done', 'failed'])->where('phase_started_at', '>=', $recent);
                  });
            })
            ->orderByDesc('provisioning_started_at')
            ->limit(self::MAX_ROWS)
            ->get()
            // port (or jumper board) order: every row stays where its module is plugged in
            ->sort(function ($a, $b) {
                return strnatcasecmp((string) $a->provisioning_board, (string) $b->provisioning_board)
                    ?: strcmp($a->serial, $b->serial);
            })
            ->values();

        $writable = PoeControl::writable(SwitchPortFinder::config());
        return view('livewire.provisioning-status', [
            'summary' => $summary,
            'modules' => $modules,
            'activeCount' => $modules->filter->isActive()->count(),
            'writable' => $writable,
            'failedToRestart' => $writable && $summary && $summary->open ? $summary->counts['failed'] + $summary->silent : 0,
        ]);
    }

    public static function bytes($bytes)
    {
        $mib = $bytes / 1048576;
        return $mib < 1024 ? sprintf('%d MB', $mib) : sprintf('%.1f GB', $mib / 1024);
    }

    public static function speed($bytesPerSecond)
    {
        return sprintf('%.1f MB/s', $bytesPerSecond / 1048576);
    }

    public static function duration($seconds)
    {
        $seconds = max(0, (int) $seconds);
        if ($seconds >= 3600)
            return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
