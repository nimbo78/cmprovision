<?php

/* Only what cmprovision changes; Livewire merges its defaults for everything else */
return [

    /* The components stayed where Livewire 2 kept them; Livewire 3 would look in App\Livewire */
    'class_namespace' => 'App\Http\Livewire',

    /* Full-page components render inside the Jetstream layout, as they did under Livewire 2 */
    'layout' => 'layouts.app',

];
