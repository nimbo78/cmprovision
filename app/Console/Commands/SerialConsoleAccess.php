<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SerialConsoleAccess extends Command
{
    protected $signature = 'auth:serial-console {email} {--grant} {--revoke}';
    protected $description = 'Show, grant or revoke access to physical USB consoles from the web interface';

    public function handle(): int
    {
        if ($this->option('grant') && $this->option('revoke')) {
            $this->error('Choose one action.');
            return self::INVALID;
        }
        $user = User::where('email', $this->argument('email'))->first();
        if (!$user) {
            $this->error('Web account not found.');
            return self::FAILURE;
        }
        if ($this->option('grant') || $this->option('revoke')) {
            $user->forceFill(['serial_console_access' => $this->option('grant')])->save();
        }
        $this->info($user->serial_console_access ? 'USB console access enabled.' : 'USB console access disabled.');
        return self::SUCCESS;
    }
}
