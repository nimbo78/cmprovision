<?php

namespace App\Http\Controllers;

use App\Services\StationPowerControl;

class StationPowerStatusController extends Controller
{
    public function __invoke(StationPowerControl $control)
    {
        return response()->json(['boot_id' => $control->bootId(), 'pending' => $control->pending()])
            ->header('Cache-Control', 'no-store, private');
    }
}
