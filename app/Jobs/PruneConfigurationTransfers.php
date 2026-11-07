<?php

namespace App\Jobs;

use App\Services\Configuration\TransferWorkspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class PruneConfigurationTransfers implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct() { $this->onQueue('images'); }
    public function handle(TransferWorkspace $workspace): void { $workspace->prune(); }
}
