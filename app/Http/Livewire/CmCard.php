<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Support\FailureAdvice;
use App\Support\RunTimeline;
use Livewire\Component;

/* The module card (/cms/<serial>): the last run step by step, the bootloader before and after, the
   script logs and the module's history; for a failed or silent module where it stopped and what to do */
class CmCard extends Component
{
    const HISTORY_ROWS = 200;
    const HISTORY_MESSAGE_LENGTH = 4000;

    public $serial;

    public function mount($serial)
    {
        Cm::where('serial', $serial)->firstOrFail();
        $this->serial = $serial;
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
        ]);
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
