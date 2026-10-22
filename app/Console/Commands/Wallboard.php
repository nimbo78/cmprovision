<?php

namespace App\Console\Commands;

use App\Support\WallboardSnapshot;
use Illuminate\Console\Command;

class Wallboard extends Command
{
    protected $signature = 'wallboard:snapshot {--watch : Stream one JSON snapshot every two seconds}';
    protected $description = 'Local read-only data for the HDMI wallboard';

    public function handle(WallboardSnapshot $snapshots): int
    {
        do {
            try {
                $this->line(json_encode($snapshots->current(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            } catch (\Throwable $e) {
                // The terminal keeps its last snapshot with a stale warning. No SQL, credentials or input in stdout.
                $this->line(json_encode(['version' => 1, 'error' => 'Snapshot unavailable']));
                if (!$this->option('watch')) return 1;
            }
            if ($this->option('watch')) sleep(2);
        } while ($this->option('watch'));
        return 0;
    }
}
