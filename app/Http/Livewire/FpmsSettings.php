<?php

namespace App\Http\Livewire;

use App\Services\Fpms;
use App\Support\FpmsPanelSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class FpmsSettings extends Component
{
    public $enabled = false, $open_on_start = false, $notice = null;
    /* how the panel looks: the server's own setting, sent in the snapshot (FpmsPanelSettings) */
    public $theme = 'new', $ports_map = 'pages';

    public function mount(Fpms $fpms)
    {
        foreach ($fpms->settings() as $key => $value) $this->$key = $value;
        ['theme' => $this->theme, 'ports_map' => $this->ports_map] = FpmsPanelSettings::get();
    }

    public function save(Fpms $fpms)
    {
        abort_unless(Auth::check(), 403);
        $this->notice = null;
        $valid = $this->validate(['enabled' => 'required|boolean', 'open_on_start' => 'required|boolean',
            'theme' => ['required', Rule::in(FpmsPanelSettings::THEMES)], 'ports_map' => ['required', Rule::in(FpmsPanelSettings::PORTS_MAPS)]]);
        // the look needs no root helper: it is kept even when the helper below fails
        FpmsPanelSettings::save($valid['theme'], $valid['ports_map']);
        $settings = array_map(fn ($value) => (bool) $value, array_intersect_key($valid, Fpms::DEFAULTS));
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
