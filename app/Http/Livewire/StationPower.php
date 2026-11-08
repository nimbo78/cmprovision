<?php

namespace App\Http\Livewire;

use App\Services\StationPower as Power;
use App\Services\StationPowerControl;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Locked;
use Livewire\Component;

class StationPower extends Component
{
    use ConfirmsPasswords;
    public bool $open = false;
    #[Locked] public ?array $confirmation = null;
    public ?string $error = null;

    private function actor(): string
    {
        abort_unless(auth()->check(), 403);
        return 'web:'.auth()->id().':'.hash('sha256', session()->getId());
    }
    public function begin(string $action): void
    {
        $actor = $this->actor();
        $this->ensurePasswordIsConfirmed();
        $this->error = null;
        try {
            $this->confirmation = app(Power::class)->prepare($action, $actor);
            $this->open = true;
        } catch (\RuntimeException|\InvalidArgumentException $error) { $this->error = $error->getMessage(); }
    }
    public function confirm(): void
    {
        $actor = $this->actor();
        $this->ensurePasswordIsConfirmed();
        abort_unless($this->open && $this->confirmation !== null, 403);
        $this->error = null;
        try {
            $result = app(Power::class)->confirm($this->confirmation['token'], $actor, $this->confirmation['stage'] === 'interrupt');
            if ($result['stage'] === 'scheduled') {
                $this->confirmation = null;
                $this->open = false;
                $this->dispatch('station-power-scheduled', ...$result);
            } else $this->confirmation = $result;
        } catch (\Throwable $error) {
            $this->error = get_class($error) === \RuntimeException::class ? $error->getMessage() : 'The power request could not be completed. Check station status before retrying.';
            if ($pending = app(StationPowerControl::class)->pending()) $this->dispatch('station-power-scheduled', ...$pending);
        }
    }
    public function cancel(): void
    {
        $actor = $this->actor();
        if ($this->confirmation !== null) {
            try { app(Power::class)->cancel($this->confirmation['token'], $actor); }
            catch (\RuntimeException $expired) { /* Expired confirmations are already inert. */ }
        }
        $this->confirmation = null;
        $this->open = false;
        $this->error = null;
    }
    public function updatedOpen(bool $value): void { if (!$value) $this->cancel(); }
    public function render()
    {
        $control = app(StationPowerControl::class);
        return view('livewire.station-power', ['available' => $control->available(), 'pending' => $control->pending(), 'host' => gethostname()]);
    }
}
