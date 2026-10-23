<?php

namespace App\Http\Livewire;

use App\Services\UsbPrep;
use Livewire\Component;

/* Dashboard (2.12): the button of the USB preparation, and the boards it prepared lately */
class UsbPrepPanel extends Component
{
    /** the boards of the list: this many hours back */
    const HOURS = 12;

    public $error;

    public function start()
    {
        try {
            app(UsbPrep::class)->start();
            $this->error = null;
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function stop()
    {
        app(UsbPrep::class)->stop();
        $this->error = null;
    }

    public function render()
    {
        $prep = app(UsbPrep::class);

        return view('livewire.usb-prep-panel', [
            'active' => $prep->active(),
            'minutes' => $prep->active() ? (int) ceil(($prep->until() - now()->getTimestamp()) / 60) : 0,
            'problem' => $prep->problem(),
            'boards' => $prep->recent(6)->filter(fn ($cm) => $cm->usb_prep_at->gt(now()->subHours(self::HOURS)))->values(),
        ]);
    }
}
