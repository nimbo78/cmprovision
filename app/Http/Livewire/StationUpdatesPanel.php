<?php

namespace App\Http\Livewire;

use App\Services\StationUpdates;
use App\Services\StationUpdatesControl;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Locked;
use Livewire\Component;

class StationUpdatesPanel extends Component
{
    use ConfirmsPasswords;
    public bool $applicationCheck = false, $utilityCheck = false, $open = false;
    #[Locked] public string $component = 'application';
    #[Locked] public ?array $confirmation = null;
    public ?string $error = null;
    public function mount(): void
    {
        $preferences = app(StationUpdates::class)->preferences();
        $this->applicationCheck = $preferences['application'];
        $this->utilityCheck = $preferences['utility-os'];
    }
    private function actor(): string
    {
        abort_unless(auth()->check(), 403);
        return 'web:'.auth()->id().':'.hash('sha256', session()->getId());
    }
    public function updatedApplicationCheck(): void { $this->savePreferences(); }
    public function updatedUtilityCheck(): void { $this->savePreferences(); }
    private function savePreferences(): void
    {
        $this->actor();
        app(StationUpdates::class)->savePreferences($this->applicationCheck, $this->utilityCheck);
    }
    private function execute(callable $action): void
    {
        $this->actor();
        $this->error = null;
        try { $result = $action(); $this->dispatch('station-update-started', id: $result['id'] ?? null); }
        catch (\RuntimeException|\InvalidArgumentException $error) { $this->error = $error->getMessage(); }
    }
    public function check(): void { $this->execute(fn () => app(StationUpdates::class)->check()); }
    public function selectComponent(string $component): void
    {
        $this->actor();
        abort_unless(in_array($component, StationUpdates::COMPONENTS, true), 422);
        $this->component = $component;
        $this->error = null;
    }
    public function download(string $component, string $tag): void
    {
        $this->execute(fn () => app(StationUpdates::class)->download($component, $tag));
    }
    public function begin(string $action, string $target): void
    {
        $actor = $this->actor();
        $this->ensurePasswordIsConfirmed();
        $this->back();
        try { $this->confirmation = app(StationUpdates::class)->prepare($action, $target, $actor); $this->open = true; }
        catch (\RuntimeException|\InvalidArgumentException $error) { $this->error = $error->getMessage(); }
    }
    public function recheckSpace(): void
    {
        abort_unless($this->open && isset($this->confirmation['space']), 403);
        $this->begin($this->confirmation['action'], $this->confirmation['target']);
    }
    public function confirm(): void
    {
        $actor = $this->actor();
        $this->ensurePasswordIsConfirmed();
        abort_unless($this->open && $this->confirmation !== null, 403);
        if (!isset($this->confirmation['token'])) {
            $this->error = 'Recheck disk space before confirming.';
            return;
        }
        $this->execute(function () use ($actor) {
            $result = app(StationUpdates::class)->confirm($this->confirmation['token'], $actor);
            $this->open = false;
            $this->confirmation = null;
            return $result;
        });
    }
    public function back(): void
    {
        $actor = $this->actor();
        if (isset($this->confirmation['token'])) {
            try { app(StationUpdates::class)->abandon($this->confirmation['token'], $actor); }
            catch (\RuntimeException $expired) { /* An expired ticket is already inert. */ }
        }
        $this->open = false;
        $this->confirmation = null;
        $this->error = null;
    }
    public function updatedOpen(bool $value): void { if (!$value) $this->back(); }
    public function cancel(string $id): void { $this->execute(fn () => app(StationUpdates::class)->cancel($id)); }
    public function render()
    {
        $control = app(StationUpdatesControl::class);
        return view('livewire.station-updates-panel', ['available' => $control->available(), 'status' => $control->status()]);
    }
}
