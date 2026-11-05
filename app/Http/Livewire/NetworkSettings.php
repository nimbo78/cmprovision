<?php

namespace App\Http\Livewire;

use App\Services\NetworkChanges;
use App\Services\NetworkProfiles;
use App\Support\NetworkRoutingInput;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NetworkSettings extends Component
{
    use ConfirmsPasswords;

    #[Locked]
    public $device = null, $uuid = null;
    #[Locked]
    public $editingSaved = false;
    public $ipv4 = [], $ipv6 = [];
    public $standTarget = '';
    public $activationDevice = '';
    public $newDevice = '', $newProfileName = '';
    public $notice = null, $noticeIsError = false;

    public function render()
    {
        $changes = app(NetworkChanges::class);
        $available = $changes->available();
        $status = $available ? $changes->status() : null;
        $pending = ($status['ok'] ?? false) ? ($status['result'] ?? null) : null;
        if (is_array($pending)) {
            $pending['can_confirm'] = isset($pending['token']);
            unset($pending['token']); // the confirmation token never goes to the browser
        }
        $profiles = $available ? app(NetworkProfiles::class)->active() : null;
        return view('livewire.network-settings', [
            'available' => $available,
            'profiles' => $profiles,
            'savedProfiles' => $available ? app(NetworkProfiles::class)->saved() : null,
            'ethernetDevices' => $available ? app(NetworkProfiles::class)->ethernetDevices() : null,
            'stand' => $available ? app(NetworkProfiles::class)->stand($profiles) : null,
            'pending' => $pending,
            'statusError' => $status !== null && !($status['ok'] ?? false) ? ($status['error'] ?? 'Network status is unavailable.') : null,
        ]);
    }

    public function edit(string $device, string $uuid, bool $saved = false): void
    {
        $profile = app(NetworkProfiles::class)->detail($device, $uuid, $saved);
        if ($profile === null) {
            $this->flash('The active network profile is no longer available.', true);
            return;
        }
        if (!in_array($profile['ipv4']['method'], ['auto', 'manual', 'disabled', 'link-local'], true) ||
            !in_array($profile['ipv6']['method'], ['auto', 'dhcp', 'manual', 'disabled', 'link-local', 'ignore'], true)) {
            $this->flash('This profile uses an assignment method that must be edited with the system network tools.', true);
            return;
        }
        $this->device = $device;
        $this->uuid = $uuid;
        $this->editingSaved = $saved;
        $this->ipv4 = $this->formValues($profile['ipv4']);
        $this->ipv6 = $this->formValues($profile['ipv6']);
        $this->resetErrorBag();
        $this->notice = null;
    }

    public function close(): void
    {
        $this->device = null;
        $this->uuid = null;
        $this->editingSaved = false;
        $this->resetErrorBag();
    }

    public function apply(): void
    {
        $this->ensurePasswordIsConfirmed();
        if ($this->device === null || $this->uuid === null) {
            $this->flash('Choose an active network profile first.', true);
            return;
        }
        $rules = [];
        foreach (['ipv4', 'ipv6'] as $family) {
            $methods = $family === 'ipv4' ? 'auto,manual,disabled,link-local' : 'auto,dhcp,manual,disabled,link-local,ignore';
            $rules[$family.'.method'] = 'required|in:'.$methods;
            foreach (['addresses', 'gateway', 'dns', 'routes', 'routing_rules', 'dns_search'] as $field) {
                $rules[$family.'.'.$field] = 'nullable|string|max:4096';
            }
            $rules[$family.'.route_metric'] = 'required|integer|between:-1,4294967295';
            $rules[$family.'.never_default'] = 'required|boolean';
            $rules[$family.'.ignore_auto_dns'] = 'required|boolean';
            $rules[$family.'.ignore_auto_routes'] = 'required|boolean';
            $rules[$family.'.route_table'] = 'required|integer|between:0,4294967295';
        }
        Validator::make(['ipv4' => $this->ipv4, 'ipv6' => $this->ipv6], $rules)->validate();
        $v4 = $this->requestValues($this->ipv4, 'ipv4');
        $v6 = $this->requestValues($this->ipv6, 'ipv6');
        if ($v4 === null || $v6 === null) {
            return;
        }
        $profile = ['ipv4' => $v4, 'ipv6' => $v6];
        $target = $this->device ?: $this->activationDevice;
        $result = $this->editingSaved
            ? app(NetworkChanges::class)->connection($target, $this->uuid, true, $profile)
            : app(NetworkChanges::class)->begin($this->device, $this->uuid, $profile);
        if (!($result['ok'] ?? false)) {
            $this->flash($result['error'] ?? 'The network change could not start.', true);
            return;
        }
        $this->close();
        $this->flash('The network change is temporary. Reconnect if needed and confirm it before the timer ends.');
    }

    public function confirm(): void { $this->finish('confirm'); }
    public function cancel(): void { $this->finish('cancel'); }

    public function activate(string $uuid): void
    {
        $this->ensurePasswordIsConfirmed();
        $reader = app(NetworkProfiles::class);
        foreach ($reader->saved() ?? [] as $profile) {
            if ($profile['uuid'] !== $uuid) continue;
            $device = $profile['device'] ?: $this->activationDevice;
            if (!$profile['device'] && !in_array($device, $reader->ethernetDevices() ?? [], true)) {
                $this->flash('Choose an Ethernet interface for this profile.', true);
                return;
            }
            $this->connectionResult(app(NetworkChanges::class)->connection($device, $uuid, true));
            return;
        }
        $this->flash('The saved profile is no longer available.', true);
    }

    public function deactivate(string $device, string $uuid): void
    {
        $this->ensurePasswordIsConfirmed();
        $this->connectionResult(app(NetworkChanges::class)->connection($device, $uuid, false));
    }

    private function connectionResult(array $result): void
    {
        $this->flash(($result['ok'] ?? false)
            ? 'The connection change is temporary. Check connectivity and confirm before the timer ends.'
            : ($result['error'] ?? 'The connection could not be changed.'), !($result['ok'] ?? false));
    }

    public function createProfile(): void
    {
        $this->ensurePasswordIsConfirmed();
        Validator::make(['device' => $this->newDevice, 'name' => $this->newProfileName], [
            'device' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{1,15}$/'],
            'name' => ['required', 'string', 'max:64'],
        ])->validate();
        $result = app(NetworkChanges::class)->createProfile($this->newDevice, $this->newProfileName);
        $this->flash(($result['ok'] ?? false) ? 'Profile created and left disconnected. Edit it to configure addresses and connect.'
            : ($result['error'] ?? 'Could not create profile.'), !($result['ok'] ?? false));
        if ($result['ok'] ?? false) $this->newProfileName = '';
    }

    public function deleteProfile(string $uuid): void
    {
        $this->ensurePasswordIsConfirmed();
        $result = app(NetworkChanges::class)->deleteProfile($uuid);
        $this->flash(($result['ok'] ?? false) ? 'Saved profile deleted.'
            : ($result['error'] ?? 'Could not delete profile.'), !($result['ok'] ?? false));
    }

    public function moveStand(): void
    {
        $this->ensurePasswordIsConfirmed();
        $reader = app(NetworkProfiles::class);
        $profiles = $reader->active();
        $source = $reader->stand($profiles);
        $target = null;
        foreach ($profiles ?? [] as $profile) {
            if ($profile['uuid'] === $this->standTarget && $profile['device'] !== ($source['device'] ?? null)) {
                $target = $profile;
                break;
            }
        }
        if ($source === null || $target === null) {
            $this->flash('Choose an active target interface; the current stand must have one primary or secondary address.', true);
            return;
        }
        $result = app(NetworkChanges::class)->moveStand($source, $target);
        if (!($result['ok'] ?? false)) {
            $this->flash($result['error'] ?? 'The provisioning network could not be moved.', true);
            return;
        }
        $this->standTarget = '';
        $this->flash('The stand network moved temporarily. Verify connectivity and confirm before the timer ends.');
    }

    private function finish(string $action): void
    {
        $this->ensurePasswordIsConfirmed();
        $service = app(NetworkChanges::class);
        $status = $service->status();
        $token = $status['result']['token'] ?? null;
        if (!($status['ok'] ?? false) || !is_string($token)) {
            $this->flash($status['error'] ?? 'The change is no longer waiting for confirmation.', true);
            return;
        }
        $result = $action === 'confirm' ? $service->confirm($token) : $service->cancel($token);
        $this->flash(($result['ok'] ?? false) ? ($action === 'confirm' ? 'Network settings confirmed.' : 'Network settings restored.')
            : ($result['error'] ?? 'The network change could not be completed.'), !($result['ok'] ?? false));
    }

    private function formValues(array $profile): array
    {
        return [
            'method' => $profile['method'], 'addresses' => str_replace(',', "\n", $profile['addresses']),
            'gateway' => $profile['gateway'], 'dns' => str_replace(',', "\n", $profile['dns']),
            'routes' => str_replace(',', "\n", $profile['routes']),
            'route_metric' => $profile['route-metric'] === '' ? '-1' : $profile['route-metric'],
            'never_default' => $profile['never-default'] === 'yes', 'ignore_auto_dns' => $profile['ignore-auto-dns'] === 'yes',
            'ignore_auto_routes' => ($profile['ignore-auto-routes'] ?? 'no') === 'yes',
            'route_table' => ($profile['route-table'] ?? '') === '' ? '0' : $profile['route-table'],
            'routing_rules' => str_replace(',', "\n", $profile['routing-rules'] ?? ''),
            'dns_search' => str_replace(',', "\n", $profile['dns-search'] ?? ''),
        ];
    }

    private function requestValues(array $values, string $family): ?array
    {
        try {
            $routes = NetworkRoutingInput::routes($values['routes']);
        } catch (\InvalidArgumentException $e) {
            $this->addError($family.'.routes', $e->getMessage());
            return null;
        }
        try {
            $routingRules = NetworkRoutingInput::rules($values['routing_rules']);
        } catch (\InvalidArgumentException $e) {
            $this->addError($family.'.routing_rules', $e->getMessage());
            return null;
        }
        return [
            'method' => $values['method'], 'addresses' => $this->lines($values['addresses']),
            'gateway' => trim((string) $values['gateway']), 'dns' => $this->lines($values['dns']), 'routes' => $routes,
            'route_metric' => (int) $values['route_metric'], 'never_default' => (bool) $values['never_default'],
            'ignore_auto_dns' => (bool) $values['ignore_auto_dns'],
            'ignore_auto_routes' => (bool) $values['ignore_auto_routes'], 'route_table' => (int) $values['route_table'],
            'routing_rules' => $routingRules, 'dns_search' => $this->lines($values['dns_search']),
        ];
    }

    private function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $text)), fn ($v) => $v !== ''));
    }

    private function flash(string $text, bool $error = false): void
    {
        $this->notice = $text;
        $this->noticeIsError = $error;
    }
}
