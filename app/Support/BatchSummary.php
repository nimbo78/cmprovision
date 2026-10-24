<?php

namespace App\Support;

use App\Models\Cm;
use App\Models\NotificationBatch;

/**
 * A batch as the top bar, the browser tab and the dashboard show it: its modules by state, the average time of a
 * finished module, how long the running ones still need, which of them are slow (SlowRuns), and the tab's state (a failure outweighs a module that
 * stopped reporting, which outweighs running modules). counts are the bots' (NotificationBatch::counts()); silent
 * modules are among their active ones, and running is the active ones that still report.
 */
class BatchSummary
{
    public $batch, $project, $modules, $open, $counts, $silent, $running, $averageSeconds, $typicalSeconds, $slow, $leftSeconds, $state;

    /* The open batch, or null */
    public static function current()
    {
        $batch = NotificationBatch::open();
        return $batch ? new self($batch, true) : null;
    }

    /* The open batch, else the last one that is over */
    public static function forDashboard()
    {
        if ($summary = self::current())
            return $summary;
        $batch = NotificationBatch::lastClosed();
        return $batch ? new self($batch, false) : null;
    }

    public function __construct(NotificationBatch $batch, bool $open)
    {
        $this->batch = $batch;
        $this->open = $open;
        $this->project = $batch->project;
        $this->modules = $batch->cms()->get();
        $active = $this->modules->filter(function ($cm) { return in_array($cm->phase, Cm::ACTIVE_PHASES, true); });
        $done = $this->modules->where('phase', 'done');
        $this->counts = ['active' => $active->count(), 'done' => $done->count(), 'failed' => $this->modules->where('phase', 'failed')->count()];
        $reporting = $active->filter(function ($cm) { return $cm->silentFor() === null; });
        $this->running = $reporting->count();
        $this->silent = $this->counts['active'] - $this->running;

        $durations = $done->filter(function ($cm) { return $cm->provisioning_started_at && $cm->provisioning_complete_at; })
            ->map(function ($cm) { return $cm->provisioning_complete_at->getTimestamp() - $cm->provisioning_started_at->getTimestamp(); });
        $this->averageSeconds = $durations->isEmpty() ? null : (int) round($durations->avg());
        // the median of the finished runs, and the running ones that are well past it: [module id => seconds past it]
        $this->typicalSeconds = SlowRuns::typical($this->modules);
        $this->slow = SlowRuns::among($reporting, $this->typicalSeconds);
        // a silent module's estimate is frozen at its last report: only the reporting ones count
        $left = $reporting->map(function ($cm) { return $cm->secondsLeft(); })->filter(function ($s) { return $s !== null; });
        $this->leftSeconds = $left->isEmpty() ? null : (int) $left->max();

        $this->state = $this->counts['failed'] ? 'fail' : ($this->silent ? 'stale' : ($this->running ? 'run' : ($this->counts['done'] ? 'done' : 'idle')));
    }

    /* The start of the browser tab's title: what needs a look first, then the running or the done ones; empty when
       there is nothing to tell */
    public function title()
    {
        $c = $this->counts;
        $problems = array_filter([$c['failed'] ? "{$c['failed']} failed" : null, $this->silent ? "{$this->silent} no report" : null]);
        if (!$problems && !$this->running)
            return $c['done'] ? "✓ {$c['done']} done" : '';
        $rest = $this->running ? "{$this->running} running" : ($c['done'] ? "{$c['done']} done" : null);
        return implode(' · ', array_filter(array_merge($problems, [$rest])));
    }
}
