<?php

namespace App\Http\Livewire;

use App\Services\Sensor\SavedAccounts;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ReprovisionAccounts extends Component
{
    use ConfirmsPasswords;

    #[Locked] public ?string $editing = null;
    public bool $form = false, $enabled = true;
    public string $name = '', $user = '';
    public ?string $error = null;

    public function add(): void
    {
        $this->authorizeUser(); $this->resetValidation();
        $this->editing = null; $this->name = $this->user = ''; $this->enabled = true; $this->error = null; $this->form = true;
    }

    public function edit(string $id): void
    {
        $this->authorizeUser(); $this->resetValidation(); $this->error = null;
        foreach (app(SavedAccounts::class)->listing() as $a) {
            if ($a['id'] !== $id) continue;
            $this->editing = $id; $this->name = $a['name']; $this->user = $a['user'];
            $this->enabled = $a['enabled']; $this->form = true; return;
        }
        $this->error = 'This saved sensor account no longer exists.';
    }

    public function cancel(): void { $this->form = false; $this->editing = null; $this->resetValidation(); }

    public function save(string $password = ''): void
    {
        try { $this->change(function (SavedAccounts $accounts) use ($password) {
            $accounts->save($this->editing, $this->name, $this->user, $password, $this->enabled);
            $this->cancel();
        }); } finally { $this->dispatch('sensor-account-secret-cleared'); }
    }

    public function remove(string $id): void { $this->change(fn ($a) => $a->remove($id)); }
    public function toggle(string $id): void { $this->change(fn ($a) => $a->toggle($id)); }
    public function moveUp(string $id): void { $this->change(fn ($a) => $a->move($id, -1)); }
    public function moveDown(string $id): void { $this->change(fn ($a) => $a->move($id, 1)); }

    private function authorizeUser(): void { abort_unless(auth()->check(), 403); }
    private function change(callable $action): void
    {
        $this->authorizeUser(); $this->ensurePasswordIsConfirmed(); $this->error = null;
        try { $action(app(SavedAccounts::class)); }
        catch (\RuntimeException $e) { $this->error = $e->getMessage(); }
    }

    public function render()
    {
        $accounts = [];
        try { $accounts = app(SavedAccounts::class)->listing(); }
        catch (\RuntimeException $e) { $this->error = $e->getMessage(); }
        return view('livewire.reprovision-accounts', compact('accounts'));
    }
}
