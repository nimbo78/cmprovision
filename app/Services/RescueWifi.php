<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/** Root-owned configuration; only an explicitly confirmed reveal returns a secret. */
class RescueWifi
{
    const HELPER = '/usr/lib/cmprovision/rescue-wifi';
    const STATUS = '/run/cmprovision/rescue-wifi.json';
    const PROFILE_UUID = 'f5e6b717-a82b-4b52-9a8e-b60937c5d725';
    const DEFAULTS = ['enabled' => false, 'delay' => 600, 'ssid' => '', 'ap_mac' => '', 'client_mac' => '',
        'subnet' => '', 'channel' => 6, 'management_profiles' => [], 'password_configured' => false];
    const STATES = ['off', 'standby', 'waiting', 'starting', 'active', 'recovery', 'stopping', 'paused', 'fault', 'unavailable'];

    private $run;
    private $path;

    public function __construct(?callable $run = null, string $path = self::STATUS)
    {
        $this->path = $path;
        $this->run = $run;
    }

    public function available(): bool { return $this->run !== null || is_file(self::HELPER); }

    private function call(string $action, array $input = []): array
    {
        if (!in_array($action, ['status', 'configure', 'start', 'pause', 'resume', 'restart', 'reveal'], true))
            throw new \RuntimeException('Unknown rescue Wi-Fi action.');
        $command = ['sudo', '-n', self::HELPER, $action];
        $body = json_encode($input, JSON_THROW_ON_ERROR);
        if ($this->run) {
            [$code, $output] = ($this->run)($command, $body);
        } else {
            $process = new Process($command);
            $process->setInput($body);
            $process->setTimeout($action === 'configure' ? 45 : 15);
            $process->run();
            $code = $process->getExitCode();
            $output = $process->getOutput();
        }
        $data = strlen($output) <= 65536 ? json_decode($output, true) : null;
        if ($code !== 0 || !is_array($data))
            throw new \RuntimeException(is_string($data['error'] ?? null) ? mb_substr($data['error'], 0, 250)
                : 'Rescue Wi-Fi helper is unavailable. Check the package installation.');
        return $data;
    }

    public function metadata(): array
    {
        if (!$this->available()) return ['settings' => self::DEFAULTS, 'radios' => [], 'management_profiles' => [], 'readiness_error' => null];
        $data = $this->call('status');
        $settings = array_intersect_key((array) ($data['settings'] ?? []), self::DEFAULTS) + self::DEFAULTS;
        return ['settings' => $settings, 'radios' => array_slice((array) ($data['radios'] ?? []), 0, 16),
            'management_profiles' => array_slice((array) ($data['management_profiles'] ?? []), 0, 128),
            'readiness_error' => is_string($data['readiness_error'] ?? null) ? mb_substr($data['readiness_error'], 0, 250) : null];
    }

    public function status(): array
    {
        $info = @lstat($this->path);
        if (!$info || ($info['mode'] & 0170000) !== 0100000 || $info['uid'] !== 0 || ($info['mode'] & 0022) || $info['size'] > 65536)
            return [];
        $value = json_decode((string) @file_get_contents($this->path, false, null, 0, 65537), true);
        $status = is_array($value) ? self::sanitizeStatus($value) : [];
        if ($status && (!is_numeric($value['checked_at'] ?? null) || abs(time() - $value['checked_at']) > 90)) {
            $status['state'] = 'unavailable';
            $status['reason'] = 'stale';
        }
        return $status;
    }

    public static function sanitizeStatus(array $data): array
    {
        if (($data['schema'] ?? null) !== 1 || !in_array($data['state'] ?? null, self::STATES, true)) return [];
        return ['enabled' => ($data['enabled'] ?? false) === true, 'active' => ($data['active'] ?? false) === true,
            'state' => $data['state'], 'reason' => is_string($data['reason'] ?? null) ? mb_substr($data['reason'], 0, 80) : '',
            'ssid' => is_string($data['ssid'] ?? null) ? mb_strcut($data['ssid'], 0, 32, 'UTF-8') : '',
            'address' => filter_var($data['address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: null,
            'device' => is_string($data['device'] ?? null) && preg_match('/\A[A-Za-z0-9_.-]{1,15}\z/', $data['device']) ? $data['device'] : null,
            'clients' => is_int($data['clients'] ?? null) && $data['clients'] >= 0 && $data['clients'] <= 512 ? $data['clients'] : null,
            'remaining' => is_int($data['remaining'] ?? null) && $data['remaining'] >= 0 && $data['remaining'] <= 3600 ? $data['remaining'] : null,
            'pending_settings' => ($data['pending_settings'] ?? false) === true];
    }

    public function save(array $settings): void { $this->call('configure', $settings); }
    public function action(string $action): void { $this->call($action); }
    public function reveal(): array { return $this->call('reveal'); }

    public static function label(array $status): string
    {
        return ['off' => 'Disabled', 'standby' => 'Ready', 'waiting' => 'Searching for management Wi-Fi',
            'starting' => 'Starting AP', 'active' => 'AP active', 'recovery' => 'AP active · management restored',
            'stopping' => 'Stopping AP', 'paused' => 'Paused', 'fault' => 'AP failed to start',
            'unavailable' => 'Unavailable'][$status['state'] ?? 'off'] ?? 'Unavailable';
    }

    public static function reason(array $status): string
    {
        return ['management_available' => 'A management connection is available.', 'disabled' => 'Automatic rescue access is disabled.',
            'searching_management' => 'Trying the saved management networks.', 'trying_cold_spare' => 'Trying the built-in radio as a client.',
            'management_unavailable' => 'Connect to the rescue SSID to reach this station and attached sensors.',
            'manual_hold' => 'Manual start: the AP stays available for at least 15 minutes.',
            'clients_connected' => 'The AP stays on until its clients disconnect.', 'clients_unknown' => 'Client count is unknown; keeping the AP available.',
            'waiting_for_stable_management' => 'Waiting for the management connection to stay stable.',
            'waiting_for_clients_to_leave' => 'No clients connected; waiting before switching off.',
            'manual_pause' => 'Automatic rescue access resumes when the pause expires.',
            'observation_unavailable' => 'NetworkManager status is unavailable; existing AP access is preserved.',
            'station_operation_pending' => 'Waiting for the current station operation.', 'wifi_disabled' => 'Wi-Fi is disabled or blocked.',
            'radio_busy' => 'The selected radio is in use.', 'start_failed' => 'Check the interface binding, radio support, routes and NetworkManager journal.',
            'subnet_conflict' => 'The AP stopped because a new route overlaps its subnet. Choose another AP subnet before restarting.',
            'stale' => 'The rescue controller has not reported recently.', 'ap_not_ready' => 'Check the radio selection and provisioning interface binding.'][$status['reason'] ?? ''] ?? '';
    }
}
