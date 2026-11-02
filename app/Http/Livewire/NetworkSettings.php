<?php

namespace App\Http\Livewire;

use App\Services\NetworkChanges;
use App\Services\NetworkProfiles;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NetworkSettings extends Component
{
    use ConfirmsPasswords;

    #[Locked]
    public $device = null, $uuid = null;
    public $ipv4 = [], $ipv6 = [];
    public $standTarget = '';
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
            'stand' => $available ? app(NetworkProfiles::class)->stand($profiles) : null,
            'pending' => $pending,
            'statusError' => $status !== null && !($status['ok'] ?? false) ? ($status['error'] ?? 'Network status is unavailable.') : null,
        ]);
    }

    public function edit(string $device, string $uuid): void
    {
        $profile = app(NetworkProfiles::class)->detail($device, $uuid);
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
        $this->ipv4 = $this->formValues($profile['ipv4']);
        $this->ipv6 = $this->formValues($profile['ipv6']);
        $this->resetErrorBag();
        $this->notice = null;
    }

    public function close(): void
    {
        $this->device = null;
        $this->uuid = null;
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
            foreach (['addresses', 'gateway', 'dns', 'routes'] as $field) {
                $rules[$family.'.'.$field] = 'nullable|string|max:4096';
            }
            $rules[$family.'.route_metric'] = 'required|integer|between:-1,4294967295';
            $rules[$family.'.never_default'] = 'required|boolean';
            $rules[$family.'.ignore_auto_dns'] = 'required|boolean';
        }
        Validator::make(['ipv4' => $this->ipv4, 'ipv6' => $this->ipv6], $rules)->validate();
        $v4 = $this->requestValues($this->ipv4, 'ipv4');
        $v6 = $this->requestValues($this->ipv6, 'ipv6');
        if ($v4 === null || $v6 === null) {
            return;
        }
        $result = app(NetworkChanges::class)->begin($this->device, $this->uuid, ['ipv4' => $v4, 'ipv6' => $v6]);
        if (!($result['ok'] ?? false)) {
            $this->flash($result['error'] ?? 'The network change could not start.', true);
            return;
        }
        $this->close();
        $this->flash('The network change is temporary. Reconnect if needed and confirm it before the timer ends.');
    }

    public function confirm(): void { $this->finish('confirm'); }
    public function cancel(): void { $this->finish('cancel'); }

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
        ];
    }

    private function requestValues(array $values, string $family): ?array
    {
        $routes = [];
        foreach ($this->lines($values['routes']) as $line) {
            $parts = preg_split('/\s+/', $line);
            // Accept both "prefix gateway metric" and the familiar "prefix via gateway metric N".
            $parts = array_values(array_filter($parts, fn ($part) => $part !== 'via' && $part !== 'metric'));
            if (count($parts) < 1 || count($parts) > 3 || (isset($parts[2]) && !ctype_digit($parts[2]))) {
                $this->addError($family.'.routes', 'Use one route per line: prefix, optional gateway and metric.');
                return null;
            }
            $route = ['destination' => $parts[0]];
            if (isset($parts[1]) && count($parts) === 2 && ctype_digit($parts[1])) {
                $route['metric'] = (int) $parts[1];
            } else {
                if (isset($parts[1])) $route['via'] = $parts[1];
                if (isset($parts[2])) $route['metric'] = (int) $parts[2];
            }
            $routes[] = $route;
        }
        return [
            'method' => $values['method'], 'addresses' => $this->lines($values['addresses']),
            'gateway' => trim((string) $values['gateway']), 'dns' => $this->lines($values['dns']), 'routes' => $routes,
            'route_metric' => (int) $values['route_metric'], 'never_default' => (bool) $values['never_default'],
            'ignore_auto_dns' => (bool) $values['ignore_auto_dns'],
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
