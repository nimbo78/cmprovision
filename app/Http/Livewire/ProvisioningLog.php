<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\Cmlog;
use Livewire\Component;

/* Dashboard: the latest provisioning log entries, refreshed like the progress panel */
class ProvisioningLog extends Component
{
    const ROWS = 100;

    public function render()
    {
        $log = Cmlog::orderBy('id', 'desc')->limit(self::ROWS)->get();

        return view('livewire.provisioning-log', [
            'log' => $log,
            // a module is named by its MAC; the serial of one that is not there any more is all the row has
            'macs' => Cm::whereIn('serial', $log->pluck('cm')->filter()->unique()->values())->pluck('mac', 'serial'),
            'today' => now()->local()->toDateString(),
            'zone' => config('app.display_timezone'),
        ]);
    }
}
