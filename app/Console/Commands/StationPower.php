<?php

namespace App\Console\Commands;

use App\Services\StationPower as Power;
use App\Support\FpmsPanelSettings;
use Illuminate\Console\Command;

/** Local physical panel only; its fixed bridge runs this command as www-data. */
class StationPower extends Command
{
    protected $signature = 'station:power {--json : Bounded JSON request on stdin}';
    protected $description = 'Local FPMS station menu and power confirmation protocol';
    public function handle(Power $power): int
    {
        try {
            if (!$this->option('json')) throw new \InvalidArgumentException('JSON input is required.');
            $raw = stream_get_contents(STDIN, 4097);
            if (strlen($raw) > 4096) throw new \InvalidArgumentException('Request is too large.');
            $request = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($request)) throw new \InvalidArgumentException('Invalid power request.');
            $result = match ($request['operation'] ?? '') {
                'status' => $power->status(),
                'prepare' => $power->prepare($request['action'] ?? '', 'fpms'),
                'confirm' => $power->confirm($request['token'] ?? '', 'fpms', ($request['interrupt'] ?? false) === true),
                'cancel' => $this->cancel($power, $request['token'] ?? ''),
                'appearance' => $this->appearance($request, false),
                'set-appearance' => $this->appearance($request, true),
                'screen-timeout' => $this->screenTimeout($request, false),
                'set-screen-timeout' => $this->screenTimeout($request, true),
                default => throw new \InvalidArgumentException('Unknown power operation.'),
            };
            $this->line(json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR));
        } catch (\Throwable $error) {
            $message = $error instanceof \RuntimeException || $error instanceof \InvalidArgumentException
                ? $error->getMessage() : 'The station power request could not be completed.';
            $this->line(json_encode(['ok' => false, 'error' => $message], JSON_THROW_ON_ERROR));
        }
        return self::SUCCESS;
    }
    private function cancel(Power $power, string $token): array
    {
        $power->cancel($token, 'fpms');
        return ['stage' => 'cancelled'];
    }

    private function appearance(array $request, bool $save): array
    {
        $keys = $save ? ['operation', 'mode'] : ['operation'];
        if (count($request) !== count($keys) || array_diff(array_keys($request), $keys))
            throw new \InvalidArgumentException('Invalid appearance request.');
        if ($save) {
            if (!is_string($request['mode']) || !in_array($request['mode'], FpmsPanelSettings::COLOR_MODES, true))
                throw new \InvalidArgumentException('Unknown FPMS color mode.');
            FpmsPanelSettings::saveColorMode($request['mode']);
        }
        return ['stage' => 'appearance', 'mode' => FpmsPanelSettings::get()['color_mode'],
            'observed_at' => now()->getTimestamp()];
    }

    private function screenTimeout(array $request, bool $save): array
    {
        $keys = $save ? ['operation', 'seconds'] : ['operation'];
        if (count($request) !== count($keys) || array_diff(array_keys($request), $keys))
            throw new \InvalidArgumentException('Invalid screen timeout request.');
        if ($save) {
            if (!is_int($request['seconds'])) throw new \InvalidArgumentException('Screen timeout must be an integer.');
            FpmsPanelSettings::saveScreenTimeout($request['seconds']);
        }
        return ['stage' => 'screen-timeout', 'seconds' => FpmsPanelSettings::get()['screen_timeout'], 'observed_at' => now()->getTimestamp()];
    }
}
