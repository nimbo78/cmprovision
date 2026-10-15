<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Cm extends Model
{
    use HasFactory;

    /* Phases a module reports while it works, in order, and the two final ones; the OS check (2.6.0) waits for the
       module's new system in firstboot */
    const ACTIVE_PHASES = ['preinstall', 'write', 'verify', 'postinstall', 'firstboot'];
    const PHASE_LABELS = [
        'preinstall' => 'Pre-install', 'write' => 'Writing image', 'verify' => 'Verifying',
        'postinstall' => 'Post-install', 'firstboot' => 'Checking the OS', 'done' => 'Done', 'failed' => 'Failed',
    ];
    /* Seconds without a report after which a module counts as silent: the image write and the
       verification report every few seconds, scripts only when they start */
    const STALE_AFTER_STREAMING = 60;
    const STALE_AFTER_SCRIPTS = 600;
    /* The OS check: the module reboots into its new system, which reports within this many seconds (FirstBootTimeout);
       it is quiet until then */
    const FIRSTBOOT_SECONDS = 900;
    const OS_NO_REPORT = 'No report from the new system in 15 min';
    /* A module still "in progress" this long after it started was unplugged */
    const ACTIVE_HOURS = 12;
    /* A run has a dozen steps; the cap only guards against a script that keeps renaming itself */
    const MAX_TIMELINE = 200;
    /* A finished module asks every 5 s whether to blink (led_poll_once in scriptexecute/leds.blade.php)
       and, while it blinks, every 2 s whether to go on: it blinks until identify_until, IDENTIFY_SECONDS
       after the operator pressed Identify, or until Stop. polled_at is written at most once per
       POLL_NOTE_SECONDS, so a module counts as on the bench for ON_BENCH_SECONDS after it. */
    const IDENTIFY_SECONDS = 30;
    const POLL_NOTE_SECONDS = 60;
    const ON_BENCH_SECONDS = 120;

    protected $fillable = [
        'serial','mac','model','memory_in_gb','storage','csd','cid','firmware',
        'image_filename', 'image_sha256', 'pre_script_output', 'post_script_output', 'script_return_code',
        'temp1', 'temp2', 'provisioning_board', 'provisioning_started_at', 'provisioning_complete_at', 'project_id',
        'phase', 'phase_detail', 'phase_started_at', 'progress_bytes', 'progress_total', 'progress_updated_at',
        'notification_batch_id', 'eeprom_before', 'eeprom_config_before', 'eeprom_config_after', 'eeprom_result',
        'switch_id', 'switch_name', 'switch_port', 'board_pending_since',
        'timeline', 'polled_at', 'identify_until',
        'os_check', 'hostname', 'os_state', 'os_failed_units', 'os_checked_at', 'os_check_output',
    ];

    protected $casts = [
        'provisioning_started_at' => 'datetime',
        'provisioning_complete_at' => 'datetime',
        'phase_started_at' => 'datetime',
        'progress_updated_at' => 'datetime',
        'progress_bytes' => 'integer',
        'progress_total' => 'integer',
        'timeline' => 'array',
        'polled_at' => 'datetime',
        'identify_until' => 'datetime',
        'board_pending_since' => 'datetime',
        'os_checked_at' => 'datetime',
    ];

    /* A module that finished and still asks the server whether to blink, i.e. is still on the bench */
    public function canIdentify()
    {
        return in_array($this->phase, ['done', 'failed'], true) && $this->polled_at
            && $this->polled_at->getTimestamp() >= now()->getTimestamp() - self::ON_BENCH_SECONDS;
    }

    public function isIdentifying()
    {
        return $this->identify_until && $this->identify_until->getTimestamp() > now()->getTimestamp();
    }

    /* The operator wants to see which one it is: the module blinks the next time it asks */
    public function identify()
    {
        if ($this->canIdentify())
        {
            $this->identify_until = now()->addSeconds(self::IDENTIFY_SECONDS);
            $this->save();
        }
        return $this;
    }

    /* The operator has found it: the module returns to its state at its next question */
    public function stopIdentify()
    {
        if ($this->identify_until)
        {
            $this->identify_until = null;
            $this->save();
        }
        return $this;
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /* Bootloader release date (YYYY-MM-DD) in a firmware file name (pieeprom-2026-09-23.bin), in what
       vcgencmd reports ("2026/09/23 12:02:14") or in a version read from the flash chip
       (BUILD_TIMESTAMP=<unix time>); null when there is none */
    public static function eepromVersionOf($text)
    {
        if ($text === null || $text === '')
            return null;
        if (preg_match('/pieeprom-(\d{4}-\d{2}-\d{2})\.bin/', $text, $m))
            return $m[1];
        if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2})\b/m', $text, $m))
            return $m[1].'-'.$m[2].'-'.$m[3];
        if (preg_match('/BUILD_TIMESTAMP=(\d+)/', $text, $m))
            return gmdate('Y-m-d', (int) $m[1]);
        return null;
    }

    public function eepromVersionBefore()
    {
        return self::eepromVersionOf($this->eeprom_before);
    }

    /* What the EEPROM holds after provisioning: the flashed image, or what it had when the project
       does not flash it. Unknown after a failed flash. */
    public function eepromVersionAfter()
    {
        if ($this->eeprom_result === 'failed')
            return null;
        return self::eepromVersionOf($this->firmware);
    }

    /* Enter a phase (or the next script within it); a change restarts the phase clock, goes into the
       timeline of the run, and entering a working phase restarts the byte counter (a failed module
       keeps how far it got) */
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

            $timeline = $this->timeline ?: [];
            if (count($timeline) < self::MAX_TIMELINE)
                $timeline[] = ['phase' => $phase, 'detail' => $detail, 'at' => now()->getTimestamp()];
            $this->timeline = $timeline;
        }
        $this->progress_updated_at = now();
        return $this;
    }

    public function markFailed($reason)
    {
        return $this->setPhase('failed', $reason);
    }

    /* The new system's report is welcome: the module waits for it (pending when its "written" report was lost), or its
       run failed only for want of it (a late report still counts) */
    public function awaitsOsReport()
    {
        return in_array($this->os_check, ['pending', 'waiting'], true)
            || ($this->os_check === 'failed' && $this->os_checked_at === null && $this->phase_detail === self::OS_NO_REPORT);
    }

    /* Failed the OS check by its own report: the module runs its new system, and a power cycle starts that again */
    public function failedInItsSystem()
    {
        return $this->phase === 'failed' && $this->os_check === 'failed' && $this->os_checked_at !== null;
    }

    public function isActive()
    {
        return in_array($this->phase, self::ACTIVE_PHASES, true);
    }

    /* In a working phase and started within ACTIVE_HOURS: the phase alone stays "write" for a module pulled mid-run */
    public function isRunning()
    {
        return $this->isActive() && $this->provisioning_started_at
            && $this->provisioning_started_at->getTimestamp() >= now()->subHours(self::ACTIVE_HOURS)->getTimestamp();
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
        $limit = $this->phase === 'firstboot' ? self::FIRSTBOOT_SECONDS
            : ($this->isStreaming() ? self::STALE_AFTER_STREAMING : self::STALE_AFTER_SCRIPTS);
        return $silent > $limit ? $silent : null;
    }
}
