<?php

namespace App\Http\Livewire;

use App\Support\BatchSummary;
use Livewire\Component;

/* The open batch in the top bar of every page; its root carries the tab's title and icon (public/js/tab-status.js).
   Polled every 10 s with keep-alive: Livewire would otherwise poll a background tab 95% less often. */
class BatchIndicator extends Component
{
    public function render()
    {
        return view('livewire.batch-indicator', ['summary' => BatchSummary::current()]);
    }
}
