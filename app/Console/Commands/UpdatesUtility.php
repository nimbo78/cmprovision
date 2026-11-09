<?php

namespace App\Console\Commands;

use App\Services\UtilityOs;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/** The root worker delegates the web-owned boot link to www-data. */
class UpdatesUtility extends Command
{
    protected $signature = 'updates:utility {--validate=} {--activate=}';
    protected $description = 'Verify or select an installed Utility OS for subsequent boots';
    public function handle(UtilityOs $utility): int
    {
        try {
            $selector = $this->option('activate') ?? $this->option('validate');
            if (!is_string($selector)) throw new \InvalidArgumentException('Choose a Utility OS selector.');
            if (str_starts_with($selector, 'managed:')) {
                $process = new Process(['/usr/bin/python3', '-I', '/usr/lib/cmprovision/update_utility.py', '--validate', $selector]);
                $process->setTimeout(120);
                $process->mustRun();
            }
            $dir = match ($selector) { 'legacy' => 'scriptexecute', 'bundled-modern' => 'scriptexecute64', default => $selector };
            if ($utility->manifest($dir, true) === null) throw new \InvalidArgumentException('Utility OS files failed verification.');
            if ($this->option('activate') !== null) $utility->select($selector);
            $this->line(json_encode(['ok' => true, 'current' => $utility->selector()]));
            return self::SUCCESS;
        } catch (\Throwable $error) { $this->line('{"ok":false}'); return self::FAILURE; }
    }
}
