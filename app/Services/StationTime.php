<?php

namespace App\Services;

use App\Models\Cm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StationTime
{
    public function __construct(private StationTimeControl $control, private NetworkChangeGate $gate) {}

    public function timezone($zone): array
    {
        return $this->change(function () use ($zone) {
            $status = $this->control->status();
            if (!$status['available']) throw new \RuntimeException($status['reason']);
            Validator::make(['timezone' => $zone], ['timezone' => ['required', 'string', Rule::in($status['timezones'])]])->validate();
            $state = $this->control->timezone($zone);
            // Queue workers finish their current job before reading the new display zone.
            Artisan::call('queue:restart');
            Log::notice('Station time zone changed', ['timezone' => $zone, 'user' => auth()->id()]);
            return $state;
        });
    }
    public function ntp($enabled, $text): array
    {
        return $this->change(function () use ($enabled, $text) {
            Validator::make(['ntpEnabled' => $enabled, 'ntpServers' => $text], [
                'ntpEnabled' => ['required', 'boolean'], 'ntpServers' => ['nullable', 'string', 'max:4096'],
            ])->validate();
            $servers = preg_split('/[\s,]+/', trim($text ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            if (count($servers) > 8) throw ValidationException::withMessages(['ntpServers' => 'Use at most eight NTP servers.']);
            foreach ($servers as $server) {
                $ip = filter_var($server, FILTER_VALIDATE_IP);
                $host = strlen($server) <= 253 && !preg_match('/\A[0-9.]+\z/D', $server)
                    && preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?\z/D', $server);
                if (!$ip && !$host) throw ValidationException::withMessages(['ntpServers' => 'Each server must be a hostname, IPv4 or IPv6 address; omit URLs and ports.']);
            }
            $status = $this->control->status();
            if (!$status['ntp_available']) throw new \RuntimeException($status['ntp_reason']);
            $servers = array_values(array_unique(array_map('strtolower', $servers)));
            $state = $this->control->ntp((bool) $enabled, $servers);
            Log::notice('Station NTP settings changed', ['enabled' => (bool) $enabled, 'servers' => $servers, 'user' => auth()->id()]);
            return $state;
        });
    }
    private function change(callable $apply): array
    {
        $result = $this->gate->start(function () use ($apply) {
            if (Cm::whereIn('phase', Cm::ACTIVE_PHASES)->exists())
                throw new \RuntimeException('Wait until provisioning finishes before changing station time settings.');
            return $apply();
        });
        if (!is_array($result)) throw new \RuntimeException('Another station operation is pending. Finish it before changing time settings.');
        return $result;
    }
}
