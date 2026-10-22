<?php

namespace App\Console\Commands;

use App\Support\WallboardSnapshot;
use Illuminate\Console\Command;

class Wallboard extends Command
{
    protected $signature = 'wallboard:snapshot {--watch : Keep producing a snapshot every two seconds}
        {--output= : Write each snapshot to this file (atomically, readable by everyone) instead of stdout}';
    protected $description = 'Local read-only data for the HDMI wallboard';

    public function handle(WallboardSnapshot $snapshots): int
    {
        $output = $this->option('output');
        if ($output !== null && (!is_string($output) || !str_starts_with($output, '/') || !is_dir(dirname($output)) || !is_writable(dirname($output))))
        {
            $this->error('The output must be a file in an existing writable directory.');
            return 1;
        }
        do {
            try {
                $json = json_encode($snapshots->current(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                $output === null ? $this->line($json) : $this->store($output, $json);
            } catch (\Throwable $e) {
                report($e);   // laravel.log; the screen only shows that the data is stale
                // No SQL, credentials or input on stdout. A file keeps its last good snapshot, so the display goes stale.
                if ($output === null)
                    $this->line(json_encode(['version' => 1, 'error' => 'Snapshot unavailable']));
                if (!$this->option('watch')) return 1;
            }
            if ($this->option('watch')) sleep(2);
        } while ($this->option('watch'));
        return 0;
    }

    /* The reader never sees a half-written file: write beside it, then rename over it. */
    protected function store(string $path, string $json): void
    {
        $temporary = tempnam(dirname($path), '.snapshot-');
        if ($temporary === false)
            throw new \RuntimeException('No temporary file for the snapshot.');
        try {
            file_put_contents($temporary, $json."\n");
            chmod($temporary, 0644);
            rename($temporary, $path);
        } finally {
            if (is_file($temporary))
                unlink($temporary);
        }
    }
}
