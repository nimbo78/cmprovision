<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/* A USB observation can precede registration. It is never a provisioning run or a batch result. */
class UsbDiagnosticReport extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['report' => 'array', 'received_at' => 'datetime'];
}
