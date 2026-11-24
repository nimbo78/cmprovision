<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SerialConsoleBroker
{
    private const UNAVAILABLE = ['ok' => false, 'error' => 'unavailable', 'message' => 'USB console service unavailable.'];
    private const MAX_REPLY = 3145728;

    public static function owner(Request $request, int|string $userId): string
    {
        return hash_hmac('sha256', 'serial-console:'.$userId.':'.$request->session()->getId(), config('app.key'));
    }

    /** This fixed local endpoint accepts one bounded JSON exchange per connection. */
    public function request(#[\SensitiveParameter] array $payload): array
    {
        $socket = @stream_socket_client('unix://'.config('services.serial_console.socket'), $errno, $error, 0.4);
        if (!$socket) {
            return self::UNAVAILABLE;
        }
        try {
            stream_set_timeout($socket, 2);
            $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            for ($offset = 0, $length = strlen($line); $offset < $length; $offset += $written) {
                $written = @fwrite($socket, substr($line, $offset, 65536));
                if (!$written) {
                    return self::UNAVAILABLE;
                }
            }
            $line = @fgets($socket, self::MAX_REPLY + 2);
            if (!is_string($line) || !str_ends_with($line, "\n")) {
                return self::UNAVAILABLE;
            }
            $reply = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            return is_array($reply) && is_bool($reply['ok'] ?? null) ? $reply : self::UNAVAILABLE;
        } catch (\JsonException) {
            return self::UNAVAILABLE;
        } finally {
            fclose($socket);
        }
    }

    public function hasPorts(): bool
    {
        return Cache::remember('serial-console:present', 4, function () {
            $reply = $this->request(['op' => 'ports']);
            return $reply['ok'] && !empty($reply['ports']);
        });
    }

    public static function labelKey(string $port): string
    {
        return 'serial_console_label_'.hash('sha256', $port);
    }

    public static function wakeKey(string $port): string
    {
        return 'serial_console_wake_'.hash('sha256', $port);
    }

    public static function wakePermission(string $port, string $generation): string
    {
        // Without a unique USB serial, a replacement cable can inherit the physical port.
        return str_starts_with($port, 'by-id/') ? '1' : $generation;
    }

    public function ports(): array
    {
        $reply = $this->request(['op' => 'ports']);
        if (!$reply['ok'] || empty($reply['ports'])) {
            return $reply;
        }
        $ports = array_column($reply['ports'], 'id');
        $keys = array_merge(array_map(self::labelKey(...), $ports), array_map(self::wakeKey(...), $ports));
        $preferences = Setting::whereIn('key', $keys)->pluck('value', 'key');
        foreach ($reply['ports'] as &$port) {
            $port['label'] = $preferences->get(self::labelKey($port['id']), '');
            $port['wake_on_connect'] = $preferences->get(self::wakeKey($port['id'])) === self::wakePermission($port['id'], $port['generation']);
        }
        return $reply;
    }
}
