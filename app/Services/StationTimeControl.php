<?php

namespace App\Services;

use App\Support\SystemTimezone;
use Symfony\Component\Process\Process;

/** The web process can invoke only the fixed, root-owned time controller. */
class StationTimeControl
{
    const HELPER = '/usr/lib/cmprovision/station-time';

    public function status(): array
    {
        try { return $this->request(['action' => 'status']); }
        catch (\RuntimeException $error) {
            return ['available' => false, 'reason' => $error->getMessage(), 'timezone' => SystemTimezone::detect(),
                'timezones' => [], 'timestamp' => time(), 'ntp_available' => false, 'ntp_reason' => $error->getMessage(),
                'ntp_enabled' => false, 'ntp_active' => false, 'synchronized' => null, 'server_name' => null,
                'server_address' => null, 'system_servers' => [], 'link_servers' => [], 'fallback_servers' => [],
                'managed_servers' => [], 'provider' => null];
        }
    }
    public function timezone(string $zone): array { return $this->request(['action' => 'set-timezone', 'timezone' => $zone]); }
    public function ntp(bool $enabled, array $servers): array
    {
        return $this->request(['action' => 'set-ntp', 'enabled' => $enabled, 'servers' => $servers]);
    }
    private function request(array $request): array
    {
        if (!is_file(self::HELPER)) throw new \RuntimeException('Time controls require the updated cmprovision4 package and systemd.');
        $process = new Process(['sudo', '-n', self::HELPER]);
        $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        try { $process->run(); }
        catch (\Throwable $error) { throw new \RuntimeException('The time controller did not answer. Refresh status before retrying.'); }
        $response = strlen($process->getOutput()) <= 131072 ? json_decode($process->getOutput(), true) : null;
        if (!$process->isSuccessful() || ($response['ok'] ?? false) !== true) {
            throw new \RuntimeException(is_string($response['error'] ?? null) ? $response['error'] : 'The time controller could not complete the request.');
        }
        $result = $response['result'] ?? null;
        if (!is_array($result) || !is_bool($result['available'] ?? null) || !is_array($result['timezones'] ?? null)
            || !is_string($result['timezone'] ?? null) || !is_int($result['timestamp'] ?? null)
            || !is_bool($result['ntp_available'] ?? null) || !is_array($result['managed_servers'] ?? null))
            throw new \RuntimeException('The time controller returned an incompatible status. Check package installation.');
        return $result;
    }
}
