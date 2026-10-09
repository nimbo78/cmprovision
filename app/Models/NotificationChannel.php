<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/* Where provisioning notifications go. Settings depend on the type, see TYPES. */
class NotificationChannel extends Model
{
    const TYPES = [
        'mattermost_bot' => 'Mattermost bot (thread per batch)',
        'telegram' => 'Telegram bot (thread per batch)',
        'mattermost_webhook' => 'Mattermost / Slack incoming webhook',
        'webhook' => 'Webhook (JSON)',
    ];
    /* Types that keep a batch summary message with the modules as replies under it */
    const THREADED = ['mattermost_bot', 'telegram'];
    const EVENTS = ['started' => 'Module started', 'completed' => 'Module done', 'failed' => 'Module failed'];

    protected $fillable = ['name', 'type', 'enabled', 'settings', 'events', 'last_sent_at', 'last_error'];
    protected $casts = [
        'enabled' => 'boolean',
        'settings' => 'array',
        'events' => 'array',
        'last_sent_at' => 'datetime',
    ];

    public function setting($key, $default = null)
    {
        $settings = $this->settings ?: [];
        return isset($settings[$key]) && $settings[$key] !== '' ? $settings[$key] : $default;
    }

    public function wants($event)
    {
        return in_array($event, $this->events ?: [], true);
    }

    public function isThreaded()
    {
        return in_array($this->type, self::THREADED, true);
    }

    public function typeLabel()
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
