<?php

namespace App\Models;

use App\Services\Snmp\SnmpClient;
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

    /* A port left off that the switch shows switched on again (at the switch, or a write it applied late): its row
       closes. The port's own object is read, not its PoE status: the status words differ by vendor (Huawei's are not
       verified) and a snapshot may have been read before the port went off. Only rows of this switch. */
    public static function closeSeenOn(SnmpClient $snmp, string $host): void
    {
        foreach (self::stayedOff()->where('switch_host', $host)->get() as $outage)
        {
            try
            {
                $on = (int) $snmp->get($outage->oid) === $outage->on_value;
            }
            catch (\Throwable $e)
            {
                continue;   // not readable now (a php-snmp warning too): the row stays
            }
            if ($on)
            {
                $outage->update(['on_at' => now(), 'error' => null]);
                $outage->log('info', 'PoE on '.$outage->port.' is on again (seen on the switch)');
            }
        }
    }

    /* A line of the module log about this port; a booting Raspberry Pi has no serial yet */
    public function log(string $level, string $message): void
    {
        Cmlog::create(['cm' => $this->cm_serial ?? '', 'board' => $this->port, 'loglevel' => $level, 'msg' => $message]);
    }
}
