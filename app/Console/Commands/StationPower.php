<?php

namespace App\Console\Commands;

use App\Services\StationPower as Power;
use Illuminate\Console\Command;

/** Local physical panel only; its fixed bridge runs this command as www-data. */
class StationPower extends Command
{
    protected $signature = 'station:power {--json : Bounded JSON request on stdin}';
    protected $description = 'Local FPMS station power confirmation protocol';
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
}
