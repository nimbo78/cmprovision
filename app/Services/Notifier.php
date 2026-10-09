<?php

namespace App\Services;

use App\Models\Cm;
use App\Models\NotificationBatch;
use App\Models\NotificationChannel;
use App\Models\NotificationThread;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;

/**
 * Delivers provisioning events to the notification channels.
 *  - Bot channels (Mattermost bot, Telegram) keep a thread per batch: a summary message that is
 *    edited on every event, with a reply per module for the selected events.
 *  - Webhook channels get one message per selected event.
 * Errors are recorded on the channel and never reach the caller.
 */
class Notifier
{
    const TIMEOUT = 10;
    const ICONS = ['started' => '▶️', 'completed' => '✅', 'failed' => '❌', 'test' => '🔔'];

    public function notify(Cm $cm, $event)
    {
        $batch = $cm->notification_batch_id ? NotificationBatch::find($cm->notification_batch_id) : null;

        foreach (NotificationChannel::where('enabled', true)->orderBy('id')->get() as $channel)
        {
            try
            {
                if ($this->deliver($channel, $cm, $event, $batch))
                    $channel->update(['last_sent_at' => now(), 'last_error' => null]);
            }
            catch (\Throwable $e)
            {
                $channel->update(['last_error' => now()->local()->format('Y-m-d H:i').' '.$e->getMessage()]);
            }
        }
    }

    /* A standalone test message; throws on failure so the settings page can show the reason */
    public function test(NotificationChannel $channel)
    {
        $text = self::ICONS['test'].' Test message from cmprovision on '.gethostname();
        if ($channel->type === 'webhook')
            $this->sendWebhook($channel, ['event' => 'test', 'text' => $text]);
        else
            $this->send($channel, $text);
        $channel->update(['last_sent_at' => now(), 'last_error' => null]);
    }

    /* @return bool whether anything was sent */
    protected function deliver(NotificationChannel $channel, Cm $cm, $event, $batch)
    {
        $line = $this->moduleLine($cm, $event);

        if (!$channel->isThreaded())
        {
            if (!$channel->wants($event))
                return false;
            if ($channel->type === 'webhook')
                $this->sendWebhook($channel, $this->payload($cm, $event, $batch, $line));
            else
                $this->send($channel, $this->projectPrefix($cm).$line);
            return true;
        }

        if (!$batch)
        {
            if (!$channel->wants($event))
                return false;
            $this->send($channel, $this->projectPrefix($cm).$line);
            return true;
        }

        $created = false;
        $root = $this->thread($channel, $batch, $created);
        if ($channel->wants($event))
            $this->send($channel, $line, $root);
        if (!$created)
            $this->edit($channel, $root, $this->summary($batch));
        return true;
    }

    /* Id of the batch summary in this channel, posting it first if needed. The unique key on
       notification_threads makes sure only one of several simultaneous requests posts it. */
    protected function thread(NotificationChannel $channel, NotificationBatch $batch, &$created)
    {
        $key = ['batch_id' => $batch->id, 'channel_id' => $channel->id];
        $thread = NotificationThread::where($key)->first();
        if (!$thread)
        {
            try
            {
                $thread = NotificationThread::create($key + ['external_id' => '']);
                try
                {
                    $thread->update(['external_id' => (string) $this->send($channel, $this->summary($batch))]);
                    $created = true;
                    return $thread->external_id;
                }
                catch (\Throwable $e)
                {
                    $thread->delete();   // let the next event try again
                    throw $e;
                }
            }
            catch (QueryException $e)
            {
                // another request is posting the summary right now
            }
        }

        for ($i = 0; $i < 40; $i++)
        {
            $thread = NotificationThread::where($key)->first();
            if ($thread && $thread->external_id !== '')
                return $thread->external_id;
            usleep(250000);
        }
        throw new \RuntimeException('Batch summary was not posted in time');
    }

    /* Post a message (as a reply when $root is given); returns the new message id */
    protected function send(NotificationChannel $channel, $text, $root = null)
    {
        switch ($channel->type)
        {
            case 'mattermost_bot':
                $body = ['channel_id' => $channel->setting('channel_id'), 'message' => $text];
                if ($root)
                    $body['root_id'] = $root;
                return $this->mattermost($channel)->post($this->mattermostUrl($channel, '/posts'), $body)->throw()->json('id');

            case 'telegram':
                $body = ['chat_id' => $channel->setting('chat_id'), 'text' => $text, 'disable_web_page_preview' => true];
                if ($channel->setting('topic_id'))
                    $body['message_thread_id'] = (int) $channel->setting('topic_id');
                if ($root)
                    $body['reply_parameters'] = ['message_id' => (int) $root, 'allow_sending_without_reply' => true];
                return $this->telegram($channel, 'sendMessage', $body)['message_id'] ?? null;

            case 'mattermost_webhook':
                $this->http()->post($channel->setting('url'), ['text' => $text])->throw();
                return null;
        }
        throw new \InvalidArgumentException("Unknown channel type {$channel->type}");
    }

    protected function edit(NotificationChannel $channel, $id, $text)
    {
        if ($channel->type === 'mattermost_bot')
        {
            $this->mattermost($channel)->put($this->mattermostUrl($channel, "/posts/$id/patch"), ['message' => $text])->throw();
        }
        else if ($channel->type === 'telegram')
        {
            try
            {
                $this->telegram($channel, 'editMessageText', ['chat_id' => $channel->setting('chat_id'), 'message_id' => (int) $id, 'text' => $text]);
            }
            catch (\RuntimeException $e)
            {
                if (strpos($e->getMessage(), 'message is not modified') === false)
                    throw $e;
            }
        }
    }

    protected function sendWebhook(NotificationChannel $channel, array $payload)
    {
        $this->http()->post($channel->setting('url'), $payload)->throw();
    }

    protected function http()
    {
        return Http::timeout(self::TIMEOUT)->withOptions(['connect_timeout' => 5])->acceptJson();
    }

    protected function mattermost(NotificationChannel $channel)
    {
        return $this->http()->withToken($channel->setting('token'));
    }

    protected function mattermostUrl(NotificationChannel $channel, $path)
    {
        return rtrim($channel->setting('server_url'), '/').'/api/v4'.$path;
    }

    /* Telegram Bot API call; waits once when the chat is rate limited (HTTP 429, retry_after) */
    protected function telegram(NotificationChannel $channel, $method, array $body)
    {
        $url = 'https://api.telegram.org/bot'.$channel->setting('bot_token').'/'.$method;
        for ($attempt = 1; ; $attempt++)
        {
            $response = $this->http()->post($url, $body);
            $retryAfter = (int) $response->json('parameters.retry_after');
            if ($response->status() == 429 && $attempt == 1 && $retryAfter > 0 && $retryAfter <= 10)
            {
                sleep($retryAfter);
                continue;
            }
            if (!$response->json('ok'))
                throw new \RuntimeException('Telegram '.$method.': HTTP '.$response->status().' '.($response->json('description') ?: $response->body()));
            return $response->json('result');
        }
    }

    public function summary(NotificationBatch $batch)
    {
        $counts = $batch->counts();
        $project = $batch->project;
        $lines = ['📦 Batch #'.$batch->id.' · project '.($project ? $project->name : '(deleted)')
                  .' · started '.$batch->started_at->local()->format('d.m H:i')];
        if ($project && $project->image)
            $lines[] = 'Image: '.$project->image->filename;
        if ($project && $project->eeprom_firmware)
            $lines[] = 'EEPROM: '.$project->eeprom_firmware;
        $lines[] = '✅ '.$counts['done'].' done · ❌ '.$counts['failed'].' failed · ⏳ '.$counts['active'].' in progress';
        return implode("\n", $lines);
    }

    public function moduleLine(Cm $cm, $event)
    {
        $parts = [$cm->serial, $cm->mac];
        if ($cm->provisioning_board)
            $parts[] = 'board '.$cm->provisioning_board;
        if ($event === 'completed' && $cm->provisioning_started_at && $cm->provisioning_complete_at)
            $parts[] = self::duration($cm->provisioning_complete_at->getTimestamp() - $cm->provisioning_started_at->getTimestamp());
        if ($event === 'failed' && $cm->phase_detail)
            $parts[] = $cm->phase_detail;
        if ($event === 'started')
            $parts[] = 'started';
        return (self::ICONS[$event] ?? '').' '.implode(' · ', $parts);
    }

    protected function projectPrefix(Cm $cm)
    {
        return $cm->project ? '['.$cm->project->name.'] ' : '';
    }

    protected function payload(Cm $cm, $event, $batch, $line)
    {
        $payload = [
            'event' => $event,
            'text' => $this->projectPrefix($cm).$line,
            'serial' => $cm->serial,
            'mac' => $cm->mac,
            'board' => $cm->provisioning_board,
            'project' => $cm->project ? $cm->project->name : null,
            'image' => $cm->image_filename,
            'eeprom_firmware' => $cm->project ? $cm->project->eeprom_firmware : null,
            'phase' => $cm->phase,
            'detail' => $cm->phase_detail,
            'started_at' => $cm->provisioning_started_at ? $cm->provisioning_started_at->toIso8601ZuluString() : null,
            'completed_at' => $cm->provisioning_complete_at ? $cm->provisioning_complete_at->toIso8601ZuluString() : null,
            'duration_seconds' => ($cm->provisioning_started_at && $cm->provisioning_complete_at)
                ? $cm->provisioning_complete_at->getTimestamp() - $cm->provisioning_started_at->getTimestamp() : null,
        ];
        if ($batch)
            $payload['batch'] = ['id' => $batch->id] + $batch->counts();
        return $payload;
    }

    public static function duration($seconds)
    {
        $seconds = max(0, (int) $seconds);
        return $seconds >= 3600 ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
                                : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
