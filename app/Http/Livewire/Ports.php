<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\PoeOutage;
use App\Services\OuiVendors;
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
 * The stand by its switches' ports (docs/operations.md#switches-and-poe-restart): each switch's front panel with
 * its line and the module in each port, one summary of the stand, the status filter (Alpine, in the browser)
 * and one port's details.
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

        $now = Carbon::now()->getTimestamp();
        $switches = [];
        foreach ($stand['switches'] as $id => $state)
            $switches[] = self::block($id, $state, $stand['map']['switches'][$id] ?? null, $now,
                $state['same_as'] ? $stand['switches'][$state['same_as']] : null);
        usort($switches, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']) ?: $a['id'] - $b['id']; });
        $tile = $stand['map']['tiles'][$this->selected] ?? null;
        $writable = (bool) array_filter($stand['switches'], function ($state) { return PoeControl::writable($state['config']); });
        return view('livewire.ports', [
            'configured' => true,
            'switches' => $switches,
            // two switches with one name give boards nobody can tell apart (one switch under two addresses says so itself)
            'duplicates' => array_keys(array_filter(array_count_values(array_column(array_filter($switches, function ($s) { return !$s['same']; }), 'name')),
                function ($n) { return $n > 1; })),
            'asOf' => self::asOf($stand['switches']),
            'map' => $stand['map'],
            'tile' => $tile,
            // the utility OS of the stand runs on the device (it says so over LLDP): it booted and has not reached the server
            'utilityOs' => $tile ? StandMap::utilityOs($tile) : null,
            // who made the device behind a port that says nothing: only for the ports whose device is not a module
            'vendors' => $tile && in_array($tile['state'], ['foreign', 'boot', 'quiet'], true) && $tile['macs']
                ? array_filter(OuiVendors::names(array_slice($tile['macs'], 0, 8))) : [],
            'usbReport' => $tile && $tile['macs'] ? \App\Models\UsbDiagnosticReport::whereIn('mac', $tile['macs'])
                ->where('received_at', '>', now()->subMinutes(15))->orderByDesc('received_at')->first() : null,
            'now' => $now,
            'writable' => $writable,
            'failed' => $writable && $stand['map'] ? count(self::failedOnStand($stand)) : 0,
            'restart' => $tile ? ['blocker' => PoeRestart::switchBlocker($stand['switches'][$tile['switch_id']] ?? null) ?? StandMap::restartBlocker($tile),
                                  'confirm' => PoeRestart::confirm($tile['modules'][0] ?? null, $tile['label'])] : null,
        ]);
    }

    /* One switch's block: its line, what it says while silent, a placeholder before its first answer or instead of the
       ports of a switch that is in the list twice ($same: the state of the row that is drawn) */
    protected static function block(int $id, array $state, ?array $part, int $now, ?array $same = null): array
    {
        $snapshot = $state['snapshot'];
        $error = $state['error'];
        $note = $placeholder = null;
        if ($same)
            $placeholder = 'Same switch as '.$same['switch']->hostname().' ('.$same['switch']->host
                .'): delete one of them under Settings → Ethernet switches.';
        elseif ($snapshot && $error)
            $note = 'As of '.self::clock($snapshot['taken_at']).', not answering since '.self::clock($error['since']).': '.$error['message'];
        elseif (!$snapshot)
            // another page may hold the poll: its snapshot comes with the next refresh
            $placeholder = $error ? 'Cannot read the switch: '.$error['message'] : 'Asking the switch…';
        return ['id' => $id, 'name' => $state['switch']->hostname(), 'line' => self::header($state, $now), 'note' => $note,
                'placeholder' => $placeholder, 'part' => $part, 'same' => (bool) $same,
                'noMacTable' => $snapshot && $snapshot['mac_method'] === null && in_array(true, array_column($snapshot['ports'], 'up'), true)];
    }

    /* The line inside a switch's block: its name first, then its serial, base MAC and address, its model and revision,
       when it was polled and what its ports draw. A snapshot kept by 2.3.0 has no identity yet */
    protected static function header(array $state, int $now): string
    {
        $snapshot = $state['snapshot'];
        $who = $snapshot['identity'] ?? [];
        $name = $state['switch']->hostname();
        $host = $state['config']['host'];
        $model = $snapshot['model'] ?? null;
        // without a sysDescr the model is the switch's own name
        $model = $model !== null && $model !== $name && $model !== ($who['name'] ?? null) ? trim($model.' '.($who['revision'] ?? '')) : null;
        $watts = !empty($snapshot['poe']) ? array_sum(array_map(function ($p) { return $p['poe']['watts'] ?? 0; }, $snapshot['ports'])) : null;
        return implode(' · ', array_filter([
            $name,
            isset($who['serial']) ? 'S/N '.$who['serial'] : null,
            isset($who['mac']) ? 'MAC '.$who['mac'] : null,
            $host !== $name ? $host : null,
            $model,
            $snapshot ? 'polled '.max(0, $now - $snapshot['taken_at']).' s ago' : null,
            $watts !== null ? 'PoE '.number_format($watts, 1).' W' : null,
            $snapshot ? self::discovery($snapshot) : null,
        ]));
    }

    /* Whether the switch tells what is plugged into it (LLDP, CDP): how many neighbours it knows, or that it says nothing */
    protected static function discovery(array $snapshot): string
    {
        $count = 0;
        foreach ($snapshot['ports'] as $port)
            $count += count($port['neighbors'] ?? []);
        $on = !empty($snapshot['discovery']['lldp']) || !empty($snapshot['discovery']['cdp']);
        if ($count > 0)
            return $count.' '.($count === 1 ? 'neighbor' : 'neighbors').' via LLDP/CDP';
        return $on ? 'LLDP/CDP on, no neighbors' : 'no LLDP/CDP data';
    }

    /* The verdict's start while switches do not answer: what the stand looked like and who is silent */
    protected static function asOf(array $states): string
    {
        $silent = [];
        $oldest = null;
        foreach ($states as $state)
            if ($state['error'])
            {
                $silent[] = $state['switch']->hostname();
                if ($state['snapshot'])
                    $oldest = min($oldest ?? PHP_INT_MAX, $state['snapshot']['taken_at']);
            }
        if (!$silent)
            return '';
        return ($oldest !== null ? 'As of '.self::clock($oldest).', ' : '').implode(', ', $silent).' '.(count($silent) === 1 ? 'is' : 'are').' not answering: ';
    }

    /* A moment as the page shows it: the time today, the day and time before */
    protected static function clock(int $at): string
    {
        $time = Carbon::createFromTimestamp($at)->local();
        return $time->format($time->isToday() ? 'H:i:s' : 'j M H:i');
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
