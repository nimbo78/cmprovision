<?php

namespace App\Http\Controllers;

use App\Services\StationUpdatesControl;

class StationUpdatesStatusController extends Controller
{
    public function __invoke(StationUpdatesControl $control)
    {
        return response()->json($control->request(['action' => 'status']))->header('Cache-Control', 'no-store');
    }
}
