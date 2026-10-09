<?php

namespace App\Http\Livewire;

use Livewire\Component;
use Illuminate\Support\Carbon;
use App\Models\Firmware;
use App\Models\Setting;
use App\Services\FirmwareUpdater;

class Firmwares extends Component
{
    public $firmware, $lastUpdate;

    public function render()
    {
        $this->firmware = Firmware::all();
        $setting = Setting::find('firmware_last_update');
        $this->lastUpdate = $setting ? Carbon::parse($setting->value, 'UTC')->local()->toDateTimeString() : null;

        return view('livewire.firmware');
    }

    public function update()
    {
        $result = (new FirmwareUpdater)->update();

        $added = count($result['added']);
        $msg = $added
            ? "Added $added new image(s): ".implode(', ', $result['added']).'.'
            : 'No new images available.';

        if (count($result['errors']))
        {
            $msg .= ' Errors: '.implode('; ', $result['errors']);
        }
        else
        {
            Setting::updateOrCreate(['key' => 'firmware_last_update'], ['value' => now()->toDateTimeString()]);
        }

        session()->flash('message', $msg);
    }
}
