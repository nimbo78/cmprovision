<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class FirstAdmin
{
    private const HELPER = '/usr/lib/cmprovision/first-admin';

    public function state(): string
    {
        return $this->request(['action' => 'status'])['state'];
    }

    public function claim(#[\SensitiveParameter] string $code, string $name, string $email, #[\SensitiveParameter] string $hash): array
    {
        return $this->request(['action' => 'claim', 'code' => $code, 'name' => $name, 'email' => $email, 'password_hash' => $hash]);
    }

    private function request(#[\SensitiveParameter] array $payload): array
    {
        if (is_executable(self::HELPER)) {
            try {
                $process = new Process(['/usr/bin/sudo', '-n', self::HELPER], null, null, json_encode($payload, JSON_THROW_ON_ERROR), 20);
                $process->run();
                $result = json_decode($process->getOutput(), true);
                if ($process->isSuccessful() && in_array($result['state'] ?? null, ['armed', 'closed', 'invalid', 'limited', 'claimed'], true)) {
                    return $result;
                }
            } catch (\Throwable) {
                // Process input contains credentials. Only return a safe fixed error.
            }
        }
        return ['state' => 'unavailable'];
    }
}
