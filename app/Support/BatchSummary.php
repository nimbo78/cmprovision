<?php

namespace App\Support;

use App\Models\Cm;
use App\Models\NotificationBatch;

/**
 * A batch as the top bar, the browser tab and the dashboard show it: its modules by state, the average time of a
 * finished module, how long the running ones still need, and the tab's state (a failure outweighs running modules).
 */
class BatchSummary
{
    public $batch, $project, $modules, $open, $counts, $averageSeconds, $leftSeconds, $state;

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

        $durations = $done->filter(function ($cm) { return $cm->provisioning_started_at && $cm->provisioning_complete_at; })
            ->map(function ($cm) { return $cm->provisioning_complete_at->getTimestamp() - $cm->provisioning_started_at->getTimestamp(); });
        $this->averageSeconds = $durations->isEmpty() ? null : (int) round($durations->avg());
        $left = $active->map(function ($cm) { return $cm->secondsLeft(); })->filter(function ($s) { return $s !== null; });
        $this->leftSeconds = $left->isEmpty() ? null : (int) $left->max();

        $this->state = $this->counts['failed'] ? 'fail' : ($this->counts['active'] ? 'run' : ($this->counts['done'] ? 'done' : 'idle'));
    }

    /* The start of the browser tab's title; empty when there is nothing to tell */
    public function title()
    {
        $c = $this->counts;
        if ($c['failed'] && $c['active'])
            return "{$c['failed']} failed · {$c['active']} running";
        if ($c['failed'])
            return "{$c['failed']} failed · {$c['done']} done";
        if ($c['active'])
            return "{$c['active']} running";
        return $c['done'] ? "✓ {$c['done']} done" : '';
    }
}
