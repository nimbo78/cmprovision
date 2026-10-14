<?php

namespace App\Listeners;

use App\Events\CmProvisioningComplete;
use App\Events\CmProvisioningFailed;
use App\Events\CmProvisioningStarted;
use App\Services\Notifier;

/* Records each provisioning event for the notification channels (notification_outbox); FlushNotifications sends
   them in packs from the queue, so a slow chat server never holds up provisioning. Found by event discovery
   (EventServiceProvider::shouldDiscoverEvents). */
class SendProvisioningNotifications
{
    public function handleStarted(CmProvisioningStarted $event)
    {
        app(Notifier::class)->notify($event->cm, 'started');
    }

    public function handleCompleted(CmProvisioningComplete $event)
    {
        app(Notifier::class)->notify($event->cm, 'completed');
    }

    public function handleFailed(CmProvisioningFailed $event)
    {
        app(Notifier::class)->notify($event->cm, 'failed');
    }
}
