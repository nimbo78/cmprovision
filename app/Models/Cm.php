<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Cm extends Model
{
    use HasFactory;

    /* Phases a module reports while it works, in order, and the two final ones */
    const ACTIVE_PHASES = ['preinstall', 'write', 'verify', 'postinstall'];
    const PHASE_LABELS = [
        'preinstall' => 'Pre-install', 'write' => 'Writing image', 'verify' => 'Verifying',
        'postinstall' => 'Post-install', 'done' => 'Done', 'failed' => 'Failed',
    ];
    /* Seconds without a report after which a module counts as silent: the image write and the
       verification report every few seconds, scripts only when they start */
    const STALE_AFTER_STREAMING = 60;
    const STALE_AFTER_SCRIPTS = 600;

    protected $fillable = [
        'serial','mac','model','memory_in_gb','storage','csd','cid','firmware',
        'image_filename', 'image_sha256', 'pre_script_output', 'post_script_output', 'script_return_code',
        'temp1', 'temp2', 'provisioning_board', 'provisioning_started_at', 'provisioning_complete_at', 'project_id',
        'phase', 'phase_detail', 'phase_started_at', 'progress_bytes', 'progress_total', 'progress_updated_at',
    ];

    protected $casts = [
        'provisioning_started_at' => 'datetime',
        'provisioning_complete_at' => 'datetime',
        'phase_started_at' => 'datetime',
        'progress_updated_at' => 'datetime',
        'progress_bytes' => 'integer',
        'progress_total' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /* Enter a phase (or the next script within it); a change restarts the phase clock, and entering
       a working phase restarts the byte counter (a failed module keeps how far it got) */
    public function setPhase($phase, $detail = null)
    {
        $detail = ($detail === null || $detail === '') ? null : Str::limit($detail, 250);
        if ($this->phase !== $phase || $this->phase_detail !== $detail)
        {
            if (in_array($phase, self::ACTIVE_PHASES, true))
                $this->progress_bytes = null;
            $this->phase = $phase;
            $this->phase_detail = $detail;
            $this->phase_started_at = now();
        }
        $this->progress_updated_at = now();
        return $this;
    }

    public function markFailed($reason)
    {
        return $this->setPhase('failed', $reason);
    }

    public function isActive()
    {
        return in_array($this->phase, self::ACTIVE_PHASES, true);
    }

    public function isStreaming()
    {
        return in_array($this->phase, ['write', 'verify'], true);
    }

    public function phaseLabel()
    {
        $label = self::PHASE_LABELS[$this->phase] ?? $this->phase;
        if ($this->phase_detail && $this->phase !== 'failed')
            $label .= ': '.$this->phase_detail;
        return $label;
    }

    /* Whole percent of the image written or verified, null when unknown */
    public function progressPercent()
    {
        if ($this->phase === 'done')
            return 100;
        if (!($this->isStreaming() || $this->phase === 'failed') || !$this->progress_total || $this->progress_bytes === null)
            return null;
        return (int) min(100, floor($this->progress_bytes * 100 / $this->progress_total));
    }

    /* Average bytes per second since the phase started, null until there is something to average */
    public function bytesPerSecond()
    {
        if (!$this->isStreaming() || !$this->progress_bytes || !$this->phase_started_at || !$this->progress_updated_at)
            return null;
        $seconds = $this->progress_updated_at->getTimestamp() - $this->phase_started_at->getTimestamp();
        return $seconds > 0 ? $this->progress_bytes / $seconds : null;
    }

    public function secondsLeft()
    {
        $speed = $this->bytesPerSecond();
        if (!$speed || !$this->progress_total)
            return null;
        return (int) max(0, round(($this->progress_total - $this->progress_bytes) / $speed));
    }

    /* Seconds since the last report when that is longer than a module in this phase stays quiet */
    public function silentFor()
    {
        if (!$this->isActive() || !$this->progress_updated_at)
            return null;
        $silent = now()->getTimestamp() - $this->progress_updated_at->getTimestamp();
        $limit = $this->isStreaming() ? self::STALE_AFTER_STREAMING : self::STALE_AFTER_SCRIPTS;
        return $silent > $limit ? $silent : null;
    }
}
