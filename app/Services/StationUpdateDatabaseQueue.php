<?php

namespace App\Services;

use Illuminate\Queue\DatabaseQueue;

/** Hold admission only across the database reservation transaction, never across idle sleep. */
class StationUpdateDatabaseQueue extends DatabaseQueue
{
    public function pop($queue = null)
    {
        $gate = app(StationUpdateQueueGate::class);
        if (!$gate->reserve()) return null;
        try { return parent::pop($queue); }
        finally { $gate->release(); }
    }
}
