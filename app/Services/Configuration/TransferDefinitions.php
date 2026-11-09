<?php

namespace App\Services\Configuration;

use App\Models\NotificationChannel;
use App\Services\EepromImage;
use App\Services\NetBoxExport;
use App\Services\NetworkProfiles;
use App\Services\Sensor\SavedAccounts;
use App\Services\Snmp\PhpSnmpClient;
use App\Services\SwitchPortFinder;
use App\Services\TlsTrust;
use App\Support\FpmsPanelSettings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Pure configuration checks: no activation, notifications, printer access or network changes. */
class TransferDefinitions
{
    public function normalize(string $type, #[\SensitiveParameter] array $data, array $objects = []): array
    {
        if ($type === 'script') return Definitions::script($data);
        if ($type === 'label') {
            foreach (['ftp_hostname', 'ftp_username', 'ftp_password', 'print_command'] as $field) $data[$field] = (string) ($data[$field] ?? '');
            return Definitions::label($data);
        }
        if ($type === 'project') return Definitions::projectDefinition($data, $objects);
        if ($type === 'settings') return $this->settings($data);
        if ($type === 'notification') return $this->notification($data);
        if ($type === 'switch') return $this->ethernetSwitch($data);
        if ($type === 'host') {
            Validator::make($data, ['ip' => 'required|ipv4', 'mac' => 'required|regex:/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/D',
                'hostname' => 'nullable|string|max:63|regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/D'])->validate();
            return ['mac' => strtolower($data['mac']), 'ip' => $data['ip'], 'hostname' => ($data['hostname'] ?? '') ?: null];
        }
        if ($type === 'network') {
            $rules = [];
            foreach (['ipv4', 'ipv6'] as $family) {
                foreach (NetworkProfiles::PROPERTIES as $property) $rules[$family.'.'.$property] = 'present|nullable|string|max:4096';
                $rules[$family.'.method'] = 'required|in:'.($family === 'ipv4' ? 'auto,manual,disabled,link-local' : 'auto,dhcp,manual,disabled,link-local,ignore');
            }
            Validator::make($data, $rules)->validate();
            if ($data['type'] === 'vlan' && (empty($data['parent']) || empty($data['vlan_id']))) {
                throw ValidationException::withMessages(['interface' => 'A VLAN draft needs its source parent and VLAN ID.']);
            }
            $data['draft'] = true;
            return $data;
        }
        return $data; // Binary definitions were checked by the catalog and the staged-file verifier.
    }

    private function settings(array $data): array
    {
        $values = $data['values'];
        $rules = match ($data['group']) {
            'updates' => ['application' => 'required|boolean', 'utility-os' => 'required|boolean'],
            'netbox' => array_fill_keys(array_keys(NetBoxExport::DEFAULTS), 'required|string|max:100|not_regex:/[\x00-\x1f]/'),
            'display' => [
                'fpms' => 'required|array:theme,ports_map,color_mode', 'fpms.theme' => ['required', Rule::in(FpmsPanelSettings::THEMES)],
                'fpms.ports_map' => ['required', Rule::in(FpmsPanelSettings::PORTS_MAPS)],
                'fpms.color_mode' => ['required', Rule::in(FpmsPanelSettings::COLOR_MODES)],
                'wallboard' => 'required|array:mode,palette,view,rotation', 'wallboard.mode' => 'required|in:off,auto,on',
                'wallboard.palette' => 'required|in:warm,warm-light,neutral,neutral-light',
                'wallboard.view' => 'required|in:auto,desk,flow,engineer', 'wallboard.rotation' => 'required|integer|between:5,60',
                'fpms_service' => 'required|array:enabled,open_on_start', 'fpms_service.enabled' => 'required|boolean',
                'fpms_service.open_on_start' => 'required|boolean', 'hdmi_auto' => 'required|boolean',
            ],
            'usb_prep' => ['firmware' => 'required|string|regex:/^firmware-[1-9][0-9]{0,4}$/D', 'settings' => 'required|string|max:2024'],
            'sensor_accounts' => ['accounts' => 'present|array|max:10', 'accounts.*' => 'required|array:name,user,password,enabled',
                'accounts.*.name' => 'required|string|max:80|not_regex:/[\r\n\x00]/',
                'accounts.*.user' => 'required|string|regex:/^[a-z_][a-z0-9_-]{0,31}$/D',
                'accounts.*.password' => 'required|string|max:1024|not_regex:/[\r\n\x00]/', 'accounts.*.enabled' => 'required|boolean'],
            'mark_key' => ['key' => 'required|string|regex:/^[0-9a-f]{64}$/D'],
        };
        if ($data['group'] === 'netbox') {
            $rules['status'] = ['required', Rule::in(NetBoxExport::STATUSES)];
            $rules['mac_style'] = ['required', Rule::in(array_keys(NetBoxExport::MAC_STYLES))];
        }
        $allowed = array_values(array_filter(array_keys($rules), fn ($key) => !str_contains($key, '.')));
        if (array_diff(array_keys($values), $allowed)) throw ValidationException::withMessages(['settings' => 'The settings group contains an unsupported value.']);
        $values = Validator::make($values, $rules)->validate();
        if ($data['group'] === 'sensor_accounts') {
            if (!array_is_list($values['accounts']) || count(array_filter($values['accounts'], fn ($account) => $account['enabled'])) > SavedAccounts::MAX_ENABLED) {
                throw ValidationException::withMessages(['accounts' => 'Keep at most ten accounts and enable at most three.']);
            }
            foreach ($values['accounts'] as &$account) {
                if (strlen($account['password']) > 1024) throw ValidationException::withMessages(['accounts' => 'A sensor password exceeds 1024 bytes.']);
                $account['enabled'] = (bool) $account['enabled'];
            }
            unset($account);
        }
        if ($data['group'] === 'usb_prep') $values['settings'] = EepromImage::normalizeConfig($values['settings']);
        return ['group' => $data['group'], 'values' => $values];
    }

    private function notification(array $data): array
    {
        Validator::make($data, ['name' => 'required|string|max:100'])->validate();
        $tls = in_array($data['type'], NotificationChannel::TLS_TYPES, true) ? ['tls_ca', 'tls_ca_for', 'tls_insecure'] : [];
        $fields = match ($data['type']) {
            'telegram' => ['bot_token', 'chat_id', 'topic_id'],
            'mattermost_bot' => ['server_url', 'token', 'channel', 'channel_id'],
            default => ['url'],
        };
        if (array_diff(array_keys($data['settings']), array_merge($fields, $tls))) {
            throw ValidationException::withMessages(['settings' => 'This notification type has an unsupported setting.']);
        }
        $rules = ['tls_ca' => 'nullable|string|max:20000', 'tls_ca_for' => 'nullable|string|max:500', 'tls_insecure' => 'sometimes|boolean'];
        if ($data['type'] === 'telegram') {
            $rules += ['bot_token' => 'required|string|regex:/^\d+:[A-Za-z0-9_-]+$/D', 'chat_id' => 'required|string|regex:/^(-?\d+|@\w{4,})$/D', 'topic_id' => 'nullable|integer|min:1'];
        } elseif ($data['type'] === 'mattermost_bot') {
            $rules += ['server_url' => 'required|url|max:200', 'token' => 'required|string|max:200',
                'channel' => 'required|string|max:300', 'channel_id' => 'required|string|max:100'];
        } else $rules['url'] = 'required|url|max:500';
        $settings = Validator::make($data['settings'], $rules)->validate();
        if (isset($settings['tls_ca']) && trim($settings['tls_ca']) !== '') {
            $certificate = TlsTrust::normalize($settings['tls_ca']);
            $address = $settings[$data['type'] === 'mattermost_bot' ? 'server_url' : 'url'] ?? '';
            if ($certificate === null || TlsTrust::addressKey($address) === null || ($settings['tls_ca_for'] ?? null) !== TlsTrust::addressKey($address)) {
                throw ValidationException::withMessages(['settings.tls_ca' => 'The certificate is invalid or belongs to another notification server.']);
            }
            $settings['tls_ca'] = $certificate;
        }
        $data['enabled'] = (bool) $data['enabled'];
        $data['settings'] = $settings;
        return $data;
    }

    private function ethernetSwitch(array $data): array
    {
        $rules = ['host' => 'required|string|max:255|regex:/^[A-Za-z0-9.:_-]+$/D', 'version' => 'required|in:2c,3',
            'method' => ['required', Rule::in(array_merge(['auto'], array_keys(SwitchPortFinder::METHODS)))]];
        foreach (['', 'write.'] as $prefix) {
            foreach (['community', 'user', 'auth_password', 'priv_password'] as $key) $rules[$prefix.$key] = 'nullable|string|max:100';
            $rules[$prefix.'auth_protocol'] = ['nullable', Rule::in(PhpSnmpClient::authProtocols())];
            $rules[$prefix.'priv_protocol'] = ['nullable', Rule::in(PhpSnmpClient::PRIV_PROTOCOLS)];
        }
        if ($data['version'] === '2c') $rules['community'] = 'required|string|max:100';
        else {
            $rules['user'] = 'required|string|max:100';
            foreach (['', 'write.'] as $prefix) {
                $values = $prefix === '' ? $data : ($data['write'] ?? []);
                if ($prefix !== '' && empty($values['user'])) continue;
                foreach (['auth', 'priv'] as $kind) if (!empty($values[$kind.'_protocol'])) $rules[$prefix.$kind.'_password'] = 'required|string|min:8|max:100';
            }
        }
        Validator::make($data, $rules)->validate();
        return $data;
    }
}
