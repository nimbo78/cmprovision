<?php

namespace App\Http\Livewire;

use App\Services\StationAccessControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Locked;
use Livewire\Component;

class StationAccessSettings extends Component
{
    use ConfirmsPasswords;

    public string $name = '', $email = '', $linuxUser = '';
    public bool $enableSsh = false;
    #[Locked] public ?array $plan = null;
    #[Locked] public array $status = [];
    public ?string $notice = null, $error = null;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
        $this->name = auth()->user()->name;
        $this->email = auth()->user()->email;
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $control = app(StationAccessControl::class);
        $result = $control->available() ? $control->status() : ['ok' => false];
        $this->status = ($result['ok'] ?? false) ? ($result['result'] ?? []) : [];
        if (isset($result['error'])) $this->error = $result['error'];
        $pending = $this->status['pending'] ?? null;
        $this->plan = is_array($pending) && ($pending['id'] ?? null) === session('station_access_request') ? $pending : null;
        if ($this->linuxUser === '') $this->linuxUser = $this->status['users'][0]['user'] ?? '';
    }

    public function saveWeb(string $current = '', string $password = '', string $confirmation = ''): void
    {
        abort_unless(auth()->check(), 403);
        $this->resetValidation(); $this->error = $this->notice = null;
        try {
            if (!Hash::check($current, auth()->user()->password)) {
                throw ValidationException::withMessages(['current_password' => 'Enter your current web password.']);
            }
            DB::transaction(function () use ($current, $password, $confirmation) {
                app(UpdatesUserProfileInformation::class)->update(auth()->user(), ['name' => $this->name, 'email' => $this->email]);
                if ($password !== '' || $confirmation !== '') {
                    app(UpdatesUserPasswords::class)->update(auth()->user(), ['current_password' => $current,
                        'password' => $password, 'password_confirmation' => $confirmation]);
                }
            });
            // Jetstream's authenticated-session middleware must keep this session after a password change.
            session()->put('password_hash_web', auth()->guard('web')->hashPasswordForCookie(auth()->user()->getAuthPassword()));
            session()->put('auth.password_confirmed_at', time());
            $this->notice = 'Web account saved.';
            $this->dispatch('station-wizard-saved');
        } finally { $this->dispatch('station-access-clear-secrets'); }
    }

    public function prepareSsh(string $password = '', string $keys = ''): void
    {
        $this->ensurePasswordIsConfirmed();
        $this->error = $this->notice = null;
        try {
            $result = app(StationAccessControl::class)->prepare(['user' => $this->linuxUser, 'password' => $password,
                'keys' => $keys, 'enable_ssh' => $this->enableSsh]);
            if (!($result['ok'] ?? false)) { $this->error = $result['error'] ?? 'Could not prepare SSH access.'; return; }
            $this->plan = $result['result'];
            session()->put('station_access_request', $this->plan['id']);
        } finally { $this->dispatch('station-access-clear-secrets'); }
    }

    public function applySsh(string $code = ''): void
    {
        $this->ensurePasswordIsConfirmed();
        if ($this->plan === null) { $this->error = 'Prepare an SSH access request first.'; return; }
        $result = app(StationAccessControl::class)->apply($this->plan['id'], $code);
        $this->dispatch('station-access-clear-secrets');
        if (!($result['ok'] ?? false)) { $this->error = $result['error'] ?? 'Could not update SSH access.'; return; }
        $this->error = null;
        $this->notice = 'SSH access updated. '.implode('. ', $result['result']['applied'] ?? []);
        $this->plan = null; session()->forget('station_access_request');
        $this->dispatch('station-wizard-saved');
    }

    public function cancelSsh(): void
    {
        $this->ensurePasswordIsConfirmed();
        if ($this->plan) {
            $result = app(StationAccessControl::class)->cancel($this->plan['id']);
            if (!($result['ok'] ?? false)) { $this->error = $result['error'] ?? 'Could not cancel the request.'; return; }
        }
        $this->plan = null; session()->forget('station_access_request');
        $this->dispatch('station-access-clear-secrets'); $this->dispatch('station-wizard-saved');
    }

    public function render() { return view('livewire.station-access-settings'); }
}
