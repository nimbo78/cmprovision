<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\EthernetSwitch;
use App\Services\Switch\PoeControl;
use App\Services\Switch\PoeRestart;
use App\Services\Switch\Stand;
use App\Support\FailureAdvice;
use App\Support\RunTimeline;
use App\Support\StandMap;
use Livewire\Component;

/* The module card (/cms/<serial>): the last run step by step, the bootloader before and after, the
   script logs and the module's history; for a failed or silent module where it stopped and what to do */
class CmCard extends Component
{
    const HISTORY_ROWS = 200;
    const HISTORY_MESSAGE_LENGTH = 4000;

    public $serial;
    public $restartMessage = null, $restartError = false;
    public $powerLoaded = false;

    public function mount($serial)
    {
        Cm::where('serial', $serial)->firstOrFail();
        $this->serial = $serial;
    }

    /* The Power row asks the switch, which may be slow or silent: the card shows first, then the row (wire:init) */
    public function loadPower()
    {
        $this->powerLoaded = true;
    }

    /* The module blinks for the operator the next time it asks the server */
    public function identify()
    {
        Cm::where('serial', $this->serial)->firstOrFail()->identify();
    }

    public function stopIdentify()
    {
        Cm::where('serial', $this->serial)->firstOrFail()->stopIdentify();
    }

    /* The next start writes the image from the beginning instead of going on from the last durable piece (2.6.1) */
    public function startOver()
    {
        $cm = Cm::where('serial', $this->serial)->firstOrFail();
        if ($cm->durable_bytes === null)
            return;
        $cm->durable_bytes = null;
        $cm->save();
        Cmlog::create(['cm' => $cm->serial, 'board' => $cm->provisioning_board, 'loglevel' => 'info',
            'msg' => 'Start over: the next start writes the image from the beginning (by '.(auth()->user()->name ?? 'unknown').').']);
    }

    /* Switch the PoE of the module's port off and on (PoeRestart) */
    public function restart()
    {
        $cm = Cm::where('serial', $this->serial)->firstOrFail();
        ['text' => $this->restartMessage, 'error' => $this->restartError] =
            PoeRestart::message(PoeRestart::modules([$cm], auth()->user()->name ?? null));
        $this->dispatch('poe-outages-changed');   // a port left off shows in the banner at once
    }

    public function render()
    {
        $cm = Cm::where('serial', $this->serial)->firstOrFail();

        return view('livewire.cm-card', [
            'cm' => $cm,
            'steps' => RunTimeline::steps($cm),
            'advice' => FailureAdvice::for($cm),
            'resumeFrom' => $cm->resumableFrom(),
            'settings' => self::settingsDiff($cm->eeprom_config_before, $cm->eeprom_config_after),
            'history' => Cmlog::where('cm', $this->serial)->orderByDesc('id')->limit(self::HISTORY_ROWS)->get(),
            'zone' => config('app.display_timezone'),
            'power' => $this->powerLoaded ? $this->power($cm) : null,
            'powerPending' => !$this->powerLoaded && EthernetSwitch::exists(),
        ])->title($cm->serial);
    }

    /* The module's port and its PoE on its switch's snapshot, and why it may not be restarted; null without a switch */
    protected function power(Cm $cm): ?array
    {
        $stand = Stand::current();
        if (!$stand)
            return null;
        $tile = Stand::tileOf($stand['map'], $cm);
        $state = $tile ? $stand['switches'][$tile['switch_id']] : null;
        if (!$tile)
            $blocker = Stand::notSeen($stand);
        elseif (!PoeControl::writable($state['config']))
            $blocker = 'no write access on '.$tile['switch'].' (Settings → Ethernet switches)';
        elseif (!Stand::answering($state))
            $blocker = $tile['switch'].' does not answer';
        else
            $blocker = StandMap::restartBlocker($tile);
        return ['port' => $tile['label'] ?? null, 'poe' => $tile['poe'] ?? null, 'blocker' => $blocker,
                'confirm' => PoeRestart::confirm($cm, $tile['label'] ?? null)];
    }

    /* Both sides of the bootloader settings line by line ('before' or 'after' is null when unknown);
       a line the other side does not have is marked changed */
    public static function settingsDiff($before, $after)
    {
        $lines = function ($text) {
            if ($text === null)
                return null;
            $lines = array_map('rtrim', explode("\n", str_replace("\r", '', $text)));
            return array_values(array_filter($lines, 'strlen'));
        };
        $mark = function ($side, $other) {
            if ($side === null)
                return null;
            return array_map(function ($line) use ($other) {
                return ['text' => $line, 'changed' => $other !== null && !in_array($line, $other, true)];
            }, $side);
        };

        $b = $lines($before);
        $a = $lines($after);
        return ['before' => $mark($b, $a), 'after' => $mark($a, $b)];
    }
}
