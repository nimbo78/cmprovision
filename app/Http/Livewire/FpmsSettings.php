<?php

namespace App\Http\Livewire;

use App\Services\Fpms;
use App\Support\FpmsPanelSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class FpmsSettings extends Component
{
    public $enabled = false, $open_on_start = false, $notice = null;
    /* how the panel looks: the server's own setting, sent in the snapshot (FpmsPanelSettings) */
    public $theme = 'new', $ports_map = 'pages', $color_mode = 'dark', $screen_timeout = FpmsPanelSettings::DEFAULT_SCREEN_TIMEOUT;
    #[Locked]
    public array $savedPreferences = [];

    public function mount(Fpms $fpms)
    {
        foreach ($fpms->settings() as $key => $value) $this->$key = $value;
        $settings = FpmsPanelSettings::get();
        ['theme' => $this->theme, 'ports_map' => $this->ports_map, 'color_mode' => $this->color_mode, 'screen_timeout' => $this->screen_timeout] = $settings;
        $this->savedPreferences = array_intersect_key($settings, array_flip(['color_mode', 'screen_timeout']));
    }

    public function refreshDisplayPreferences(): void
    {
        $settings = FpmsPanelSettings::get();
        foreach ($this->savedPreferences as $key => $original) {
            if ((string) $this->$key === (string) $original) $this->$key = $settings[$key];
            $this->savedPreferences[$key] = $settings[$key];
        }
    }

    public function save(Fpms $fpms)
    {
        abort_unless(Auth::check(), 403);
        $this->notice = null;
        $valid = $this->validate(['enabled' => 'required|boolean', 'open_on_start' => 'required|boolean',
            'theme' => ['required', Rule::in(FpmsPanelSettings::THEMES)], 'ports_map' => ['required', Rule::in(FpmsPanelSettings::PORTS_MAPS)],
            'color_mode' => ['required', Rule::in(FpmsPanelSettings::COLOR_MODES)],
            'screen_timeout' => ['required', 'integer', Rule::in(array_keys(FpmsPanelSettings::SCREEN_TIMEOUTS))]]);
        // the look needs no root helper: it is kept even when the helper below fails
        $wanted = ['color_mode' => $valid['color_mode'], 'screen_timeout' => (int) $valid['screen_timeout']];
        $changed = array_diff_assoc($wanted, $this->savedPreferences);
        FpmsPanelSettings::save($valid['theme'], $valid['ports_map'], $changed['color_mode'] ?? null, $changed['screen_timeout'] ?? null);
        $this->savedPreferences = $wanted;
        $this->refreshDisplayPreferences();
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
