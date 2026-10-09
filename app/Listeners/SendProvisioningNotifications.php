<?php

namespace App\Listeners;

use App\Events\CmProvisioningComplete;
use App\Events\CmProvisioningFailed;
use App\Events\CmProvisioningStarted;
use App\Models\Cm;
use App\Services\Notifier;

/* Sends notifications after the response has gone to the module, so a slow chat server never holds
   up provisioning. Found by event discovery (EventServiceProvider::shouldDiscoverEvents). */
class SendProvisioningNotifications
{
    public function handleStarted(CmProvisioningStarted $event)
    {
        $this->afterResponse($event->cm, 'started');
    }

    public function handleCompleted(CmProvisioningComplete $event)
    {
        $this->afterResponse($event->cm, 'completed');
    }

    public function handleFailed(CmProvisioningFailed $event)
    {
        $this->afterResponse($event->cm, 'failed');
    }

    protected function afterResponse(Cm $cm, $what)
    {
        $id = $cm->id;
        $sent = false;
        // Laravel 8 keeps terminating callbacks after running them, so guard against a second run
        app()->terminating(function () use ($id, $what, &$sent) {
            if ($sent)
                return;
            $sent = true;
            $cm = Cm::find($id);
            if ($cm)
                (new Notifier)->notify($cm, $what);
        });
    }
}
