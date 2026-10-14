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
    /** key of the port whose details are open, e.g. "s1p10003"; only select() sets it */
    #[Locked]
    public $selected = null;

    public $restartMessage = null, $restartError = false;

    public function select($key)
    {
        $this->selected = is_string($key) && preg_match('/^s\d+p\d+$/', $key) ? $key : null;
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
        $cms = $stand && $stand['map'] ? self::failedOnStand($stand) : [];
        $this->report(PoeRestart::modules($cms, auth()->user()->name ?? null));
    }

    public function render()
    {
        $stand = Stand::current();
        if (!$stand)
            return view('livewire.ports', ['configured' => false]);
        // a port left off that its switch shows switched on again was switched on at the switch
        foreach ($stand['switches'] as $state)
            if (Stand::answering($state))
                PoeOutage::closeSeenOn(app(SnmpClient::class, ['config' => $state['config']]), $state['switch']);

        $first = reset($stand['switches']);
        $tile = $stand['map']['tiles'][$this->selected] ?? null;
        $writable = (bool) array_filter($stand['switches'], function ($state) { return PoeControl::writable($state['config']); });
        return view('livewire.ports', [
            'configured' => true,
            'header' => self::header($first['snapshot'], $first['config']['host']),
            'snapshot' => $first['snapshot'],
            'error' => $first['error'],
            'map' => $stand['map'],
            'tile' => $tile,
            'now' => Carbon::now()->getTimestamp(),
            'writable' => $writable,
            'failed' => $writable && $stand['map'] ? count(self::failedOnStand($stand)) : 0,
            'restart' => $tile ? ['blocker' => PoeRestart::switchBlocker($stand['switches'][$tile['switch_id']] ?? null) ?? StandMap::restartBlocker($tile),
                                  'confirm' => PoeRestart::confirm($tile['modules'][0] ?? null, $tile['label'])] : null,
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

    /* Failed and silent modules on ports that may be restarted, on switches that may write and answer */
    protected static function failedOnStand(array $stand): array
    {
        $cms = [];
        foreach ($stand['map']['tiles'] as $tile)
            if (PoeRestart::switchBlocker($stand['switches'][$tile['switch_id']] ?? null) === null && StandMap::restartBlocker($tile) === null)
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
