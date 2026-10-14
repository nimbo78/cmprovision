<?php

namespace App\Services;

use App\Jobs\FlushNotifications;
use App\Models\Cm;
use App\Models\NotificationBatch;
use App\Models\NotificationChannel;
use App\Models\NotificationOutbox;
use App\Models\NotificationThread;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Delivers provisioning events to the notification channels. An event becomes a row in notification_outbox for each
 * channel that is on (notify()); FlushNotifications sends them from the queue in packs (flush()):
 *  - bot channels (Mattermost bot, Telegram) keep a thread per batch: a summary message, edited with the numbers of
 *    now after each pack, and a reply per pack with the events the channel selected;
 *  - a Mattermost webhook gets a message per pack, a JSON webhook a request per event.
 * Errors are recorded on the channel and never reach the module.
 */
class Notifier
{
    const TIMEOUT = 10;
    const ICONS = ['started' => '▶️', 'completed' => '✅', 'failed' => '❌', 'test' => '🔔'];
    const ATTEMPTS = 5;
    const RETRY_SECONDS = 30;           // after a failed delivery
    const GAP_MICROSECONDS = 1000000;   // between two calls to one chat
    const SPLIT = 4000;                 // characters in one message (Telegram takes 4096)
    const BLOCKED = 'notify-blocked:';  // cache: until when a chat asked to be left alone
    const LAST_CALL = 'notify-last-call:';

    protected $editError = null;   // a summary that could not be edited while the pack went out

    /* A provisioning event: a row for each channel that is on now; FlushNotifications sends them in packs */
    public function notify(Cm $cm, $event)
    {
        $channels = NotificationChannel::where('enabled', true)->pluck('id');
        foreach ($channels as $id)
            NotificationOutbox::create(['channel_id' => $id, 'batch_id' => $cm->notification_batch_id, 'cm_id' => $cm->id, 'event' => $event]);
        if ($channels->isNotEmpty())
            FlushNotifications::soon();
    }

    /** Send what waits for this channel; null when nothing is left to send, else the seconds until the next try (the
        wait a chat asked for, or RETRY_SECONDS after a failed delivery) */
    public function flush(NotificationChannel $channel)
    {
        $rows = NotificationOutbox::with(['cm.project', 'batch'])->where('channel_id', $channel->id)->whereNull('sent_at')
            ->orderBy('id')->get();
        if ($rows->isEmpty())
            return null;
        if (!$channel->enabled)
        {
            NotificationOutbox::whereKey($rows->modelKeys())->update(['sent_at' => now(), 'error' => 'channel turned off']);
            return null;
        }
        $blocked = (int) Cache::get(self::BLOCKED.$channel->id, 0) - now()->getTimestamp();
        if ($blocked > 0)
            return $blocked;

        $this->editError = null;
        try
        {
            $sent = false;
            if ($channel->type === 'webhook')
                foreach ($rows as $row)
                {
                    if ($row->cm && $channel->wants($row->event))
                    {
                        $this->sendWebhook($channel, $this->payload($row->cm, $row->event, $row->batch, $this->moduleLine($row->cm, $row->event)));
                        $sent = true;
                    }
                    $row->update(['sent_at' => now()]);
                }
            else
                foreach ($rows->groupBy('batch_id') as $group)
                {
                    $sent = $this->deliverPack($channel, $group) || $sent;
                    NotificationOutbox::whereKey($group->modelKeys())->update(['sent_at' => now()]);
                }
            if ($sent)
                $channel->update(['last_sent_at' => now(),
                    'last_error' => $this->editError ? now()->local()->format('Y-m-d H:i').' '.$this->editError : null]);
            return null;
        }
        catch (\Throwable $e)
        {
            $retryAfter = self::retryAfter($e);
            if ($retryAfter !== null)
            {
                Cache::put(self::BLOCKED.$channel->id, now()->getTimestamp() + $retryAfter, $retryAfter + 60);
                return $retryAfter;
            }
            $error = self::errorText($e, $channel);
            $channel->update(['last_error' => now()->local()->format('Y-m-d H:i').' '.$error]);
            $left = false;
            foreach (NotificationOutbox::whereKey($rows->modelKeys())->whereNull('sent_at')->get() as $row)
            {
                $attempts = $row->attempts + 1;
                $row->update(['attempts' => $attempts, 'error' => $error, 'sent_at' => $attempts >= self::ATTEMPTS ? now() : null]);
                $left = $left || $attempts < self::ATTEMPTS;
            }
            return $left ? self::RETRY_SECONDS : null;
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

    /* One batch's events in one message: a part per event the channel wants, a module per line. A bot answers in the
       batch's thread, posting its summary first if needed, and edits the summary with the numbers of now, also when no
       part is left. The edit comes first and each message marks its events sent: a rate limit part way through sends
       nothing twice. @return bool whether anything was sent */
    protected function deliverPack(NotificationChannel $channel, $rows)
    {
        $batch = $rows->first()->batch;
        $root = null;
        if ($channel->isThreaded() && $batch)
        {
            $created = false;
            $root = $this->thread($channel, $batch, $created);
            if (!$created)
                try
                {
                    $this->edit($channel, $root, $this->summary($batch));
                }
                catch (\Throwable $e)
                {
                    // a summary someone deleted must not hold the replies back; a rate limit waits for all of them
                    if (self::retryAfter($e) !== null)
                        throw $e;
                    $this->editError = self::errorText($e, $channel);
                }
        }

        $first = $rows->first(fn ($row) => $row->cm);
        $prefix = $root === null && $first ? $this->projectPrefix($first->cm) : '';
        $sent = $root !== null;
        foreach ($this->messages($channel, $rows) as [$text, $ids])
        {
            $this->send($channel, $prefix.$text, $root);
            NotificationOutbox::whereKey($ids)->update(['sent_at' => now()]);
            $sent = true;
        }
        return $sent;
    }

    /* The pack as messages of at most SPLIT characters, each with the outbox ids it carries: a part per event the
       channel wants, a header and then a module per line; a header never ends a message */
    protected function messages(NotificationChannel $channel, $rows)
    {
        $lines = [];   // [text, outbox id or null for a header]
        foreach (['started' => 'started', 'completed' => 'done', 'failed' => 'failed'] as $event => $word)
        {
            $mine = $rows->filter(fn ($row) => $row->event === $event && $row->cm && $channel->wants($event));
            if ($mine->isEmpty())
                continue;
            $lines[] = [self::ICONS[$event].' '.$mine->count().' '.$word.':', null];
            foreach ($mine as $row)
                $lines[] = [$this->moduleText($row->cm, $event), $row->id];
        }

        $messages = [];
        [$text, $ids] = ['', []];
        foreach ($lines as $i => [$line, $id])
        {
            $needed = mb_strlen($line) + ($id === null && isset($lines[$i + 1]) ? 1 + mb_strlen($lines[$i + 1][0]) : 0);
            if ($text !== '' && mb_strlen($text) + 1 + $needed > self::SPLIT)
            {
                $messages[] = [$text, $ids];
                [$text, $ids] = ['', []];
            }
            $text = $text === '' ? $line : $text."\n".$line;
            if ($id !== null)
                $ids[] = $id;
        }
        if ($text !== '')
            $messages[] = [$text, $ids];
        return $messages;
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
        $this->pace($channel);
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
                $this->http($channel)->post($channel->setting('url'), ['text' => $text])->throw();
                return null;
        }
        throw new \InvalidArgumentException("Unknown channel type {$channel->type}");
    }

    protected function edit(NotificationChannel $channel, $id, $text)
    {
        $this->pace($channel);
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
        $this->http($channel)->post($channel->setting('url'), $payload)->throw();
    }

    /* Seconds a chat server asked to wait (HTTP 429), or null for any other failure */
    protected static function retryAfter(\Throwable $e)
    {
        if ($e instanceof RateLimited)
            return $e->retryAfter;
        if ($e instanceof RequestException && $e->response->status() == 429)
            return max(1, ((int) $e->response->header('Retry-After')) ?: 10);
        return null;
    }

    /* At least GAP_MICROSECONDS between two calls to one chat (Telegram: about one message a second in a chat) */
    protected function pace(NotificationChannel $channel)
    {
        $wait = self::GAP_MICROSECONDS - (int) ((microtime(true) - (float) Cache::get(self::LAST_CALL.$channel->id, 0)) * 1000000);
        if ($wait > 0)
            Sleep::usleep($wait);
        Cache::put(self::LAST_CALL.$channel->id, microtime(true), 60);
    }

    /* What a failed delivery says on the settings page: without the Telegram bot token that Guzzle puts in the URL of
       its messages, and with advice about the server's certificate */
    public static function errorText(\Throwable $e, NotificationChannel $channel)
    {
        return self::redact(TlsTrust::explain($e, in_array($channel->type, NotificationChannel::TLS_TYPES, true)));
    }

    public static function redact($text)
    {
        return preg_replace('#/bot\d+:[A-Za-z0-9_-]+#', '/bot…', $text);
    }

    /* A request with the channel's trust in its server's certificate */
    protected function http(NotificationChannel $channel)
    {
        return Http::timeout(self::TIMEOUT)->withOptions(['connect_timeout' => 5] + $channel->tlsOptions())->acceptJson();
    }

    protected function mattermost(NotificationChannel $channel)
    {
        return $this->http($channel)->withToken($channel->setting('token'));
    }

    protected function mattermostUrl(NotificationChannel $channel, $path)
    {
        return rtrim($channel->setting('server_url'), '/').'/api/v4'.$path;
    }

    /* Telegram Bot API call; a rate limit (HTTP 429) comes back as RateLimited, for the queue to wait it out */
    protected function telegram(NotificationChannel $channel, $method, array $body)
    {
        $url = rtrim(config('cmprovision.telegram_api'), '/').'/bot'.$channel->setting('bot_token').'/'.$method;
        $response = $this->http($channel)->post($url, $body);
        $said = $response->json('description') ?: $response->body();
        if ($response->status() == 429)
            throw new RateLimited((int) $response->json('parameters.retry_after') ?: 30, 'Telegram '.$method.': HTTP 429 '.$said);
        if (!$response->json('ok'))
            throw new \RuntimeException('Telegram '.$method.': HTTP '.$response->status().' '.$said);
        return $response->json('result');
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
        return (self::ICONS[$event] ?? '').' '.$this->moduleText($cm, $event);
    }

    /* A module in a message: serial, MAC, board and what the event adds (duration, EEPROM, reason) */
    public function moduleText(Cm $cm, $event)
    {
        $parts = [$cm->serial, $cm->mac];
        if ($cm->provisioning_board)
            $parts[] = 'board '.$cm->provisioning_board;
        if ($event === 'completed' && $cm->provisioning_started_at && $cm->provisioning_complete_at)
            $parts[] = self::duration($cm->provisioning_complete_at->getTimestamp() - $cm->provisioning_started_at->getTimestamp());
        if ($event === 'completed' && ($eeprom = self::eepromChange($cm)))
            $parts[] = $eeprom;
        if ($event === 'failed' && $cm->phase_detail)
            $parts[] = $cm->phase_detail;
        if ($event === 'started')
            $parts[] = 'started';
        return implode(' · ', $parts);
    }

    /* "EEPROM 2021-02-16 → 2026-09-23" when flashrom wrote it, "EEPROM 2026-09-23 (unchanged)" when it
       held the image already, the version alone when the project does not flash it */
    public static function eepromChange(Cm $cm)
    {
        $before = $cm->eepromVersionBefore();
        $after = $cm->eepromVersionAfter();
        if ($cm->eeprom_result === 'written' && $after)
            return 'EEPROM '.($before ?: '?').' → '.$after;
        if ($cm->eeprom_result === 'identical' && $after)
            return 'EEPROM '.$after.' (unchanged)';
        if ($cm->eeprom_config_after === null && $before)
            return 'EEPROM '.$before;
        return null;
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
            // the board's two halves, for a collector that sorts by switch: null without a switch
            'switch' => $cm->switch_name,
            'port' => $cm->switch_port,
            'project' => $cm->project ? $cm->project->name : null,
            'image' => $cm->image_filename,
            'eeprom_firmware' => $cm->project ? $cm->project->eeprom_firmware : null,
            'eeprom' => ['before' => $cm->eepromVersionBefore(), 'after' => $cm->eepromVersionAfter(), 'result' => $cm->eeprom_result],
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
