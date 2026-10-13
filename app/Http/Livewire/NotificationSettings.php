<?php

namespace App\Http\Livewire;

use App\Models\NotificationBatch;
use App\Models\NotificationChannel;
use App\Services\Notifier;
use App\Services\TlsTrust;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/* Settings page section: notification channels and the current batch */
class NotificationSettings extends Component
{
    public $formOpen = false, $editingId = null;
    public $name = '', $type = 'mattermost_bot', $enabled = true, $events = ['completed', 'failed'];
    public $url = '', $serverUrl = '', $token = '', $channel = '', $botToken = '', $chatId = '', $topicId = '';
    public $foundChats = [];
    /* The server's certificate: the certificates to trust (PEM), or no check at all */
    public $tlsCa = '', $tlsInsecure = false, $tlsFetched = null;
    /* "host:port" the certificates in the form were saved or fetched for; null when the user typed them in */
    #[Locked]
    public $tlsCaFor = null;
    public $notice = null, $noticeIsError = false;

    public function render()
    {
        $batch = NotificationBatch::whereNull('closed_at')->latest('id')->first();
        return view('livewire.notification-settings', [
            'channels' => NotificationChannel::orderBy('name')->get(),
            'batch' => $batch,
            'batchCounts' => $batch ? $batch->counts() : null,
            'tlsCerts' => trim($this->tlsCa) !== '' ? (TlsTrust::describe($this->tlsCa) ?? []) : [],
        ]);
    }

    public function add()
    {
        $this->resetForm();
        $this->formOpen = true;
    }

    public function edit($id)
    {
        $channel = NotificationChannel::findOrFail($id);
        $this->resetForm();
        $this->editingId = $channel->id;
        $this->name = $channel->name;
        $this->type = $channel->type;
        $this->enabled = $channel->enabled;
        $this->events = $channel->events ?: [];
        $this->url = $channel->setting('url', '');
        $this->serverUrl = $channel->setting('server_url', '');
        $this->channel = $channel->setting('channel', $channel->setting('channel_id', ''));
        $this->chatId = (string) $channel->setting('chat_id', '');
        $this->topicId = (string) $channel->setting('topic_id', '');
        $this->tlsCa = (string) $channel->setting('tls_ca', '');
        $this->tlsCaFor = $channel->setting('tls_ca_for');
        $this->tlsInsecure = (bool) $channel->setting('tls_insecure', false);
        // secrets are not sent to the browser: an empty field keeps the stored value
        $this->formOpen = true;
    }

    public function cancel()
    {
        $this->resetForm();
    }

    public function save()
    {
        $existing = $this->editingId ? NotificationChannel::findOrFail($this->editingId) : null;
        $this->validate($this->channelRules($existing), [
            'token.required' => 'Enter the bot token: a stored token is only used for the server it was saved with.',
            'botToken.required' => 'Enter the bot token.',
        ]);
        $tls = $this->tlsSettings();
        if ($tls === null)
            return;

        $settings = $existing ? ($existing->settings ?: []) : [];
        switch ($this->type)
        {
            case 'mattermost_webhook':
            case 'webhook':
                $settings = ['url' => $this->url] + $tls;
                break;

            case 'mattermost_bot':
                $token = $this->token !== '' ? $this->token : ($this->keepsStoredToken($existing) ? $settings['token'] : '');
                $channelId = $this->resolveMattermostChannel($this->serverUrl, $token, $this->channel,
                                                             TlsTrust::options($tls['tls_ca'] ?? null, $tls['tls_insecure']));
                if ($channelId === null)
                    return;
                $settings = ['server_url' => rtrim($this->serverUrl, '/'), 'token' => $token,
                             'channel' => $this->channel, 'channel_id' => $channelId] + $tls;
                break;

            case 'telegram':
                $settings = ['bot_token' => $this->botToken !== '' ? $this->botToken : ($settings['bot_token'] ?? ''),
                             'chat_id' => $this->chatId, 'topic_id' => $this->topicId];
                break;
        }

        $values = ['name' => $this->name, 'type' => $this->type, 'enabled' => (bool) $this->enabled,
                   'settings' => $settings, 'events' => array_values($this->events)];
        if ($existing)
            $existing->update($values);
        else
            NotificationChannel::create($values);

        $this->resetForm();
        $this->flash('Saved.');
    }

    protected function channelRules($existing)
    {
        $rules = [
            'name' => 'required|string|max:100',
            'type' => ['required', Rule::in(array_keys(NotificationChannel::TYPES))],
            'events' => 'required|array|min:1',
            'events.*' => [Rule::in(array_keys(NotificationChannel::EVENTS))],
        ];
        $keepsSecret = $existing && $existing->type === $this->type;
        switch ($this->type)
        {
            case 'mattermost_webhook':
            case 'webhook':
                $rules['url'] = 'required|url|max:500';
                break;
            case 'mattermost_bot':
                $rules['serverUrl'] = 'required|url|max:200';
                $rules['token'] = ($this->keepsStoredToken($existing) ? 'nullable' : 'required').'|string|max:200';
                $rules['channel'] = 'required|string|max:300';
                break;
            case 'telegram':
                $rules['botToken'] = [$keepsSecret ? 'nullable' : 'required', 'regex:/^\d+:[A-Za-z0-9_-]+$/'];
                $rules['chatId'] = ['required', 'regex:/^(-?\d+|@\w{4,})$/'];
                $rules['topicId'] = 'nullable|integer|min:1';
                break;
        }
        if (in_array($this->type, NotificationChannel::TLS_TYPES, true))
        {
            $rules['tlsCa'] = 'nullable|string|max:20000';
            $rules['tlsInsecure'] = 'boolean';
        }
        return $rules;
    }

    /* The server address in the form for the chosen type */
    protected function formAddress()
    {
        return $this->type === 'mattermost_bot' ? $this->serverUrl : $this->url;
    }

    /* tls_insecure, tls_ca and tls_ca_for of the channel's settings; null after reporting an error */
    protected function tlsSettings()
    {
        if (!in_array($this->type, NotificationChannel::TLS_TYPES, true))
            return [];
        $settings = ['tls_insecure' => (bool) $this->tlsInsecure];
        if (trim($this->tlsCa) === '')
            return $settings;

        $pem = TlsTrust::normalize($this->tlsCa);
        if ($pem === null)
        {
            $this->addError('tlsCa', strpos($this->tlsCa, 'PRIVATE KEY') !== false
                ? 'This holds a private key: paste the certificate only.'
                : 'Paste one or more certificates in PEM (-----BEGIN CERTIFICATE-----).');
            return null;
        }
        $key = TlsTrust::addressKey($this->formAddress());
        if ($key === null)
        {
            $this->addError('tlsCa', 'A certificate is only for an https:// address.');
            return null;
        }
        // certificates saved or fetched for one server are no trust in another one
        if ($this->tlsCaFor !== null && $this->tlsCaFor !== $key)
        {
            $this->addError('tlsCa', 'This certificate belongs to '.$this->tlsCaFor.': fetch the certificate of '.$key.', or clear the field.');
            return null;
        }
        return $settings + ['tls_ca' => $pem, 'tls_ca_for' => $key];
    }

    /* Typed or pasted by the user: the certificates are for the server in the form */
    public function updatedTlsCa()
    {
        $this->tlsCaFor = null;
    }

    /* Takes the certificates the server in the form presents, unless the system trusts them already */
    public function fetchCertificate()
    {
        $this->tlsFetched = null;
        $this->resetErrorBag();   // what the last save said was about the trust before
        $address = (string) $this->formAddress();
        try
        {
            $fetched = app(TlsTrust::class)->fetch($address);
        }
        catch (\RuntimeException $e)
        {
            $this->addError('tlsCa', $e->getMessage());
            return;
        }

        $key = TlsTrust::addressKey($address);
        if ($fetched['trusted'])
        {
            // a certificate kept from before would only stand in the way, of another server even
            $this->tlsCa = '';
            $this->tlsCaFor = null;
            $this->tlsFetched = 'This system trusts the certificate of '.$key.' already: the channel needs no certificate of its own.';
            return;
        }
        if (!$fetched['chain'])
        {
            $this->addError('tlsCa', $key.' presented no certificate.');
            return;
        }
        $this->tlsCa = implode('', array_column($fetched['chain'], 'pem'));
        $this->tlsCaFor = $key;
        $server = $fetched['chain'][0];
        $host = trim(substr($key, 0, strrpos($key, ':')), '[]');
        $this->tlsFetched = "Fetched from $key: compare the SHA-256 fingerprint with the server's, then save."
            .(TlsTrust::covers($server, $host) ? ''
              : ' The certificate is for '.implode(', ', $server['names'] ?: [$server['subject']]).", not for $host: "
                .'put that name in the address, or accept any certificate.');
    }

    /* A Mattermost token left empty means "the stored one", but only for the server it was stored with:
       otherwise changing the address would send the hidden token to any host */
    protected function keepsStoredToken($existing)
    {
        return $existing && $existing->type === 'mattermost_bot' && $existing->setting('token') !== null
            && rtrim($existing->setting('server_url', ''), '/') === rtrim($this->serverUrl, '/');
    }

    /* Channel id from an id, a "team/channel" pair or a channel link; null after reporting an error */
    protected function resolveMattermostChannel($server, $token, $channel, array $tls)
    {
        $channel = trim($channel);
        if (preg_match('/^[a-z0-9]{26}$/', $channel))
            return $channel;

        $path = trim(parse_url($channel, PHP_URL_PATH) ?: $channel, '/');
        $parts = array_values(array_filter(explode('/', $path), function ($p) { return $p !== '' && $p !== 'channels'; }));
        if (count($parts) < 2)
        {
            $this->addError('channel', 'Paste the channel link, or write team/channel.');
            return null;
        }
        list($team, $name) = array_slice($parts, -2);

        try
        {
            $response = Http::timeout(10)->withOptions($tls)->withToken($token)->acceptJson()
                ->get(rtrim($server, '/').'/api/v4/teams/name/'.rawurlencode($team).'/channels/name/'.rawurlencode($name));
        }
        catch (\Throwable $e)
        {
            $this->addError('channel', 'Mattermost did not answer: '.TlsTrust::explain($e));
            return null;
        }
        if (!$response->successful() || !$response->json('id'))
        {
            $this->addError('channel', 'Mattermost: HTTP '.$response->status().' '.($response->json('message') ?: 'channel not found')
                .'. The bot has to be a member of the team and of a private channel.');
            return null;
        }
        return $response->json('id');
    }

    public function findTelegramChats()
    {
        $this->foundChats = [];
        $token = $this->botToken;
        if ($token === '' && $this->editingId)
            $token = NotificationChannel::findOrFail($this->editingId)->setting('bot_token', '');
        if ($token === '')
        {
            $this->addError('botToken', 'Enter the bot token first.');
            return;
        }

        try
        {
            $response = Http::timeout(10)->acceptJson()->get('https://api.telegram.org/bot'.$token.'/getUpdates');
        }
        catch (\Throwable $e)
        {
            $this->addError('botToken', 'Telegram did not answer: '.Notifier::redact(TlsTrust::explain($e, false)));
            return;
        }
        if (!$response->json('ok'))
        {
            $this->addError('botToken', 'Telegram: '.($response->json('description') ?: 'HTTP '.$response->status()));
            return;
        }

        foreach ($response->json('result') ?: [] as $update)
        {
            foreach (['message', 'channel_post', 'my_chat_member', 'edited_message'] as $kind)
            {
                $chat = $update[$kind]['chat'] ?? null;
                if ($chat && isset($chat['id']))
                {
                    $title = $chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: ($chat['username'] ?? '');
                    $this->foundChats[(string) $chat['id']] = $title.' ('.($chat['type'] ?? 'chat').')';
                }
            }
        }
        if (!$this->foundChats)
            $this->addError('chatId', 'The bot has not seen any chat yet: add it to the group and write a message there, then try again.');
    }

    public function useChat($id)
    {
        $this->chatId = (string) $id;
        $this->foundChats = [];
    }

    public function test($id)
    {
        $channel = NotificationChannel::findOrFail($id);
        try
        {
            (new Notifier)->test($channel);
            $this->flash('Test message sent to '.$channel->name.'.');
        }
        catch (\Throwable $e)
        {
            $message = Notifier::errorText($e, $channel);
            $channel->update(['last_error' => now()->local()->format('Y-m-d H:i').' '.$message]);
            $this->flash('Sending to '.$channel->name.' failed: '.$message, true);
        }
    }

    public function toggle($id)
    {
        $channel = NotificationChannel::findOrFail($id);
        $channel->update(['enabled' => !$channel->enabled]);
    }

    public function delete($id)
    {
        NotificationChannel::destroy($id);
        if ($this->editingId == $id)
            $this->resetForm();
    }

    public function newBatch()
    {
        NotificationBatch::startNew();
        $this->flash('The next module starts a new batch.');
    }

    protected function flash($text, $error = false)
    {
        $this->notice = $text;
        $this->noticeIsError = $error;
    }

    protected function resetForm()
    {
        $this->resetErrorBag();
        $this->formOpen = false;
        $this->editingId = null;
        $this->name = $this->url = $this->serverUrl = $this->token = $this->channel = $this->botToken = $this->chatId = $this->topicId = '';
        $this->tlsCa = '';
        $this->tlsInsecure = false;
        $this->tlsCaFor = $this->tlsFetched = null;
        $this->type = 'mattermost_bot';
        $this->enabled = true;
        $this->events = ['completed', 'failed'];
        $this->foundChats = [];
    }
}
