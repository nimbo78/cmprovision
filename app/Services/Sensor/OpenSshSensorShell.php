<?php

namespace App\Services\Sensor;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/* No shell command construction on the server. A known_hosts file belongs to one checked operation,
   so a newly flashed device may have a new key, but it cannot change between Check and Wipe. */
class OpenSshSensorShell implements SensorShell
{
    public function __construct(private ProvisionerKey $key, private int $port = 22) {}

    public function key(string $address, string $operation, string $command): ShellResult
    {
        $this->key->ensure();
        return $this->run($address, $operation, 'root', ['-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-i', $this->key->path()], $command);
    }

    public function account(string $address, string $operation, string $user, string $password, string $command): ShellResult
    {
        return $this->run($address, $operation, $user, $this->passwordOptions(), $command, $password);
    }

    public function changePassword(string $address, string $operation, string $user, string $password, string $new): ShellResult
    {
        return $this->run($address, $operation, $user, array_merge(['-tt'], $this->passwordOptions()), 'true', $password, $new);
    }

    public function forget(string $operation): void
    {
        $path = $this->directory($operation);
        if (is_link($path)) @unlink($path);
        else File::deleteDirectory($path);
    }

    private function directory(string $operation): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $operation))
            throw new \InvalidArgumentException('Invalid SSH operation.');
        return storage_path('app/ssh/operations/'.$operation);
    }

    private function passwordOptions(): array
    {
        return ['-o', 'PubkeyAuthentication=no', '-o', 'PreferredAuthentications=password,keyboard-interactive', '-o', 'NumberOfPasswordPrompts=1'];
    }

    private function run(string $address, string $operation, string $user, array $options, string $command,
                         string $password = '', ?string $new = null): ShellResult
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $user))
            return new ShellResult('error', 'Invalid SSH destination.');
        $dir = $this->directory($operation);
        if (is_link($dir) || is_link(dirname($dir))) return new ShellResult('error', 'Invalid SSH operation directory.');
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        chmod($dir, 0700);
        $argv = array_merge(['ssh', '-F', '/dev/null', '-p', (string) $this->port,
            '-o', 'ConnectTimeout=5', '-o', 'ConnectionAttempts=1', '-o', 'LogLevel=ERROR',
            '-o', 'StrictHostKeyChecking=accept-new', '-o', 'UserKnownHostsFile='.$dir.'/known_hosts',
            '-o', 'GlobalKnownHostsFile=/dev/null', '-o', 'ClearAllForwardings=yes', '-o', 'ControlMaster=no'],
            $options, ['--', $user.'@'.$address, $command]);
        $input = $new === null ? ($password !== '' ? $password."\n" : null) : new InputStream;
        $process = new Process($argv, null, ['SSH_ASKPASS' => base_path('tools/sensor-askpass'),
            'SSH_ASKPASS_REQUIRE' => 'force', 'CMPROVISION_SSH_PASSWORD' => $password, 'LC_ALL' => 'C'], $input, $new === null ? 15 : 60);
        $output = ''; $pending = ''; $answers = 0;
        try {
            $process->run(function ($type, $data) use (&$output, &$pending, &$answers, $input, $password, $new) {
                if (strlen($output) + strlen($data) > 65536) throw new \RuntimeException('SSH output limit.');
                $output .= $data;
                if (!$input instanceof InputStream) return;
                $pending .= $data;
                if (preg_match('/(?:current|old|\(current\).*UNIX).*password:\s*$/i', $pending))
                    $answer = $password;
                elseif (preg_match('/(?:retype|repeat|new).*password:\s*$/i', $pending))
                    $answer = $new;
                else return;
                if (++$answers > 4) { $input->close(); return; }
                $input->write($answer."\n");
                $pending = '';
            });
            $code = $process->getExitCode() ?? 255;
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            $code = 124;
        } catch (\Throwable $e) {
            $process->stop(0);
            return new ShellResult('error', 'Could not run the SSH client.');
        } finally {
            if ($input instanceof InputStream && !$input->isClosed()) $input->close();
        }
        // PAM usually disconnects after changing an expired password; verify by logging in again.
        if ($new !== null && preg_match('/password updated successfully|password changed/i', $output)) {
            $verified = $this->account($address, $operation, $user, $new, 'true');
            return $verified->status === 'ok' ? new ShellResult('ok', 'Password changed.') : $verified;
        }
        if ($new !== null && $code === 0) $code = 1;
        return ShellResult::from($code, $output, array_filter([$password, $new ?? '']));
    }
}
