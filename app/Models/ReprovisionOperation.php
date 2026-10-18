<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReprovisionOperation extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $attributes = ['state' => 'checking', 'password_changed' => false];
    protected $hidden = ['session_hash'];
    protected $casts = ['identity' => 'array', 'password_changed' => 'boolean', 'expires_at' => 'datetime', 'user_id' => 'integer', 'project_id' => 'integer'];
    const PENDING = ['dispatching', 'waiting', 'unknown'];

    public static function pending(string $serial): ?self
    {
        return static::where('serial', $serial)->whereIn('state', self::PENDING)->latest('created_at')->first();
    }
}
