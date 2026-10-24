<?php

namespace App\Http\Livewire;

use App\Services\Fpms;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class FpmsSettings extends Component
{
    public $enabled = false, $open_on_start = false, $notice = null;

    public function mount(Fpms $fpms)
    {
        foreach ($fpms->settings() as $key => $value) $this->$key = $value;
    }

    public function save(Fpms $fpms)
    {
        abort_unless(Auth::check(), 403);
        $this->notice = null;
        $settings = $this->validate(['enabled' => 'required|boolean', 'open_on_start' => 'required|boolean']);
        $settings = array_map(fn ($value) => (bool) $value, $settings);
        try {
            $fpms->save($settings);
            $this->notice = 'Saved. FPMS applies changes within a few seconds.';
        } catch (\Throwable $e) {
            $this->addError('enabled', $e->getMessage());
        }
    }

    public function render(Fpms $fpms)
    {
        return view('livewire.fpms-settings', ['status' => $fpms->status()]);
    }
}
