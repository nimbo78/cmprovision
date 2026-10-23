<?php

namespace App\Http\Livewire;

use App\Http\Livewire\Concerns\SortsColumns;
use App\Models\Cm;
use App\Models\EthernetSwitch;
use App\Models\NotificationBatch;
use App\Models\Project;
use App\Services\Switch\PoeControl;
use App\Services\Switch\PoeRestart;
use App\Support\BatchSummary;
use App\Support\ColumnSort;
use Livewire\Component;

/* Dashboard panel: the batch on the stand (its totals and modules), modules running outside it, and modules
   from before batches existed that finished recently */
class ProvisioningStatus extends Component
{
    const RECENT_HOURS = 2;      // modules outside any batch stay visible this long after they finish
    const ACTIVE_HOURS = Cm::ACTIVE_HOURS;     // a module still "in progress" after this long was unplugged
    const MAX_ROWS = 100;    // a stand of two 48-port switches

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

    /* The failed and silent modules of the open batch, but for one that failed its OS check in its new system: a power
       cycle boots that system again */
    public function restartFailed()
    {
        $summary = BatchSummary::current();
        $cms = $summary ? $summary->modules->filter(function ($cm) {
            return ($cm->phase === 'failed' || $cm->silentFor() !== null) && !$cm->failedInItsSystem();
        }) : [];
        $this->report(PoeRestart::modules($cms, auth()->user()->name ?? null));
    }

    protected function report(array $result): void
    {
        ['text' => $this->restartMessage, 'error' => $this->restartError] = PoeRestart::message($result);
        $this->dispatch('poe-outages-changed');   // a port left off shows in the banner at once
    }

    use SortsColumns;

    /* The columns of the table that sort the rows by a click on their header; with none chosen the order is Cm::newestFirst() */
    protected function columns(): array
    {
        $step = ['failed' => 0, 'preinstall' => 1, 'write' => 2, 'verify' => 3, 'postinstall' => 4, 'firstboot' => 5, 'done' => 6];
        return [
            'board' => ['first' => 'asc', 'value' => fn (Cm $cm) => $cm->board_pending_since || trim((string) $cm->provisioning_board) === '' ? null : (string) $cm->provisioning_board],
            'module' => ['first' => 'asc', 'value' => fn (Cm $cm) => (string) ($cm->mac ?: $cm->serial)],
            'step' => ['first' => 'asc', 'value' => fn (Cm $cm) => $step[$cm->phase] ?? null],
            'progress' => ['first' => 'desc', 'value' => fn (Cm $cm) => $cm->progressPercent()],
            'time' => ['first' => 'desc', 'value' => fn (Cm $cm) => $cm->flashedAt()?->getTimestamp()],
        ];
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
            ->values();
        // what runs now first, then by the time they were flashed, the latest on top (Cm::newestFirst()), or by the chosen column
        [$sort, $dir] = $this->sorting();
        $modules = ColumnSort::apply($modules, $this->columns(), $sort, $dir, Cm::newestFirst());

        // a module's switch must take writes; one found before 2.4 (no switch_id) needs any switch that does
        $writable = EthernetSwitch::all()->filter(function ($switch) { return PoeControl::writable($switch->config()); })->modelKeys();
        $canRestart = function (Cm $cm) use ($writable) {
            if ($cm->failedInItsSystem())
                return false;   // it runs its new system: a power cycle boots that again, it does not provision
            return $cm->switch_id ? in_array($cm->switch_id, $writable) : (bool) $writable;
        };
        $restartable = $summary && $summary->open ? $summary->modules->filter(function ($cm) use ($canRestart) {
            return ($cm->phase === 'failed' || $cm->silentFor() !== null) && $canRestart($cm);
        }) : collect();
        $active = Project::getActive();   // once for every row's Restart question
        return view('livewire.provisioning-status', [
            'summary' => $summary,
            'modules' => $modules,
            'sort' => $sort,
            'dir' => $dir,
            'activeCount' => $modules->filter->isActive()->count(),
            'canRestart' => $canRestart,
            'activeProject' => $active,
            'failedToRestart' => count($restartable),
            'resumingToRestart' => self::resuming($restartable, $active),
        ]);
    }

    /* How many of $cms go on with their write after a restart (2.6.1) */
    public static function resuming($cms, ?Project $active): int
    {
        return collect($cms)->filter(function (Cm $cm) use ($active) { return $cm->resumableFrom($active) !== null; })->count();
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

    /* The time of day in the display time zone; with the day and month (d.m) when it was not today: a batch can last days */
    public static function clock($time)
    {
        $local = $time->local();
        return $local->isToday() ? $local->format('H:i') : $local->format('d.m H:i');
    }

    public static function duration($seconds)
    {
        $seconds = max(0, (int) $seconds);
        if ($seconds >= 3600)
            return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
