<?php

namespace App\Listeners;

use App\Events\CmProvisioningComplete;
use App\Events\CmProvisioningFailed;
use App\Events\CmProvisioningStarted;
use App\Models\Cm;
use App\Services\Notifier;

/* Records each provisioning event for the notification channels (notification_outbox); FlushNotifications sends
   them in packs from the queue, so a slow chat server never holds up provisioning. Found by event discovery
   (EventServiceProvider::shouldDiscoverEvents). */
class SendProvisioningNotifications
{
    public function handleStarted(CmProvisioningStarted $event)
    {
        $this->record($event->cm, 'started');
    }

    public function handleCompleted(CmProvisioningComplete $event)
    {
        $this->record($event->cm, 'completed');
    }

    public function handleFailed(CmProvisioningFailed $event)
    {
        $this->record($event->cm, 'failed');
    }

    /* Runs in the module's request: an error here must never reach it, or a module that flashed fine would be marked
       failed */
    protected function record(Cm $cm, $what)
    {
        try
        {
            app(Notifier::class)->notify($cm, $what);
        }
        catch (\Throwable $e)
        {
            report($e);
        }
    }
}
