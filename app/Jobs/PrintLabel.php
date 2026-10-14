<?php

namespace App\Jobs;

use App\Models\Cm;
use App\Services\LabelPrinter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/* A module's preinstall label once its board is known (ResolveBoards::settle()); what the printer says goes to the
   module's log (LabelPrinter) */
class PrintLabel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $cmId;
    public $timeout = 80;   // under the database queue's retry_after (90 s): never printed twice

    public function __construct(int $cmId)
    {
        $this->cmId = $cmId;
        $this->onQueue('boards');
    }

    public function handle(): void
    {
        $cm = Cm::find($this->cmId);
        if ($cm)
            LabelPrinter::print($cm);
    }
}
