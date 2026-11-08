<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Saved project settings never contain the clear-text Linux password. */
class StationSetupInput
{
    /** Validate a portable saved definition; password hashes are retained rather than hashed as passwords. */
    public static function saved(array $saved): array
    {
        $generic = ($saved['schema'] ?? null) === 2;
        Validator::make($saved, [
            'schema' => 'required|integer|in:1,2', 'hostname' => ($generic ? 'present|nullable' : 'required').'|string|max:63', 'username' => 'required|string|max:32',
            'password_hash' => ['nullable', 'string', 'max:255', 'regex:/^\$6\$(?:rounds=100000\$)?[A-Za-z0-9.\/]{1,16}\$[A-Za-z0-9.\/]{86}$/D'],
            'keys' => 'present|array|max:10', 'keys.*' => 'string|max:16384', 'ssh' => 'required|boolean',
            'wifi' => 'present|nullable|array:ssid,security,password,country,hidden',
            'wifi.ssid' => 'required_with:wifi|string|max:32', 'wifi.security' => 'required_with:wifi|in:wpa2,wpa3,mixed',
            'wifi.password' => 'required_with:wifi|string|max:64', 'wifi.country' => ['required_with:wifi', 'regex:/^[A-Z]{2}$/D'],
            'wifi.hidden' => 'required_with:wifi|boolean',
            'profile' => $generic ? 'required|in:raspios' : 'prohibited',
            'source_username' => $generic ? 'present|nullable|string|max:32' : 'prohibited',
            'sudo_passwordless' => $generic ? 'required|boolean' : 'prohibited',
        ])->validate();
        $allowed = ['schema', 'hostname', 'username', 'password_hash', 'keys', 'ssh', 'wifi'];
        if ($generic) $allowed = [...$allowed, 'profile', 'source_username', 'sudo_passwordless'];
        if (array_diff(array_keys($saved), $allowed)) {
            throw ValidationException::withMessages(['station_setup' => 'The station definition contains an unsupported field.']);
        }
        return self::normalize(self::form($saved), $saved);
    }

    public static function normalize(array $input, ?array $saved = null): array
    {
        $generic = ($input['profile'] ?? 'station') === 'raspios';
        $data = Validator::make($input, [
            'profile' => 'sometimes|required|string|in:station,raspios',
            'source_username' => ['nullable', 'string', 'max:32', 'regex:/^[a-z][a-z0-9-]*$/D', 'not_in:root,nobody,daemon,bin,sys,www-data,sshd'],
            'sudo_passwordless' => 'sometimes|boolean',
            'hostname' => [$generic ? 'nullable' : 'required', 'string', 'max:63', function ($attribute, $value, $fail) {
                $expanded = str_replace('{serial}', '0123456789abcdef', $value);
                if (strlen($expanded) > 63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $expanded))
                    $fail('Use a Linux host name, optionally including {serial}.');
            }],
            'username' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9-]*$/D',
                $generic ? 'not_in:root,nobody,daemon,bin,sys,www-data,sshd' : 'not_in:root,nobody'],
            'password' => 'nullable|string|min:8|max:128',
            'password_login' => 'required|boolean',
            'keys' => 'nullable|string|max:16384',
            'ssh' => 'required|boolean',
            'wifi' => 'required|boolean',
            'ssid' => 'nullable|string|max:32',
            'security' => 'required|in:wpa2,wpa3,mixed',
            'wifi_password' => 'nullable|string|max:64',
            'country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/D'],
            'hidden' => 'required|boolean',
        ])->validate();
        $fail = fn ($field, $message) => throw ValidationException::withMessages([$field => $message]);
        if ($generic && !$data['ssh']) $fail('ssh', 'Generic OS access requires SSH to be enabled.');
        if ($generic && $data['wifi']) $fail('wifi', 'Use the cmprovision station profile to configure Wi-Fi.');
        if ($generic && !$data['password_login'] && empty($data['sudo_passwordless'])) {
            $fail('sudo_passwordless', 'Key-only administrators require sudo without a password.');
        }
        $password = (string) ($data['password'] ?? '');
        if (preg_match('/[\x00-\x1f\x7f]/', $password)) $fail('password', 'The password must not contain control characters.');
        $hash = $data['password_login'] ? ($saved['password_hash'] ?? null) : null;
        if ($data['password_login'] && $password !== '') {
            $hash = crypt($password, '$6$rounds=100000$'.bin2hex(random_bytes(8)).'$');
            if (!str_starts_with($hash, '$6$')) throw new \RuntimeException('Password hashing is unavailable.');
        }
        $keys = [];
        foreach (preg_split('/\r?\n/', trim($data['keys'] ?? '')) as $line) {
            if (trim($line) === '') continue;
            $parts = preg_split('/\s+/', trim($line), 3);
            $blob = base64_decode($parts[1] ?? '', true);
            $length = $blob !== false && strlen($blob) >= 4 ? unpack('N', substr($blob, 0, 4))[1] : 0;
            if (!in_array($parts[0], ['ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521'], true)
                || !$blob || $length > 64 || substr($blob, 4, $length) !== $parts[0] || strlen($blob) < $length + 12)
                $fail('keys', 'Enter public SSH keys, one per line, without authorized_keys options.');
            $keys[] = $parts[0].' '.$parts[1]; // comments are not needed on every station
        }
        $keys = array_values(array_unique($keys));
        if (count($keys) > 10) $fail('keys', 'Use at most ten public SSH keys.');
        foreach ($keys as $key) {
            $check = new \Symfony\Component\Process\Process(['ssh-keygen', '-l', '-f', '/dev/stdin'], null, null, $key."\n", 5);
            $check->run();
            if (!$check->isSuccessful()) $fail('keys', 'One or more SSH public keys are invalid.');
        }
        if ($data['password_login'] && !$hash) $fail('password', 'Enter the initial Linux password.');
        if (!$hash && !$keys) $fail('keys', 'Add at least one public SSH key for key-only access.');
        if (!$hash && !$data['ssh']) $fail('ssh', 'Key-only access requires SSH to be enabled.');
        $wifi = null;
        if ($data['wifi']) {
            $ssid = (string) ($data['ssid'] ?? '');
            if (strlen($ssid) < 1 || strlen($ssid) > 32 || preg_match('/[\x00-\x1f\x7f]/', $ssid))
                $fail('ssid', 'Use an SSID of 1 to 32 bytes without control characters.');
            $psk = (string) ($data['wifi_password'] ?? '');
            if ($psk === '' && ($saved['wifi']['ssid'] ?? null) === $ssid) $psk = $saved['wifi']['password'] ?? '';
            $hex = $data['security'] !== 'wpa3' && preg_match('/^[0-9a-fA-F]{64}$/D', $psk);
            if (!$hex && (strlen($psk) < 8 || strlen($psk) > 63 || preg_match('/[\x00-\x1f\x7f]/', $psk)))
                $fail('wifi_password', 'Enter a Wi-Fi passphrase of 8 to 63 bytes. WPA2 also accepts a 64-digit hexadecimal key.');
            if (empty($data['country'])) $fail('country', 'Set the two-letter Wi-Fi country code.');
            $wifi = ['ssid' => $ssid, 'security' => $data['security'], 'password' => $psk,
                'country' => $data['country'], 'hidden' => (bool) $data['hidden']];
        }
        $settings = ['schema' => $generic ? 2 : 1, 'hostname' => (string) ($data['hostname'] ?? ''), 'username' => $data['username'],
            'password_hash' => $hash, 'keys' => $keys, 'ssh' => (bool) $data['ssh'], 'wifi' => $wifi];
        if ($generic) $settings += ['profile' => 'raspios', 'source_username' => (string) ($data['source_username'] ?? ''),
            'sudo_passwordless' => (bool) ($data['sudo_passwordless'] ?? false)];
        return $settings;
    }

    public static function form(?array $saved = null): array
    {
        return ['profile' => $saved['profile'] ?? 'station', 'source_username' => $saved['source_username'] ?? '',
            'sudo_passwordless' => $saved['sudo_passwordless'] ?? false,
            'hostname' => $saved['hostname'] ?? 'cmprovision-{serial}', 'username' => $saved['username'] ?? '',
            'password' => '', 'password_login' => $saved === null || !empty($saved['password_hash']),
            'keys' => implode("\n", $saved['keys'] ?? []), 'ssh' => $saved['ssh'] ?? true,
            'wifi' => !empty($saved['wifi']), 'ssid' => $saved['wifi']['ssid'] ?? '',
            'security' => $saved['wifi']['security'] ?? 'wpa2', 'wifi_password' => '',
            'country' => $saved['wifi']['country'] ?? '', 'hidden' => $saved['wifi']['hidden'] ?? false];
    }
}
