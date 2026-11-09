<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StationUpdates
{
    const COMPONENTS = ['application', 'utility-os'];
    const TTL = 600;
    public function __construct(private StationUpdatesControl $control) {}
    public function preferences(): array
    {
        return array_combine(self::COMPONENTS, array_map(fn ($part) => Setting::find('updates_check_'.$part)?->value === '1', self::COMPONENTS));
    }
    public function savePreferences(bool $application, bool $utility): void
    {
        foreach (array_combine(self::COMPONENTS, [$application, $utility]) as $part => $enabled)
            Setting::updateOrCreate(['key' => 'updates_check_'.$part], ['value' => $enabled ? '1' : '0']);
    }
    public function check(?array $components = null): array
    {
        $components ??= self::COMPONENTS;
        return $this->control->request(['action' => 'check', 'nonce' => bin2hex(random_bytes(32)), 'components' => $components]);
    }
    public function scheduled(): void
    {
        if (!$this->control->available() || $this->control->pending()) return;
        Cache::lock('station-updates-schedule', 20)->get(function () {
            $due = [];
            foreach ($this->preferences() as $part => $enabled) {
                $last = (int) (Setting::find('updates_last_attempt_'.$part)?->value ?? 0);
                if ($enabled && now()->timestamp - $last >= 86400) $due[] = $part;
            }
            if (!$due) return;
            $status = $this->control->status();
            if (!empty($status['operation']) && !in_array($status['operation']['state'] ?? '', ['ready', 'succeeded', 'rolled-back', 'failed', 'canceled'], true)) return;
            $this->check($due);
            foreach ($due as $part) Setting::updateOrCreate(['key' => 'updates_last_attempt_'.$part], ['value' => (string) now()->timestamp]);
        });
    }
    public function download(string $component, string $tag): array
    {
        if (!in_array($component, self::COMPONENTS, true) || !preg_match('/\A(?:v[0-9]+\.[0-9]+\.[0-9]+|utility-os-[0-9]{4}\.[0-9]{2}(?:\.[0-9]+)?)\z/D', $tag))
            throw new \InvalidArgumentException('Choose a verified stable release.');
        return $this->control->request(['action' => 'download', 'nonce' => bin2hex(random_bytes(32)), 'component' => $component, 'tag' => $tag]);
    }
    public function prepare(string $action, string $target, string $actor): array
    {
        $status = $this->control->status();
        if ($this->control->pending()) throw new \RuntimeException('Another update is in maintenance or needs recovery.');
        $request = ['action' => $action, 'nonce' => bin2hex(random_bytes(32))];
        if ($action === 'apply') {
            $ready = collect($status['ready'] ?? [])->firstWhere('id', $target);
            if (!$ready) throw new \RuntimeException('Download and verify the selected update first.');
            $request += ['candidate' => $target, 'receipt' => $ready['receipt']];
            $description = $ready['release']['component'].' '.$ready['release']['version'];
        } elseif ($action === 'rollback') {
            $previous = collect($status['recoveries'] ?? [])->firstWhere('id', $target);
            if (!$previous) throw new \RuntimeException('No retained recovery snapshot is available for this operation.');
            $request += ['operation' => $target];
            $description = 'Restore the previous version and its recorded data. Changes after that snapshot will be lost.';
        } elseif ($action === 'activate') {
            if (!preg_match('/\A(?:legacy|bundled-modern|managed:[0-9]{4}\.[0-9]{2}(?:\.[0-9]+)?)\z/D', $target)) throw new \InvalidArgumentException('Invalid Utility OS selection.');
            $request += ['selector' => $target];
            $description = 'Use Utility OS '.$target.' for subsequent boots.';
        } else throw new \InvalidArgumentException('Unknown update action.');
        $token = bin2hex(random_bytes(32));
        $ticket = ['actor' => $actor, 'request' => $request, 'description' => $description, 'expires_at' => now()->timestamp + self::TTL];
        Cache::put('station-update-ticket:'.$token, $ticket, self::TTL);
        return ['token' => $token, 'description' => $description, 'action' => $action, 'expires_at' => $ticket['expires_at']];
    }
    public function confirm(string $token, string $actor): array
    {
        return Cache::lock('station-update-confirm', 20)->block(1, function () use ($token, $actor) {
            $ticket = preg_match('/\A[0-9a-f]{64}\z/D', $token) ? Cache::get('station-update-ticket:'.$token) : null;
            if (!is_array($ticket) || !hash_equals($ticket['actor'], $actor) || $ticket['expires_at'] <= now()->timestamp)
                throw new \RuntimeException('The confirmation expired or belongs to another session. Review the update again.');
            // Keep the same nonce for a lost/retried reply: root decides if it has already run.
            $result = $this->control->request($ticket['request']);
            Log::notice('Station update requested', ['action' => $ticket['request']['action'], 'operation' => $result['id'] ?? null]);
            return $result;
        });
    }
    public function cancel(string $id): array { return $this->control->request(['action' => 'cancel', 'id' => $id]); }
}
