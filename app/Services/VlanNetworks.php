<?php

namespace App\Services;

/** The root helper accepts a command from the sudo allowlist and a JSON request on stdin. */
class VlanNetworks
{
    const HELPER = '/usr/lib/cmprovision/vlan-networks';
    private $run;
    private $helper;

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

    public function available(): bool
    {
        if (!is_file($this->helper)) {
            return false;
        }
        [$code, $output] = call_user_func($this->run, ['timeout', '3', 'nmcli', '-t', '-f', 'TYPE,STATE', 'device'], '');
        foreach ($code === 0 ? explode("\n", trim($output)) : [] as $line) {
            [$type, $state] = array_pad(explode(':', $line, 2), 2, '');
            if ($type === 'ethernet' && $state !== 'unmanaged') {
                return true;
            }
        }

        return false;
    }

    public function list(): array
    {
        return $this->call('list', (object) []);
    }

    public function add(string $parent, int $id, string $address): array
    {
        return $this->call('add', ['parent' => $parent, 'vlan_id' => $id, 'address' => $address]);
    }

    public function remove(string $uuid): array
    {
        return $this->call('remove', ['uuid' => $uuid]);
    }

    private function call(string $operation, $input): array
    {
        [$code, $output] = call_user_func($this->run,
            ['timeout', '45', 'sudo', '-n', $this->helper, $operation], json_encode($input));
        $decoded = json_decode($output, true);
        if (!is_array($decoded) || !isset($decoded['ok']) || ($decoded['ok'] && $code !== 0)) {
            return ['ok' => false, 'error' => $code === 124 ? 'The VLAN helper timed out.' : 'The VLAN helper could not run.'];
        }

        return $decoded;
    }
}
