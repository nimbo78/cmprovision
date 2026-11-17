<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StationSetupRun extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['settings', 'run', 'host_key', 'web_setup_code'];
    protected $casts = ['settings' => 'encrypted:array', 'web_setup_code' => 'encrypted', 'expires_at' => 'datetime'];

    public static function availableCode(Cm $cm): \Illuminate\Database\Eloquent\Builder
    {
        return self::where('cm_id', $cm->id)->where('run', $cm->diagnostics_token)
            ->where('target_serial', $cm->serial)->where('target_cid', $cm->cid)
            ->where('state', 'applied')->where('expires_at', '>', now())->whereNotNull('web_setup_code');
    }
}
