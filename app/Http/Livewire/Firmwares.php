<?php

namespace App\Http\Livewire;

use Livewire\Attributes\Title;
use Livewire\Component;
use Illuminate\Support\Carbon;
use App\Models\Firmware;
use App\Models\Setting;
use App\Services\FirmwareNotes;
use App\Services\FirmwareUpdater;

#[Title('Firmware')]
class Firmwares extends Component
{
    public $lastUpdate;

    public function render()
    {
        $setting = Setting::find('firmware_last_update');
        $this->lastUpdate = $setting ? Carbon::parse($setting->value, 'UTC')->local()->toDateTimeString() : null;

        $notes = new FirmwareNotes;
        $firmware = Firmware::all();
        $changes = [];
        foreach ($firmware as $f)
            $changes[$f->path] = $notes->forFile($f->name);

        // view data, not a property: Livewire 3 does not serialize Firmware objects
        return view('livewire.firmware', ['firmware' => $firmware, 'changes' => $changes, 'notesDate' => $notes->latestDate()]);
    }

    public function update()
    {
        $result = (new FirmwareUpdater)->update();

        $added = count($result['added']);
        $msg = $added
            ? "Added $added new image(s): ".implode(', ', $result['added']).'.'
            : 'No new images available.';
        if ($result['notes'] == 'updated')
            $msg .= ' Release notes updated.';
        elseif ($result['notes'] == 'unavailable')
            $msg .= ' Release notes could not be fetched.';

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
