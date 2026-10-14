<?php

namespace App\Http\Livewire;

use App\Models\EthernetSwitch;
use App\Models\PoeOutage;
use App\Services\Switch\PoeControl;
use Livewire\Component;

/* Ports a PoE restart left off (PoeOutage::stayedOff()), on the dashboard, the Ports page and the module card until
   they are on again */
class PoeOutages extends Component
{
    public $message = null, $messageIsError = false;

    /* A restart on the same page (PoeRestart) shows a port it left off at once, not at the next poll */
    protected $listeners = ['poe-outages-changed' => '$refresh'];

    public function powerOn($id)
    {
        $outage = PoeOutage::whereNull('on_at')->find($id);
        if (!$outage)
            return;
        // a row from before 2.4 knows its switch by address only
        $switch = $outage->switch_id ? EthernetSwitch::find($outage->switch_id) : EthernetSwitch::where('host', $outage->switch_host)->first();
        if (!$switch)
            return $this->say('The switch is gone: switch '.$outage->port.' on at the switch.', true);
        $control = PoeControl::forConfig($switch->config());
        if (!$control)
            return $this->say('No write access on '.$switch->hostname().': set it under Settings → Ethernet switches.', true);

        $error = $control->switchOnChecked($outage->oid, $outage->on_value);
        if ($error !== null)
        {
            $outage->update(['error' => $error]);
            return $this->say('PoE on '.$outage->port.' is still off: '.$error, true);
        }
        $outage->update(['on_at' => now(), 'error' => null]);
        $outage->log('info', 'PoE on '.$outage->port.' switched on by '.$this->who());
        $this->say('PoE on '.$outage->port.' is on again.');
    }

    public function forget($id)
    {
        $outage = PoeOutage::whereNull('on_at')->find($id);
        if (!$outage)
            return;
        $outage->update(['on_at' => now()]);
        $outage->log('warning', 'PoE outage of '.$outage->port.' forgotten by '.$this->who().'; the port may still be off');
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
