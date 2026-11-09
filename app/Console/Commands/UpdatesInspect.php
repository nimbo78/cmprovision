<?php

namespace App\Console\Commands;

use App\Models\Cm;
use App\Services\UtilityOs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** The root worker invokes this as www-data; root never opens SQLite or its WAL files. */
class UpdatesInspect extends Command
{
    protected $signature = 'updates:inspect';
    protected $description = 'Read update admission and the selected Utility OS without privileged DB access';
    public function handle(UtilityOs $utility): int
    {
        $current = $utility->current();
        $this->line(json_encode(['utility_selector' => match ($current) {
            'scriptexecute' => 'legacy', 'scriptexecute64' => 'bundled-modern', default => $current,
        }, 'utility_os_version' => $current && $current !== 'scriptexecute' ? ($utility->manifest($current)['version'] ?? '0') : '0',
            'utility_os_label' => $current ? ($utility->manifest($current)['version'] ?? 'unknown') : 'none',
            'admission' => ['active' => Cm::whereIn('phase', Cm::ACTIVE_PHASES)->orderBy('id')->get(['id', 'phase'])->toArray(),
                'reserved_jobs' => DB::table('jobs')->whereNotNull('reserved_at')->count()]], JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
