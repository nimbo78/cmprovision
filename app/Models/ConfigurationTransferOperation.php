<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigurationTransferOperation extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'session_hash', 'direction', 'status', 'format', 'options', 'secrets',
        'result', 'error', 'progress_bytes', 'total_bytes', 'expires_at',
    ];

    protected $hidden = ['session_hash', 'options', 'secrets'];

    protected $casts = [
        'user_id' => 'integer', 'options' => 'encrypted:array', 'secrets' => 'encrypted:array',
        'result' => 'array', 'progress_bytes' => 'integer', 'total_bytes' => 'integer', 'expires_at' => 'datetime',
    ];
}
