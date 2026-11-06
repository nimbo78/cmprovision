<?php

namespace App\Jobs;

use App\Models\StationSetupRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ExpireStationSetup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public $timeout = 20;
    public $tries = 3;
    public function __construct(public int $runId) { $this->onQueue('boards'); }
    public function handle(): void
    {
        // A newer setup can reuse the row; never expire it from an earlier delayed job.
        StationSetupRun::whereKey($this->runId)->where('expires_at', '<=', now())->delete();
    }
}
