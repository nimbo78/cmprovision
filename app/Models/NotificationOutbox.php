<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/* A provisioning event waiting for its channel (FlushNotifications); sent_at: it went out or was given up */
class NotificationOutbox extends Model
{
    protected $table = 'notification_outbox';
    protected $fillable = ['channel_id', 'batch_id', 'cm_id', 'event', 'attempts', 'error', 'sent_at'];
    protected $casts = ['sent_at' => 'datetime', 'attempts' => 'integer'];

    public function cm()
    {
        return $this->belongsTo(Cm::class);
    }

    public function batch()
    {
        return $this->belongsTo(NotificationBatch::class, 'batch_id');
    }
}
