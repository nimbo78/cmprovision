<?php

namespace App\Services\Sensor;

final readonly class ShellResult
{
    public function __construct(public string $status, public string $output = '', public int $code = 0,
                                public ?array $identity = null, public ?string $wipedSerial = null) {}

    public static function from(int $code, string $output, array $secrets = []): self
    {
        // Parse the bounded, validated protocol before redaction: a password can equal a public hostname or serial.
        $identity = $code === 0 ? ReprovisionAgent::parse($output) : null;
        $wiped = $code === 0 && preg_match('/^CMPROVISION_WIPED=([a-f0-9]{16})\s*$/D', trim($output), $match) ? $match[1] : null;
        $status = match (true) {
            $code === 0 => 'ok',
            (bool) preg_match('/password.*expired|change your password|password change required/i', $output) => 'password_expired',
            (bool) preg_match('/REMOTE HOST IDENTIFICATION HAS CHANGED|Host key verification failed/i', $output) => 'host_changed',
            (bool) preg_match('/Permission denied|Authentication failed/i', $output) => 'denied',
            $code === 124 || (bool) preg_match('/timed out|No route to host|Connection refused|Network is unreachable/i', $output) => 'unreachable',
            default => 'error',
        };
        foreach ($secrets as $secret)
            if ($secret !== '') $output = str_replace($secret, '[redacted]', $output);
        $output = substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $output), 0, 4000);
        return new self($status, $output, $code, $identity, $wiped);
    }

    public function acknowledges(string $serial): bool
    {
        return $this->status === 'ok' && ($this->wipedSerial === $serial || trim($this->output) === 'CMPROVISION_WIPED='.$serial);
    }
}
