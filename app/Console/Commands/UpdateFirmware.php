<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\FirmwareUpdater;
use Illuminate\Console\Command;

class UpdateFirmware extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firmware:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch EEPROM firmware images that are not in the local store yet (same as the button on the Firmware page)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $result = (new FirmwareUpdater)->update();

        foreach ($result['added'] as $path)
        {
            $this->line("Added $path");
        }
        if (!count($result['added']))
        {
            $this->line('No new images available.');
        }
        foreach ($result['errors'] as $error)
        {
            $this->error($error);
        }

        if (count($result['errors']))
        {
            return 1;
        }

        Setting::updateOrCreate(['key' => 'firmware_last_update'], ['value' => now()->toDateTimeString()]);
        return 0;
    }
}
