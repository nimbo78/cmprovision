<?php

namespace App\Http\Livewire;

use App\Models\NotificationBatch;
use App\Models\NotificationChannel;
use App\Services\Notifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Livewire\Component;

/* Settings page section: notification channels and the current batch */
class NotificationSettings extends Component
{
    public $formOpen = false, $editingId = null;
    public $name = '', $type = 'mattermost_bot', $enabled = true, $events = ['completed', 'failed'];
    public $url = '', $serverUrl = '', $token = '', $channel = '', $botToken = '', $chatId = '', $topicId = '';
    public $foundChats = [];
    public $notice = null, $noticeIsError = false;

    public function render()
    {
        $batch = NotificationBatch::whereNull('closed_at')->latest('id')->first();
        return view('livewire.notification-settings', [
            'channels' => NotificationChannel::orderBy('name')->get(),
            'batch' => $batch,
            'batchCounts' => $batch ? $batch->counts() : null,
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
        $this->validate($this->rules($existing));

        $settings = $existing ? ($existing->settings ?: []) : [];
        switch ($this->type)
        {
            case 'mattermost_webhook':
            case 'webhook':
                $settings = ['url' => $this->url];
                break;

            case 'mattermost_bot':
                $token = $this->token !== '' ? $this->token : ($settings['token'] ?? '');
                $channelId = $this->resolveMattermostChannel($this->serverUrl, $token, $this->channel);
                if ($channelId === null)
                    return;
                $settings = ['server_url' => rtrim($this->serverUrl, '/'), 'token' => $token,
                             'channel' => $this->channel, 'channel_id' => $channelId];
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

    protected function rules($existing)
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
                $rules['token'] = ($keepsSecret ? 'nullable' : 'required').'|string|max:200';
                $rules['channel'] = 'required|string|max:300';
                break;
            case 'telegram':
                $rules['botToken'] = [$keepsSecret ? 'nullable' : 'required', 'regex:/^\d+:[A-Za-z0-9_-]+$/'];
                $rules['chatId'] = ['required', 'regex:/^(-?\d+|@\w{4,})$/'];
                $rules['topicId'] = 'nullable|integer|min:1';
                break;
        }
        return $rules;
    }

    /* Channel id from an id, a "team/channel" pair or a channel link; null after reporting an error */
    protected function resolveMattermostChannel($server, $token, $channel)
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
            $response = Http::timeout(10)->withToken($token)->acceptJson()
                ->get(rtrim($server, '/').'/api/v4/teams/name/'.rawurlencode($team).'/channels/name/'.rawurlencode($name));
        }
        catch (\Throwable $e)
        {
            $this->addError('channel', 'Mattermost did not answer: '.$e->getMessage());
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
            $this->addError('botToken', 'Telegram did not answer: '.$e->getMessage());
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
            $channel->update(['last_error' => now()->local()->format('Y-m-d H:i').' '.$e->getMessage()]);
            $this->flash('Sending to '.$channel->name.' failed: '.$e->getMessage(), true);
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
        $this->type = 'mattermost_bot';
        $this->enabled = true;
        $this->events = ['completed', 'failed'];
        $this->foundChats = [];
    }
}
