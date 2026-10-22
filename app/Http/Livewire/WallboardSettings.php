<?php

namespace App\Http\Livewire;

use App\Services\Wallboard;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class WallboardSettings extends Component
{
    public $mode = 'off', $palette = 'warm', $view = 'auto', $rotation = 12, $notice = null;

    public function mount(Wallboard $wallboard)
    {
        foreach ($wallboard->settings() as $key => $value) $this->$key = $value;
    }

    public function save(Wallboard $wallboard)
    {
        abort_unless(Auth::check(), 403);
        $settings = $this->validate(['mode' => 'required|in:off,auto,on', 'palette' => 'required|in:warm,phosphor,mono',
            'view' => 'required|in:auto,desk,flow,engineer', 'rotation' => 'required|integer|min:5|max:60']);
        $settings['rotation'] = (int) $settings['rotation'];
        try {
            $wallboard->save($settings);
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
