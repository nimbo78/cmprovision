<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/* The summary message a bot channel posted for a batch; module messages are replies to it */
class NotificationThread extends Model
{
    protected $fillable = ['batch_id', 'channel_id', 'external_id'];
}
