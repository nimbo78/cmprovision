<?php

namespace App\Console\Commands;

use App\Models\Cm;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Invoked only as the application user after replacing the authenticated SQLite snapshot. */
class BackupsRestoreFinalize extends Command
{
    protected $signature = 'backups:restore-finalize {--deactivate-project}';
    protected $description = 'Clear restored runtime work without replaying provisioning or notifications';

    public function handle(): int
    {
        DB::transaction(function () {
            foreach (['jobs', 'failed_jobs', 'job_batches', 'sessions', 'cache', 'cache_locks', 'password_resets',
                'password_reset_tokens', 'configuration_transfer_operations'] as $table) {
                if (Schema::hasTable($table)) DB::table($table)->delete();
            }
            Cm::whereIn('phase', Cm::ACTIVE_PHASES)->update(['phase' => 'failed', 'phase_detail' => 'Interrupted before station backup restoration. Start a fresh run.']);
            Setting::updateOrCreate(['key' => 'usb_prep_until'], ['value' => '0']);
            if ($this->option('deactivate-project')) Setting::destroy('active_project');
        });
        Artisan::call('optimize:clear');
        $this->line('{"ok":true}');
        return self::SUCCESS;
    }
}
