<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpdatesHealth extends Command
{
    protected $signature = 'updates:health';
    protected $description = 'Check the updated application, database and expected update routes';
    public function handle(): int
    {
        try {
            $ok = Schema::hasTable('cms') && Schema::hasTable('settings') && Schema::hasTable('jobs')
                && DB::selectOne('PRAGMA quick_check')->quick_check === 'ok'
                && app('router')->has('settings.updates.status');
            $this->line(json_encode(['ok' => $ok]));
            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $error) { $this->line('{"ok":false}'); return self::FAILURE; }
    }
}
