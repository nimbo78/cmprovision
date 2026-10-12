<?php

namespace App\Http\Livewire;

use App\Models\Cmlog;
use App\Models\PoeOutage;
use App\Services\Switch\PoeControl;
use App\Services\SwitchPortFinder;
use Livewire\Component;

/* Ports a PoE restart left off (PoeOutage::stayedOff()), on the dashboard and the Ports page until they are on again */
class PoeOutages extends Component
{
    public $message = null, $messageIsError = false;

    public function powerOn($id)
    {
        $outage = PoeOutage::whereNull('on_at')->find($id);
        if (!$outage)
            return;
        $config = SwitchPortFinder::config();
        if ($config['host'] !== $outage->switch_host)
            return $this->say('The switch has changed: switch '.$outage->port.' on at the switch.', true);
        $control = PoeControl::forConfig($config);
        if (!$control)
            return $this->say('No write access to the switch: set it under Settings → Ethernet switch.', true);

        $error = $control->switchOnChecked($outage->oid, $outage->on_value);
        if ($error !== null)
        {
            $outage->update(['error' => $error]);
            return $this->say('PoE on '.$outage->port.' is still off: '.$error, true);
        }
        $outage->update(['on_at' => now(), 'error' => null]);
        Cmlog::create(['cm' => $outage->cm_serial, 'board' => $outage->port, 'loglevel' => 'info',
            'msg' => 'PoE on '.$outage->port.' switched on by '.$this->who()]);
        $this->say('PoE on '.$outage->port.' is on again.');
    }

    public function forget($id)
    {
        $outage = PoeOutage::whereNull('on_at')->find($id);
        if (!$outage)
            return;
        $outage->update(['on_at' => now()]);
        Cmlog::create(['cm' => $outage->cm_serial, 'board' => $outage->port, 'loglevel' => 'warning',
            'msg' => 'PoE outage of '.$outage->port.' forgotten by '.$this->who().'; the port may still be off']);
        $this->say('Forgotten: check '.$outage->port.' at the switch.');
    }

    public function render()
    {
        return view('livewire.poe-outages', ['outages' => PoeOutage::stayedOff()->get()]);
    }

    protected function say($text, $error = false)
    {
        $this->message = $text;
        $this->messageIsError = $error;
    }

    protected function who()
    {
        return auth()->user()->name ?? 'someone';
    }
}
