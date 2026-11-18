<?php

namespace App\Http\Livewire;

use App\Models\Setting;
use App\Services\StationWizardState;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Station setup')]
class StationSetupWizard extends Component
{
    const STEPS = [1 => 'Overview', 2 => 'Management network', 3 => 'Switch', 4 => 'Access', 5 => 'Project', 6 => 'Stand role', 7 => 'Review'];
    protected $listeners = ['station-network-changed' => 'refreshNetwork'];
    #[Locked] public int $step = 1;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
        $saved = session('station_wizard_step', 1);
        $this->step = is_int($saved) && isset(self::STEPS[$saved]) ? $saved : 1;
    }

    public function go(int $step): void
    {
        $this->resetValidation();
        if (!isset(self::STEPS[$step])) { $this->addError('wizard', 'Choose a valid setup step.'); return; }
        $state = app(StationWizardState::class)->read();
        if ($this->networkBlocksNavigation($state)) return;
        $this->step = $step; session()->put('station_wizard_step', $step);
        $this->dispatch('station-wizard-step-changed');
    }

    public function leave()
    {
        $state = app(StationWizardState::class)->read();
        if ($this->networkBlocksNavigation($state)) return;
        session()->put('station_wizard_step', $this->step);
        return $this->redirectRoute('settings', ['tab' => 'system']);
    }

    public function finish()
    {
        $this->resetValidation();
        if ($this->step !== 7) { $this->addError('wizard', 'Review the actual configuration before completing setup.'); return; }
        $state = app(StationWizardState::class)->read();
        if ($state['blockers']) { $this->addError('wizard', implode(' ', $state['blockers'])); return; }
        Setting::updateOrCreate(['key' => 'station_wizard_completed'], ['value' => json_encode(['schema' => 1, 'at' => now()->toIso8601String(), 'user_id' => auth()->id()])]);
        session()->forget('station_wizard_step');
        session()->flash('message', 'Station setup checked. Existing settings and boot service roles are in effect.');
        return $this->redirectRoute('settings', ['tab' => 'system']);
    }

    public function render()
    {
        return view('livewire.station-setup-wizard', ['steps' => self::STEPS, 'state' => app(StationWizardState::class)->read()]);
    }

    public function refreshNetwork(): void
    {
        $this->resetValidation('wizard');
    }

    private function networkBlocksNavigation(array $state): bool
    {
        if ($state['pending'] === false) return false;
        $this->addError('wizard', $state['pending'] === true ? 'Confirm or restore the pending network change before continuing.' : 'Network transaction status is unavailable. Refresh before continuing.');
        if (in_array($this->step, [2, 6], true)) {
            // Another session may have started a change since the embedded network editor last rendered.
            $this->dispatch('station-network-recheck')->to(NetworkSettings::class);
        }
        return true;
    }
}
