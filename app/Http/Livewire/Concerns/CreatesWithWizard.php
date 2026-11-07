<?php

namespace App\Http\Livewire\Concerns;

use App\Services\Configuration\WizardSecrets;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait CreatesWithWizard
{
    #[Locked] public $wizardMode = false;
    #[Locked] public $wizardStep = 1;
    #[Locked] public $wizardToken = null;

    protected function beginWizard(): void
    {
        $this->endWizard();
        $this->wizardMode = true;
        $this->wizardToken = app(WizardSecrets::class)->start();
    }

    protected function endWizard(): void
    {
        if ($this->wizardToken !== null) app(WizardSecrets::class)->forget($this->wizardToken);
        $this->wizardMode = false;
        $this->wizardStep = 1;
        $this->wizardToken = null;
    }

    public function nextWizardStep(#[\SensitiveParameter] array $secrets = []): void
    {
        abort_unless($this->wizardMode && $this->isOpen, 409);
        $this->validateWizardStep($secrets);
        $steps = array_keys($this->wizardSteps());
        $index = array_search($this->wizardStep, $steps, true);
        $this->wizardStep = $steps[min(count($steps) - 1, $index + 1)];
        $this->resetErrorBag();
        $this->dispatch('wizard-step-changed');
    }

    public function previousWizardStep(): void
    {
        abort_unless($this->wizardMode && $this->isOpen, 409);
        $steps = array_keys($this->wizardSteps());
        $index = array_search($this->wizardStep, $steps, true);
        $this->wizardStep = $steps[max(0, $index - 1)];
        $this->resetErrorBag();
        $this->dispatch('wizard-step-changed');
    }

    protected function ensureWizardSummary(): void
    {
        if ($this->wizardMode && $this->wizardStep !== array_key_last($this->wizardSteps())) {
            throw ValidationException::withMessages(['wizard' => 'Review the final summary before saving.']);
        }
    }

    protected function wizardData(): array
    {
        return app(WizardSecrets::class)->get($this->wizardToken);
    }

    protected function keepWizardData(#[\SensitiveParameter] array $values): void
    {
        app(WizardSecrets::class)->put($this->wizardToken, $values);
    }
}
