<?php

namespace App\Models;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/* Modules provisioned together: a batch lasts while modules of the same project keep coming,
   and ends after IDLE_HOURS without any, on a project change, or when started anew by hand. */
class NotificationBatch extends Model
{
    const IDLE_HOURS = 3;

    protected $fillable = ['project_id', 'started_at', 'last_event_at', 'closed_at'];
    protected $casts = [
        'started_at' => 'datetime',
        'last_event_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function cms()
    {
        return $this->hasMany(Cm::class, 'notification_batch_id');
    }

    /* The batch a starting module joins: the open one of this project, or a new one. 40-48 modules start at once:
       one of them chooses under the lock, the others wait and join it (after 5 s they choose anyway) */
    public static function current(Project $project)
    {
        try
        {
            return Cache::lock('notification-batch', 10)->block(5, function () use ($project) {
                return self::choose($project);
            });
        }
        catch (LockTimeoutException $e)
        {
            return self::choose($project);
        }
    }

    protected static function choose(Project $project)
    {
        $batch = self::whereNull('closed_at')->latest('id')->first();
        if ($batch && ($batch->project_id != $project->id || $batch->last_event_at->lt(now()->subHours(self::IDLE_HOURS))))
        {
            $batch->update(['closed_at' => now()]);
            $batch = null;
        }

        return $batch ?: self::create(['project_id' => $project->id, 'started_at' => now(), 'last_event_at' => now()]);
    }

    /* Close the open batch: the next module starts a new one (and a new thread) */
    public static function startNew()
    {
        self::whereNull('closed_at')->update(['closed_at' => now()]);
    }

    /* The batch the stand works on now: not closed, not idle and of the active project. Null when the next
       module starts a new one; nothing is created or closed here (current() does that for a module). */
    public static function open()
    {
        $active = Project::getActiveId();
        $batch = self::whereNull('closed_at')->latest('id')->first();
        if (!$batch || !$active || $batch->project_id != $active || $batch->last_event_at->lt(now()->subHours(self::IDLE_HOURS)))
            return null;
        return $batch;
    }

    /* The latest batch that is over: closed by hand or by a project change, or idle */
    public static function lastClosed()
    {
        $open = self::open();
        return self::when($open, function ($q) use ($open) { $q->where('id', '!=', $open->id); })->latest('id')->first();
    }

    public function touchEvent()
    {
        $this->last_event_at = now();
        $this->save();
    }

    /* Modules of the batch by their current state */
    public function counts()
    {
        $phases = $this->cms()->pluck('phase');
        return [
            'done' => $phases->filter(function ($p) { return $p === 'done'; })->count(),
            'failed' => $phases->filter(function ($p) { return $p === 'failed'; })->count(),
            'active' => $phases->filter(function ($p) { return in_array($p, Cm::ACTIVE_PHASES, true); })->count(),
        ];
    }
}
