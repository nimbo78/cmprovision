<?php

namespace App\View\Components;

use Illuminate\View\Component;

class AppLayout extends Component
{
    /** @param string|null $title the page's name in the browser tab ("Dashboard" -> "Dashboard · cmprovision") */
    public function __construct(public ?string $title = null)
    {
    }

    /**
     * Get the view / contents that represents the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('layouts.app');
    }
}
