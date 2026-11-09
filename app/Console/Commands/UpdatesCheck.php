<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\StationUpdates;

class UpdatesCheck extends Command
{
    protected $signature = 'updates:check';
    protected $description = 'Check only enabled update components when their daily check is due';
    public function handle(StationUpdates $updates): int
    {
        try { $updates->scheduled(); return self::SUCCESS; }
        catch (\Throwable $error) { report($error); return self::FAILURE; }
    }
}
