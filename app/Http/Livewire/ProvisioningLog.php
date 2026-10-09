<?php

namespace App\Http\Livewire;

use App\Models\Cmlog;
use Livewire\Component;

/* Dashboard: the latest provisioning log entries, refreshed like the progress panel */
class ProvisioningLog extends Component
{
    const ROWS = 100;

    public function render()
    {
        return view('livewire.provisioning-log', [
            'log' => Cmlog::orderBy('id', 'desc')->limit(self::ROWS)->get(),
            'today' => now()->local()->toDateString(),
            'zone' => config('app.display_timezone'),
        ]);
    }
}
