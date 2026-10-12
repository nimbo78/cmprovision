<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\PoeOutage;
use App\Services\Switch\Stand;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The stand by the switch's ports (docs/superpowers/specs/2026-10-11-ports-page-design.md): the front
 * panel with the module in each port, the status filter (Alpine, in the browser) and one port's details.
 */
#[Title('Ports')]
class Ports extends Component
{
    /** key of the port whose details are open, e.g. "p10003"; only select() sets it */
    #[Locked]
    public $selected = null;

    public function select($key)
    {
        $this->selected = is_string($key) && preg_match('/^p\d+$/', $key) ? $key : null;
    }

    public function identify($id)
    {
        $cm = Cm::find($id);
        if ($cm && $cm->canIdentify())
            $cm->identify();
    }

    public function stopIdentify($id)
    {
        $cm = Cm::find($id);
        if ($cm)
            $cm->stopIdentify();
    }

    public function render()
    {
        $stand = Stand::current();
        if (!$stand)
            return view('livewire.ports', ['configured' => false]);
        // a port left off that the switch shows with PoE on again was switched on at the switch
        PoeOutage::closeSeenOn($stand['snapshot'], $stand['config']['host']);

        return view('livewire.ports', [
            'configured' => true,
            'host' => $stand['config']['host'],
            'snapshot' => $stand['snapshot'],
            'error' => $stand['error'],
            'map' => $stand['map'],
            'tile' => $stand['map']['tiles'][$this->selected] ?? null,
            'now' => Carbon::now()->getTimestamp(),
        ]);
    }
}
