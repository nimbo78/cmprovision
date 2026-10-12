<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\PoeOutage;
use App\Services\Switch\PoeControl;
use App\Services\Switch\PoeRestart;
use App\Services\Switch\Stand;
use App\Support\StandMap;
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

    public $restartMessage = null, $restartError = false;

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

    /* Switch the port's PoE off and on (PoeRestart); the port is checked again on a fresh snapshot */
    public function restart($key)
    {
        $this->report(PoeRestart::ports([(string) $key], auth()->user()->name ?? null));
    }

    /* Every failed or silent module on a port that may be restarted */
    public function restartFailed()
    {
        $stand = Stand::current();
        $cms = $stand && $stand['map'] ? self::failedOnStand($stand['map']) : [];
        $this->report(PoeRestart::modules($cms, auth()->user()->name ?? null));
    }

    public function render()
    {
        $stand = Stand::current();
        if (!$stand)
            return view('livewire.ports', ['configured' => false]);
        // a port left off that the switch shows with PoE on again was switched on at the switch
        PoeOutage::closeSeenOn($stand['snapshot'], $stand['config']['host']);

        $tile = $stand['map']['tiles'][$this->selected] ?? null;
        $writable = PoeControl::writable($stand['config']);
        return view('livewire.ports', [
            'configured' => true,
            'host' => $stand['config']['host'],
            'snapshot' => $stand['snapshot'],
            'error' => $stand['error'],
            'map' => $stand['map'],
            'tile' => $tile,
            'now' => Carbon::now()->getTimestamp(),
            'writable' => $writable,
            'failed' => $writable && $stand['map'] ? count(self::failedOnStand($stand['map'])) : 0,
            'restart' => $tile ? ['blocker' => StandMap::restartBlocker($tile),
                                  'confirm' => PoeRestart::confirm($tile['modules'][0] ?? null, $tile['name'])] : null,
        ]);
    }

    /* Failed and silent modules on ports that may be restarted */
    protected static function failedOnStand(array $map): array
    {
        $cms = [];
        foreach ($map['tiles'] as $tile)
            if (StandMap::restartBlocker($tile) === null)
                foreach ($tile['modules'] as $cm)
                    if (in_array(StandMap::moduleState($cm), ['fail', 'stale'], true))
                        $cms[] = $cm;
        return $cms;
    }

    protected function report(array $result): void
    {
        ['text' => $this->restartMessage, 'error' => $this->restartError] = PoeRestart::message($result);
    }
}
