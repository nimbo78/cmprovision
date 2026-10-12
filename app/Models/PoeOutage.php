<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/* A port whose PoE cmprovision switched off for a restart (App\Services\Switch\PoeRestart). on_at: on again, or
   forgotten by the operator */
class PoeOutage extends Model
{
    /* An open row without an error is a restart in its dark seconds for this long; after it, the restart stopped midway */
    const STUCK_SECONDS = 120;

    protected $fillable = ['switch_host', 'if_index', 'port', 'oid', 'on_value', 'cm_serial', 'user', 'off_at', 'on_at', 'error'];

    protected $casts = ['off_at' => 'datetime', 'on_at' => 'datetime', 'if_index' => 'integer', 'on_value' => 'integer'];

    /* Ports left off: switching on failed, or the restart stopped midway */
    public function scopeStayedOff($query)
    {
        return $query->whereNull('on_at')->where(function ($q) {
            $q->whereNotNull('error')->orWhere('off_at', '<', now()->subSeconds(self::STUCK_SECONDS));
        })->orderBy('off_at');
    }

    /* A port left off that the switch shows with PoE on again (switched on at the switch): its row closes. Only a
       snapshot taken after the port went off counts, and only for the switch it belongs to. */
    public static function closeSeenOn(?array $snapshot, string $host): void
    {
        if (!$snapshot)
            return;
        foreach (self::stayedOff()->where('switch_host', $host)->get() as $outage)
        {
            $poe = $snapshot['ports'][$outage->if_index]['poe'] ?? null;
            if ($poe && $poe['state'] !== 'disabled' && $snapshot['taken_at'] > $outage->off_at->getTimestamp())
            {
                $outage->update(['on_at' => now(), 'error' => null]);
                Cmlog::create(['cm' => $outage->cm_serial, 'board' => $outage->port, 'loglevel' => 'info',
                    'msg' => 'PoE on '.$outage->port.' is on again (seen on the switch)']);
            }
        }
    }
}
