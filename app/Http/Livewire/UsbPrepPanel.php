<?php

namespace App\Http\Livewire;

use App\Services\UsbPrep;
use Livewire\Component;

/* Dashboard (2.12): the tile of the USB preparation: its button, and the state of the boards it prepared lately (the last one in
   words, the others counted). A board that starts an ordinary run stops being a prepared one (usb_prep_at is emptied) */
class UsbPrepPanel extends Component
{
    /** the boards of the tile: prepared within this many minutes */
    const MINUTES = 120;

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
            // the last prepared first (the same minute: the later row)
            'boards' => $prep->recent(20)->filter(fn ($cm) => $cm->usb_prep_at->gt(now()->subMinutes(self::MINUTES)))
                ->sort(fn ($a, $b) => [$b->usb_prep_at->getTimestamp(), $b->id] <=> [$a->usb_prep_at->getTimestamp(), $a->id])->values(),
        ]);
    }
}
