<?php

namespace App\Services\Configuration;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/** Short-lived wizard state belongs to this signed-in browser session, never to a Livewire snapshot. */
class WizardSecrets
{
    public function start(): string
    {
        abort_unless(auth()->check(), 403);
        $token = bin2hex(random_bytes(16));
        $this->put($token, []);
        return $token;
    }

    public function put(string $token, #[\SensitiveParameter] array $values): void
    {
        $this->check($token);
        session()->put('configuration_wizards.'.$token, Crypt::encryptString(json_encode([
            'user' => auth()->id(), 'expires' => now()->addHours(24)->getTimestamp(), 'values' => $values,
        ], JSON_THROW_ON_ERROR)));
    }

    public function get(string $token): array
    {
        $this->check($token);
        try {
            $value = session('configuration_wizards.'.$token);
            if (!is_string($value)) throw new RuntimeException;
            $data = json_decode(Crypt::decryptString($value), true, 64, JSON_THROW_ON_ERROR);
            if (($data['user'] ?? null) !== auth()->id() || ($data['expires'] ?? 0) <= now()->getTimestamp()
                || !is_array($data['values'] ?? null)) throw new RuntimeException;
            return $data['values'];
        } catch (\Throwable $error) {
            throw new RuntimeException('This wizard has expired. Start it again.');
        }
    }

    public function forget(string $token): void
    {
        $this->check($token);
        session()->forget('configuration_wizards.'.$token);
    }

    private function check(string $token): void
    {
        abort_unless(auth()->check() && preg_match('/^[0-9a-f]{32}$/D', $token), 403);
    }
}
