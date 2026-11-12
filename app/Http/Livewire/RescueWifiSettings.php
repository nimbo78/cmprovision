<?php

namespace App\Http\Livewire;

use App\Services\RescueWifi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RescueWifiSettings extends Component
{
    use ConfirmsPasswords;

    public $enabled = false, $delayMinutes = 10, $ssid = '', $ap_mac = '', $client_mac = '', $subnet = '', $channel = 6;
    public $management_profiles = [], $notice = null, $confirmingDisconnect = false;
    #[Locked] public $available = false;
    #[Locked] public $radios = [], $managementOptions = [];
    #[Locked] public $readinessError = null;
    #[Locked] public $pendingAction = null, $pendingSettings = [];

    public function mount(RescueWifi $wifi)
    {
        abort_unless(Auth::check(), 403);
        $this->loadSettings($wifi);
    }

    private function loadSettings(RescueWifi $wifi): void
    {
        $this->available = $wifi->available();
        try {
            $data = $wifi->metadata();
            foreach (['enabled', 'ssid', 'ap_mac', 'client_mac', 'subnet', 'channel', 'management_profiles'] as $key)
                $this->$key = $data['settings'][$key];
            $this->delayMinutes = (int) ($data['settings']['delay'] / 60);
            $this->radios = $data['radios'];
            $this->managementOptions = $data['management_profiles'];
            $this->readinessError = $data['readiness_error'];
        } catch (\Throwable $error) {
            $this->readinessError = $error->getMessage();
        }
    }

    private function authorizeChange(): void
    {
        abort_unless(Auth::check(), 403);
        $this->ensurePasswordIsConfirmed();
    }

    private function values(): array
    {
        $values = $this->validate(['enabled' => 'required|boolean', 'delayMinutes' => 'required|integer|min:1|max:30',
            'ssid' => ['nullable', 'string', 'max:32', function ($attribute, $value, $fail) {
                if (strlen($value) > 32 || preg_match('/[\x00-\x1F\x7F]/', $value)) $fail('SSID must contain at most 32 UTF-8 bytes without control characters.');
            }], 'ap_mac' => ['nullable', 'regex:/\A(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\z/'],
            'client_mac' => ['nullable', 'regex:/\A(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\z/'], 'subnet' => 'nullable|string|max:32',
            'channel' => 'required|integer|in:1,6,11', 'management_profiles' => 'array|max:32', 'management_profiles.*' => 'uuid']);
        $values['delay'] = (int) $values['delayMinutes'] * 60;
        unset($values['delayMinutes']);
        foreach (['ssid', 'ap_mac', 'client_mac', 'subnet'] as $key) $values[$key] = (string) ($values[$key] ?? '');
        $values['enabled'] = (bool) $values['enabled'];
        $values['channel'] = (int) $values['channel'];
        return $values;
    }

    public function save(RescueWifi $wifi)
    {
        $this->authorizeChange();
        $this->resetErrorBag();
        $values = $this->values();
        if (!$values['enabled'] && ($wifi->status()['active'] ?? false)) {
            $this->pendingSettings = $values;
            $this->pendingAction = 'save';
            $this->confirmingDisconnect = true;
            return;
        }
        $this->store($wifi, $values);
    }

    private function store(RescueWifi $wifi, array $values): void
    {
        try {
            $active = $wifi->status()['active'] ?? false;
            $wifi->save($values);
            $this->loadSettings($wifi);
            $this->notice = $active && !($values['enabled'] ?? $this->enabled)
                ? 'Rescue access is being disabled. Reconnect through another management link.'
                : ($active ? 'Saved for next start. The current AP session continues.' : 'Saved. The controller applies changes within a few seconds.');
        } catch (\Throwable $error) {
            $this->addError('enabled', $error->getMessage());
        }
    }

    public function begin(string $action, RescueWifi $wifi)
    {
        $this->authorizeChange();
        abort_unless(in_array($action, ['start', 'pause', 'resume', 'restart'], true), 422);
        if (in_array($action, ['pause', 'restart'], true) && ($wifi->status()['active'] ?? false)) {
            $this->pendingAction = $action;
            $this->confirmingDisconnect = true;
            return;
        }
        $this->perform($wifi, $action);
    }

    private function perform(RescueWifi $wifi, string $action): void
    {
        try {
            $wifi->action($action);
            $this->notice = 'Requested. The controller applies the action within a few seconds.';
        } catch (\Throwable $error) { $this->addError('enabled', $error->getMessage()); }
    }

    public function confirmDisconnect(RescueWifi $wifi)
    {
        $this->authorizeChange();
        $action = $this->pendingAction;
        $this->confirmingDisconnect = false;
        if ($action === 'save') $this->store($wifi, $this->pendingSettings);
        elseif (in_array($action, ['pause', 'restart'], true)) $this->perform($wifi, $action);
        $this->pendingAction = null;
        $this->pendingSettings = [];
    }

    public function cancelDisconnect(): void
    {
        $this->confirmingDisconnect = false;
        $this->pendingAction = null;
        $this->pendingSettings = [];
    }

    public function showCredentials(RescueWifi $wifi)
    {
        $this->authorizeChange();
        try { $this->dispatch('rescue-wifi-details', details: $wifi->reveal()); }
        catch (\Throwable $error) { $this->addError('enabled', $error->getMessage()); }
    }

    public function changePassword($password, RescueWifi $wifi)
    {
        $this->authorizeChange();
        $valid = Validator::make(['password' => $password], ['password' => ['required', 'string', 'regex:/\A[ -~]{12,63}\z/']])->validate();
        $this->store($wifi, $valid);
        $this->dispatch('rescue-wifi-password-saved');
    }

    public function render(RescueWifi $wifi)
    {
        $status = $wifi->status();
        return view('livewire.rescue-wifi-settings', ['status' => $status, 'statusLabel' => RescueWifi::label($status), 'reason' => RescueWifi::reason($status)]);
    }
}
