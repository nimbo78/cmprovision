<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    /* The open batch of this project, or a new one */
    public static function current(Project $project)
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
