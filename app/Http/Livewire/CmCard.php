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
use App\Support\ModuleDiagnostics;
use Livewire\Component;

/* The module card (/cms/<mac>): the last run step by step, the bootloader before and after, the
   script logs and the module's history; for a failed or silent module where it stopped and what to do */
class CmCard extends Component
{
    const HISTORY_ROWS = 200;
    const HISTORY_MESSAGE_LENGTH = 4000;

    public $mac;
    public $restartMessage = null, $restartError = false;
    public $powerLoaded = false;
    public $diagnosticsMessage = null;

    /* The address says the MAC; a serial (the links of before 2.11, a test) finds the module as well. The card keeps to the
       MAC: the serial of a module may change while the card is open, its MAC is its key. */
    protected function module(): Cm
    {
        return Cm::where('mac', $this->mac)->firstOrFail();
    }

    public function mount($mac = '', $serial = '')
    {
        $cm = Cm::findByKey($mac !== '' ? $mac : $serial) ?? throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(Cm::class);
        $this->mac = $cm->mac;
    }

    /* The Power row asks the switch, which may be slow or silent: the card shows first, then the row (wire:init) */
    public function loadPower()
    {
        $this->powerLoaded = true;
    }

    /* The module blinks for the operator the next time it asks the server */
    public function identify()
    {
        $this->module()->identify();
    }

    public function stopIdentify()
    {
        $this->module()->stopIdentify();
    }

    /* The next start writes the image from the beginning instead of going on from the last durable piece (2.6.1) */
    public function startOver()
    {
        $cm = $this->module();
        if ($cm->durable_bytes === null)
            return;
        $cm->durable_bytes = null;
        $cm->durable_at = null;
        $cm->save();
        Cmlog::create(['cm' => $cm->mac, 'board' => $cm->provisioning_board, 'loglevel' => 'info',
            'msg' => 'Start over: the next start writes the image from the beginning (by '.(auth()->user()->name ?? 'unknown').').']);
    }

    /* Switch the PoE of the module's port off and on (PoeRestart) */
    public function restart()
    {
        $cm = $this->module();
        ['text' => $this->restartMessage, 'error' => $this->restartError] =
            PoeRestart::message(PoeRestart::modules([$cm], auth()->user()->name ?? null));
        $this->dispatch('poe-outages-changed');   // a port left off shows in the banner at once
    }

    public function collectModuleDiagnostics()
    {
        $cm = $this->module();
        if (!$cm->canCollectModuleDiagnostics())
        {
            $this->diagnosticsMessage = 'The module must be online in the current utility OS to collect a new snapshot.';
            return;
        }
        // No operation lock: this only marks a request, and a module that is busy must not turn the button into an error.
        $cutoff = now()->subSeconds(ModuleDiagnostics::REQUEST_SECONDS);
        Cm::whereKey($cm->id)
            ->where(fn ($q) => $q->whereNull('diagnostics_requested_at')->orWhere('diagnostics_requested_at', '<=', $cutoff))
            ->update(['diagnostics_requested_at' => now()]);
        $this->diagnosticsMessage = null;
    }

    public function downloadModuleDiagnostics()
    {
        $cm = $this->module();
        abort_unless($cm->storage_diagnostics, 404);
        $text = ModuleDiagnostics::text($cm);
        return response()->streamDownload(fn () => print($text), 'cm-'.$cm->id.'-module-diagnostics.txt', ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function render()
    {
        $cm = $this->module();

        return view('livewire.cm-card', [
            'cm' => $cm,
            'usbReport' => \App\Models\UsbDiagnosticReport::where('serial', $cm->serial)->orderByDesc('received_at')->first(),
            'moduleDiagnosis' => ModuleDiagnostics::summary($cm->storage_diagnostics),
            'diagnosticsAvailable' => $cm->canCollectModuleDiagnostics(),
            'diagnosticsPending' => $cm->diagnostics_requested_at?->gt(now()->subSeconds(ModuleDiagnostics::REQUEST_SECONDS)) ?? false,
            'steps' => RunTimeline::steps($cm),
            'advice' => FailureAdvice::for($cm),
            'resumeFrom' => $cm->resumableFrom(),
            'settings' => self::settingsDiff($cm->eeprom_config_before, $cm->eeprom_config_after),
            'history' => $cm->logLines()->orderByDesc('id')->limit(self::HISTORY_ROWS)->get(),
            'zone' => config('app.display_timezone'),
            'power' => $this->powerLoaded ? $this->power($cm) : null,
            'powerPending' => !$this->powerLoaded && EthernetSwitch::exists(),
        ])->title($cm->displayName());
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
