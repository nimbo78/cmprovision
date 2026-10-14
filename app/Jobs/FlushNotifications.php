<?php

namespace App\Jobs;

use App\Models\NotificationChannel;
use App\Models\NotificationOutbox;
use App\Services\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;

/* Sends what notification_outbox holds (Notifier::flush): a chat gets one pack per WINDOW seconds, a JSON webhook a
   request per event. Unique until a worker takes it: the events of a window join the run that waits for it */
class FlushNotifications implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable;

    const WINDOW = 10;

    public $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    /* After an event: the events of the next WINDOW seconds go out with it */
    public static function soon(): void
    {
        self::dispatch()->delay(now()->addSeconds(self::WINDOW));
    }

    public function handle(Notifier $notifier): void
    {
        $wait = null;
        $ids = NotificationOutbox::whereNull('sent_at')->distinct()->pluck('channel_id');
        foreach (NotificationChannel::whereIn('id', $ids)->orderBy('id')->get() as $channel)
        {
            $after = $notifier->flush($channel);
            if ($after !== null)
                $wait = $wait === null ? $after : min($wait, $after);
        }
        // a chat asked to wait or a delivery failed: the next run then, one waiting at a time; the sync queue of the
        // tests runs a dispatch at once, so no next run there
        if ($wait !== null && !$this->job instanceof SyncJob)
            self::dispatch()->delay(now()->addSeconds($wait));
    }
}
