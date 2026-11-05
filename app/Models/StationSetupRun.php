<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StationSetupRun extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['settings', 'run', 'host_key'];
    protected $casts = ['settings' => 'encrypted:array', 'expires_at' => 'datetime'];
}
