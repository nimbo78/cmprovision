<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\PoeOutage;
use App\Services\Snmp\SnmpClient;
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
        // a port left off that the switch shows switched on again was switched on at the switch
        if ($stand['snapshot'] && !$stand['error'])
            PoeOutage::closeSeenOn(app(SnmpClient::class, ['config' => $stand['config']]), $stand['config']['host']);

        $tile = $stand['map']['tiles'][$this->selected] ?? null;
        $writable = PoeControl::writable($stand['config']);
        return view('livewire.ports', [
            'configured' => true,
            'header' => self::header($stand['snapshot'], $stand['config']['host']),
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

    /* The line above the stand: the model and its revision in bold, then the switch's name (unless the model already
       is its name), serial, base MAC and address. A snapshot kept by 2.3.0 has no identity yet */
    protected static function header(?array $snapshot, string $host): array
    {
        $who = $snapshot['identity'] ?? [];
        $model = $snapshot['model'] ?? null;
        $name = $who['name'] ?? null;
        return [
            'title' => $model !== null ? trim($model.' '.($who['revision'] ?? '')) : null,
            'facts' => implode(' · ', array_filter([
                $name !== $model ? $name : null,
                isset($who['serial']) ? 'S/N '.$who['serial'] : null,
                isset($who['mac']) ? 'MAC '.$who['mac'] : null,
                $host,
            ])),
        ];
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
        $this->dispatch('poe-outages-changed');   // a port left off shows in the banner at once
    }
}
