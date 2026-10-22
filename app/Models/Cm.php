<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Support\ImagePieces;

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
        'serial','mac','model','memory_in_gb','storage','csd','cid','firmware','provisioning_storage',
        'image_filename', 'image_sha256', 'pre_script_output', 'post_script_output', 'script_return_code',
        'temp1', 'temp2', 'provisioning_board', 'provisioning_started_at', 'provisioning_complete_at', 'project_id',
        'phase', 'phase_detail', 'phase_started_at', 'progress_bytes', 'progress_total', 'progress_updated_at',
        'notification_batch_id', 'eeprom_before', 'eeprom_config_before', 'eeprom_config_after', 'eeprom_result',
        'switch_id', 'switch_name', 'switch_port', 'board_pending_since',
        'timeline', 'polled_at', 'identify_until',
        'os_check', 'hostname', 'os_state', 'os_failed_units', 'os_checked_at', 'os_check_output',
        'durable_bytes', 'durable_image_id', 'durable_at', 'progress_base', 'utility_os',
        'diagnostics_token', 'storage_diagnostics', 'storage_diagnostics_at', 'diagnostics_requested_at',
    ];

    // The snapshot is up to 32 KiB per module with kernel output: it is read through the card and its download, not the API.
    protected $hidden = ['diagnostics_token', 'storage_diagnostics'];

    // The original storage_diagnostics columns also hold the extended module snapshot.
    protected $casts = [
        'storage_diagnostics' => 'array',
        'storage_diagnostics_at' => 'datetime',
        'diagnostics_requested_at' => 'datetime',
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
        'durable_bytes' => 'integer',
        'durable_image_id' => 'integer',
        'durable_at' => 'datetime',
        'progress_base' => 'integer',
    ];

    /* Only a current utility-OS script that already sent a snapshot understands the request. */
    public function canCollectModuleDiagnostics(): bool
    {
        return $this->diagnostics_token && $this->storage_diagnostics_at && $this->os_checked_at === null
            && in_array($this->phase, ['done', 'failed'], true) && $this->polled_at
            && $this->polled_at->gt(now()->subSeconds(self::ON_BENCH_SECONDS));
    }

    /* A module that finished and still asks the server whether to blink, i.e. is still on the bench */
    public function canIdentify()
    {
        return in_array($this->phase, ['done', 'failed'], true) && $this->polled_at
            && $this->polled_at->getTimestamp() >= now()->getTimestamp() - self::ON_BENCH_SECONDS;
    }

    /** The utility OS of the last start: our version, or the legacy system. */
    public function utilityOsLabel(): string
    {
        return $this->utility_os ?? 'legacy';
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
            {
                $this->progress_bytes = null;
                $this->progress_base = null;
            }
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

    /* Where the next start of this module goes on with the image write (2.6.1): the start of the first piece not durably
       written, in bytes, or null when it writes from the beginning. The run stopped in the write, with at least one piece
       durable, for the project still active with "Split the image into pieces", whose image's pieces copy is ready and has
       that piece. The marks belong to this run: a run started after them (one that does not know them, as 2.6.0 after a
       rollback) wrote over what they say. $project: the active project, looked up when not given. */
    public function resumableFrom(?Project $project = null)
    {
        $project = $project ?: Project::getActive();
        if (!$project || !$project->split_image || !$this->durable_bytes || !$this->durable_image_id)
            return null;
        if ($this->provisioning_storage && $this->provisioning_storage !== $project->storage)
            return null;
        if ((int) $this->project_id !== (int) $project->id || (int) $project->image_id !== (int) $this->durable_image_id)
            return null;
        if (!$this->durable_at || !$this->provisioning_started_at || $this->durable_at->lt($this->provisioning_started_at))
            return null;
        if (!$this->stoppedInTheWrite())
            return null;
        $image = $project->image;
        if (!$image || !$image->splitReady())
            return null;
        $piece = ImagePieces::resumePiece($this->durable_bytes, $image->split_piece_mb);
        $pieces = count($image->splitOffsets()) - 2;
        return $piece >= 1 && $piece < $pieces ? ImagePieces::pieceStart($piece, $image->split_piece_mb) : null;
    }

    /* The run stopped in the image write: the module restarted mid-write, or the write was its last step before it failed */
    public function stoppedInTheWrite()
    {
        if ($this->phase === 'write')
            return true;
        $entries = array_values($this->timeline ?: []);
        $count = count($entries);
        return $this->phase === 'failed' && $count >= 2 && $entries[$count - 2]['phase'] === 'write';
    }

    /* The run goes on with the write after a restart (2.6.1): always a step of its own (it notes where it starts in the
       image for RunTimeline), its clock and byte counter from there */
    public function resumeWrite(string $detail, int $from)
    {
        $this->phase = 'write';
        $this->phase_detail = $detail;
        $this->phase_started_at = now();
        $this->progress_bytes = $from;
        $this->progress_base = $from;
        $this->progress_updated_at = now();
        $timeline = $this->timeline ?: [];
        if (count($timeline) < self::MAX_TIMELINE)
            $timeline[] = ['phase' => 'write', 'detail' => $detail, 'at' => now()->getTimestamp(), 'from' => $from];
        $this->timeline = $timeline;
        return $this;
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

    /* Average bytes per second since the phase started, null until there is something to average; a write that went on
       after a restart (2.6.1) counts from where it went on (progress_base) */
    public function bytesPerSecond()
    {
        if (!$this->isStreaming() || !$this->progress_bytes || !$this->phase_started_at || !$this->progress_updated_at)
            return null;
        $seconds = $this->progress_updated_at->getTimestamp() - $this->phase_started_at->getTimestamp();
        $written = $this->progress_bytes - (int) $this->progress_base;
        return $seconds > 0 && $written > 0 ? $written / $seconds : null;
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
