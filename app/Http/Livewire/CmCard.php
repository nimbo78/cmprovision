<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Services\Switch\PoeControl;
use App\Services\Switch\PoeRestart;
use App\Services\Switch\Stand;
use App\Services\SwitchPortFinder;
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
            'settings' => self::settingsDiff($cm->eeprom_config_before, $cm->eeprom_config_after),
            'history' => Cmlog::where('cm', $this->serial)->orderByDesc('id')->limit(self::HISTORY_ROWS)->get(),
            'zone' => config('app.display_timezone'),
            'power' => $this->powerLoaded ? $this->power($cm) : null,
            'powerPending' => !$this->powerLoaded && SwitchPortFinder::config()['host'] !== '',
        ])->title($cm->serial);
    }

    /* The module's port and its PoE on the switch's snapshot, and why it may not be restarted; null without a switch */
    protected function power(Cm $cm): ?array
    {
        $stand = Stand::current();
        if (!$stand)
            return null;
        $tile = Stand::tileOf($stand['map'], $cm);
        if (!PoeControl::writable($stand['config']))
            $blocker = 'no write access (Settings → Ethernet switch)';
        elseif (!$stand['map'] || $stand['error'])
            $blocker = 'the switch does not answer';
        elseif (!$tile)
            $blocker = 'not seen on the switch';
        else
            $blocker = StandMap::restartBlocker($tile);
        return ['port' => $tile['name'] ?? null, 'poe' => $tile['poe'] ?? null, 'blocker' => $blocker,
                'confirm' => PoeRestart::confirm($cm, $tile['name'] ?? null)];
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
