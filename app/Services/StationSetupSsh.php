<?php

namespace App\Services;

use App\Services\Sensor\ProvisionerKey;
use Symfony\Component\Process\Process;

/** A temporary forced-command SSH server runs inside the newly written station root. */
class StationSetupSsh
{
    public function __construct(private ProvisionerKey $key, private int $port = 2224) {}

    public function deliver(string $address, string $hostKey, array $identity, array $settings): void
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !self::hostKey($hostKey))
            throw new \RuntimeException('Invalid station setup destination.');
        $dir = storage_path('app/ssh/station-'.bin2hex(random_bytes(16)));
        if (!is_dir(dirname($dir))) mkdir(dirname($dir), 0700, true);
        if (is_link(dirname($dir)) || !mkdir($dir, 0700)) throw new \RuntimeException('Cannot prepare station SSH verification.');
        try {
            file_put_contents($dir.'/known_hosts', '['.$address.']:'.$this->port.' '.$hostKey."\n");
            chmod($dir.'/known_hosts', 0600);
            $this->key->ensure();
            $argv = ['ssh', '-F', '/dev/null', '-p', (string) $this->port, '-i', $this->key->path(),
                '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'ConnectTimeout=5',
                '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile='.$dir.'/known_hosts',
                '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'ClearAllForwardings=yes', '-o', 'ControlMaster=no',
                '-o', 'LogLevel=ERROR', '--', 'root@'.$address];
            $actual = json_decode($this->execute([...$argv, 'identity'], null), true);
            if ($actual !== $identity) throw new \RuntimeException('Station identity does not match this provisioning run.');
            // No credentials in arguments, shell text, HTTP responses or process logs.
            $result = $this->execute([...$argv, 'apply'], json_encode(['identity' => $identity, 'settings' => $settings], JSON_THROW_ON_ERROR));
            if (trim($result) !== 'applied') throw new \RuntimeException('Station setup did not confirm application.');
        } finally {
            @unlink($dir.'/known_hosts');
            @rmdir($dir);
        }
    }

    public static function hostKey(string $key): bool
    {
        if (!preg_match('/^ssh-ed25519 ([A-Za-z0-9+\/=]{68})$/D', $key, $parts)) return false;
        $blob = base64_decode($parts[1], true);
        return $blob !== false && strlen($blob) === 51 && substr($blob, 0, 19) === pack('N', 11).'ssh-ed25519'.pack('N', 32);
    }

    private function execute(array $argv, ?string $input): string
    {
        $process = new Process($argv, null, ['LC_ALL' => 'C'], $input, 30);
        try {
            $output = '';
            $process->run(function ($type, $chunk) use (&$output) {
                if ($type === Process::OUT) $output .= $chunk;
                if (strlen($output) > 4096) throw new \RuntimeException('Station response exceeded its limit.');
            });
            if (!$process->isSuccessful()) throw new \RuntimeException('Station SSH setup failed.');
            return $output;
        } catch (\Throwable $e) {
            $process->stop(0);
            throw new \RuntimeException('Station SSH setup failed; check its connection and image compatibility.');
        }
    }
}
