<?php

namespace App\Http\Livewire;

use App\Services\Wallboard;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class WallboardSettings extends Component
{
    public $mode = 'off', $style = 'warm', $color_mode = 'dark', $view = 'auto', $rotation = 12, $notice = null;

    public function mount(Wallboard $wallboard)
    {
        $settings = $wallboard->settings();
        foreach (['mode', 'view', 'rotation'] as $key) $this->$key = $settings[$key];
        $palette = $settings['palette'];
        $this->style = str_starts_with($palette, 'neutral') ? 'neutral' : 'warm';
        $this->color_mode = Setting::find('hdmi_color_mode')?->value === 'auto' ? 'auto' : (str_ends_with($palette, '-light') ? 'light' : 'dark');
    }

    public function save(Wallboard $wallboard)
    {
        abort_unless(Auth::check(), 403);
        $valid = $this->validate(['mode' => 'required|in:off,auto,on', 'style' => 'required|in:warm,neutral',
            'color_mode' => 'required|in:light,dark,auto', 'view' => 'required|in:auto,desk,flow,engineer', 'rotation' => 'required|integer|min:5|max:60']);
        $palette = $valid['style'].($valid['color_mode'] === 'light' ? '-light' : '');
        $settings = ['mode' => $valid['mode'], 'palette' => $palette, 'view' => $valid['view'], 'rotation' => (int) $valid['rotation']];
        try {
            $wallboard->save($settings);
            Setting::updateOrCreate(['key' => 'hdmi_color_mode'], ['value' => $valid['color_mode'] === 'auto' ? 'auto' : 'manual']);
            $this->notice = 'Saved. The display applies changes within a few seconds.';
        } catch (\Throwable $e) {
            $this->addError('mode', $e->getMessage());
        }
    }

    public function render(Wallboard $wallboard)
    {
        return view('livewire.wallboard-settings', ['status' => $wallboard->status()]);
    }
}
