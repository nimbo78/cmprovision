<?php

namespace App\Services;

/** Authenticated network edits go through the root transaction controller; JSON is passed on stdin. */
class NetworkChanges
{
    const HELPER = '/usr/lib/cmprovision/network-transaction';
    private $helper;
    private $run;

    public function __construct(?callable $run = null, string $helper = self::HELPER)
    {
        $this->helper = $helper;
        $this->run = $run ?: function (array $command, string $input): array {
            $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($process)) {
                return [127, ''];
            }
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            return [proc_close($process), $output];
        };
    }

    public function available(): bool { return is_file($this->helper); }
    public function status(): array { return $this->call('status', (object) []); }
    public function begin(string $device, string $uuid, array $profile): array
    {
        return $this->call('begin', ['device' => $device, 'uuid' => $uuid, 'profile' => $profile]);
    }
    public function moveStand(array $source, array $target): array
    {
        return $this->call('begin', ['kind' => 'stand', 'source_device' => $source['device'], 'source_uuid' => $source['uuid'],
            'target_device' => $target['device'], 'target_uuid' => $target['uuid']]);
    }
    public function confirm(string $token): array { return $this->call('confirm', ['token' => $token]); }
    public function cancel(string $token): array { return $this->call('cancel', ['token' => $token]); }
    public function connection(string $device, string $uuid, bool $activate, ?array $profile = null): array
    {
        $request = ['kind' => 'connection', 'action' => $activate ? 'activate' : 'deactivate', 'device' => $device, 'uuid' => $uuid];
        if ($profile !== null) $request['profile'] = $profile;
        return $this->call('begin', $request);
    }
    public function createProfile(string $device, string $name): array
    {
        return $this->call('profiles', ['operation' => 'create', 'device' => $device, 'name' => $name]);
    }
    public function deleteProfile(string $uuid): array
    {
        return $this->call('profiles', ['operation' => 'delete', 'uuid' => $uuid]);
    }
    public function roleState(): array { return $this->call('profiles', ['operation' => 'role-state']); }
    public function configureStand(array $profile, string $role, bool $usb, bool $isolated): array
    {
        return $this->call('begin', ['kind' => 'stand-role', 'device' => $profile['device'], 'uuid' => $profile['uuid'],
            'role' => $role, 'usb' => $usb, 'isolated' => $isolated]);
    }

    private function call(string $action, $payload): array
    {
        [$code, $output] = call_user_func($this->run,
            ['timeout', '55', 'sudo', '-n', $this->helper, $action], json_encode($payload));
        $result = json_decode($output, true);
        if (!is_array($result) || !isset($result['ok']) || ($result['ok'] && $code !== 0)) {
            return ['ok' => false, 'error' => $code === 124 ? 'The network controller timed out; check the pending change.'
                : 'The network controller could not run.'];
        }

        return $result;
    }
}
