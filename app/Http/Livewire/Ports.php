<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Services\NetworkStatus;
use App\Services\Switch\SnapshotCache;
use App\Services\Switch\SwitchSnapshot;
use App\Services\SwitchPortFinder;
use App\Support\StandMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * The stand by the switch's ports (docs/superpowers/specs/2026-10-11-ports-page-design.md): the front
 * panel with the module in each port, the status filter (Alpine, in the browser) and one port's details.
 */
class Ports extends Component
{
    /* Modules are looked for on ports with at most this many MAC addresses: a desktop switch full of
       modules, not the office network behind an uplink */
    const LOOKUP_MACS = 48;

    /** key of the port whose details are open, e.g. "p10003" */
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
        $config = SwitchPortFinder::config();
        if (!$config['host'])
            return view('livewire.ports', ['configured' => false]);

        $state = (new SnapshotCache($config['host'], function () use ($config) {
            return SwitchSnapshot::forConfig($config)->take();
        }))->current();

        $map = null;
        if ($state['snapshot'])
            $map = (new StandMap($state['snapshot'], $this->modulesOn($state['snapshot']), app(NetworkStatus::class)->serverMac()))->build();

        return view('livewire.ports', [
            'configured' => true,
            'host' => $config['host'],
            'snapshot' => $state['snapshot'],
            'error' => $state['error'],
            'map' => $map,
            'tile' => $map['tiles'][$this->selected] ?? null,
            'now' => Carbon::now()->getTimestamp(),
        ]);
    }

    /* Modules behind ports with a link and few enough addresses to be devices, not uplinks */
    protected function modulesOn(array $snapshot)
    {
        $macs = [];
        foreach ($snapshot['ports'] as $port)
            if ($port['up'] && count($port['macs']) <= self::LOOKUP_MACS)
                foreach ($port['macs'] as $mac)
                    $macs[strtolower($mac)] = true;
        $modules = [];
        foreach (array_chunk(array_keys($macs), 500) as $chunk)
            foreach (Cm::whereIn(DB::raw('lower(mac)'), $chunk)->get() as $cm)
                $modules[] = $cm;
        return $modules;
    }
}
