<?php

namespace App\Services;

use App\Models\Cm;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** One confirmation protocol for the browser and the physical front panel. */
class StationPower
{
    const TTL = 120;
    public function __construct(private StationPowerControl $control, private NetworkChangeGate $gate) {}

    public function status(): array
    {
        return ['available' => $this->control->available(), 'boot_id' => $this->control->bootId(),
            'pending' => $this->control->pending(), 'active_count' => count($this->runs())];
    }
    public function prepare(string $action, string $actor): array
    {
        if (!in_array($action, ['reboot', 'poweroff'], true)) throw new \InvalidArgumentException('Unknown power action.');
        if (!$this->control->available()) throw new \RuntimeException('Power controls require the updated cmprovision4 package and systemd.');
        if ($this->control->pending()) throw new \RuntimeException('A station power action is already scheduled.');
        $token = bin2hex(random_bytes(32));
        $ticket = ['action' => $action, 'actor' => $actor, 'stage' => 'confirm', 'expires_at' => now()->timestamp + self::TTL,
            'runs' => $this->runs()];
        Cache::put($this->key($token), $ticket, self::TTL);
        return $this->view($token, $ticket);
    }
    public function confirm(string $token, string $actor, bool $interrupt = false): array
    {
        return Cache::lock('station-power-confirm', 15)->block(1, function () use ($token, $actor, $interrupt) {
            $result = $this->gate->start(function () use ($token, $actor, $interrupt) {
                $ticket = $this->owned($token, $actor);
                if (!$this->control->available()) throw new \RuntimeException('The power controller is unavailable.');
                if ($this->control->pending()) throw new \RuntimeException('A station power action is already scheduled.');
                $runs = $this->runs();
                if ($runs && ($ticket['stage'] !== 'interrupt' || !$interrupt || $runs !== $ticket['runs'])) {
                    $ticket['stage'] = 'interrupt';
                    $ticket['runs'] = $runs;
                    Cache::put($this->key($token), $ticket, max(1, $ticket['expires_at'] - now()->timestamp));
                    return $this->view($token, $ticket);
                }
                $scheduled = $this->control->schedule($ticket['action']);
                Cache::forget($this->key($token));
                Log::notice('Station power action scheduled', ['action' => $ticket['action'],
                    'source' => str_starts_with($actor, 'web:') ? 'web' : 'fpms', 'active_modules' => count($runs)]);
                return ['stage' => 'scheduled', 'active_count' => count($runs)] + $scheduled;
            });
            if (!is_array($result)) throw new \RuntimeException('Another station operation is pending. Finish it before changing station power.');
            return $result;
        });
    }
    public function cancel(string $token, string $actor): void
    {
        $this->owned($token, $actor);
        Cache::forget($this->key($token));
    }
    private function owned(string $token, string $actor): array
    {
        $ticket = preg_match('/\A[0-9a-f]{64}\z/D', $token) ? Cache::get($this->key($token)) : null;
        if (!is_array($ticket) || !hash_equals($ticket['actor'], $actor) || $ticket['expires_at'] <= now()->timestamp)
            throw new \RuntimeException('The confirmation expired or belongs to another session. Choose the action again.');
        return $ticket;
    }
    private function runs(): array
    {
        // Silent/unfinished runs still matter: a missing report is not proof writing has stopped.
        return Cm::whereIn('phase', Cm::ACTIVE_PHASES)->orderBy('id')->get(['id', 'provisioning_started_at'])
            ->map(fn ($cm) => [$cm->id, $cm->provisioning_started_at?->toISOString()])->all();
    }
    private function key(string $token): string { return 'station-power-ticket:'.$token; }
    private function view(string $token, array $ticket): array
    {
        return ['token' => $token, 'stage' => $ticket['stage'], 'action' => $ticket['action'],
            'active_count' => count($ticket['runs']), 'expires_at' => $ticket['expires_at']];
    }
}
