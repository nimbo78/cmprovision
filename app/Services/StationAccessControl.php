<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/** The web helper cannot issue approval codes; local sudo/console is a separate entrypoint. */
class StationAccessControl
{
    const HELPER = '/usr/lib/cmprovision/station-access';

    public function available(): bool { return is_file(self::HELPER); }
    public function status(): array { return $this->request(['action' => 'status']); }
    public function prepare(#[\SensitiveParameter] array $input): array
    {
        $fields = ['user', 'password', 'keys', 'enable_ssh', 'create_user', 'allow_password_login'];
        return $this->request(['action' => 'prepare'] + array_intersect_key($input, array_flip($fields)));
    }
    public function apply(string $id, #[\SensitiveParameter] string $code): array { return $this->request(['action' => 'apply', 'id' => $id, 'code' => $code]); }
    public function cancel(string $id): array { return $this->request(['action' => 'cancel', 'id' => $id]); }

    protected function request(#[\SensitiveParameter] array $input): array
    {
        if (!$this->available()) return ['ok' => false, 'error' => 'Station SSH access control is unavailable. Use the existing SSH session or local console.'];
        $process = new Process(['sudo', '-n', self::HELPER]);
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(20);
        try {
            $process->run();
            $result = json_decode($process->getOutput(), true);
            if (is_array($result) && is_bool($result['ok'] ?? null)) return $result;
        } catch (\Throwable $error) {
            // Do not log input, process arguments or the credential-bearing exception.
        }
        return ['ok' => false, 'error' => 'Station access control did not answer. Check the account using the existing SSH session before retrying.'];
    }
}
