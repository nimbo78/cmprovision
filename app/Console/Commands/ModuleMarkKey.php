<?php

namespace App\Console\Commands;

use App\Services\Sensor\ModuleMark;
use Illuminate\Console\Command;

/* The mark key of the provisioners (ModuleMark): stations with the same key recognise the devices each other flashed.
   Run it as the web user (sudo -u www-data php artisan ...): the key is kept encrypted with the APP_KEY. To give one
   station's key to another without showing it:
     ssh a 'sudo -u www-data php /var/lib/cmprovision/artisan sensor:mark-key --show' \
       | ssh b 'sudo -u www-data php /var/lib/cmprovision/artisan sensor:mark-key --from=-' */
class ModuleMarkKey extends Command
{
    protected $signature = 'sensor:mark-key
        {--generate : Make a new mark key for this provisioner}
        {--from= : Take the mark key of another provisioner from this file (- for the standard input)}
        {--show : Print the mark key, to give it to another provisioner}';

    protected $description = 'The mark key: provisioners with the same key recognise the devices each other flashed';

    public function handle(ModuleMark $marks): int
    {
        if ($this->option('generate'))
        {
            $marks->generate();
            $this->info('A new mark key. Fingerprint: '.$marks->fingerprint());
            return 0;
        }
        if (($from = $this->option('from')) !== null)
        {
            $text = $from === '-' ? stream_get_contents(STDIN) : @file_get_contents($from);
            try
            {
                $marks->set((string) $text);
            }
            catch (\InvalidArgumentException $e)
            {
                $this->error($e->getMessage());
                return 1;
            }
            $this->info('Mark key set. Fingerprint: '.$marks->fingerprint());
            return 0;
        }
        $key = $marks->key();
        if ($this->option('show'))
        {
            if ($key === null)
            {
                $this->error('No mark key.');
                return 1;
            }
            $this->line($key);
            return 0;
        }
        $this->line($key === null
            ? 'No mark key: sensor:mark-key --generate, or --from=<file> with the key of another provisioner.'
            : 'Fingerprint: '.$marks->fingerprint());
        return 0;
    }
}
