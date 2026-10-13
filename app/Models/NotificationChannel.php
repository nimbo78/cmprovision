<?php

namespace App\Models;

use App\Services\TlsTrust;
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
    /* Types whose server the user names: they may trust a certificate of their own, or none at all (TlsTrust) */
    const TLS_TYPES = ['mattermost_bot', 'mattermost_webhook', 'webhook'];
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

    /* The server the user named: the Mattermost server or the webhook; null for Telegram */
    public function address()
    {
        if ($this->type === 'mattermost_bot')
            return $this->setting('server_url');
        return in_array($this->type, self::TLS_TYPES, true) ? $this->setting('url') : null;
    }

    /* Guzzle options for the channel's requests: no certificate check, or the certificates the channel trusts, which
       count only for the server they were saved with (settings tls_insecure, tls_ca, tls_ca_for) */
    public function tlsOptions()
    {
        if (!in_array($this->type, self::TLS_TYPES, true))
            return [];
        $key = TlsTrust::addressKey($this->address());
        $pem = $key !== null && $this->setting('tls_ca_for') === $key ? $this->setting('tls_ca') : null;
        return TlsTrust::options($pem, (bool) $this->setting('tls_insecure', false));
    }
}
