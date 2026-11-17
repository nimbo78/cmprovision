<?php

namespace App\Http\Controllers;

use App\Models\Cm;
use App\Models\StationSetupRun;

class StationSetupCodeController extends Controller
{
    public function __invoke(string $mac)
    {
        $cm = Cm::findByKey($mac);
        abort_unless($cm, 404);
        $run = StationSetupRun::availableCode($cm)->firstOrFail(['web_setup_code', 'expires_at']);
        return response()->view('auth.station-setup-code', [
            'code' => implode(' ', str_split($run->web_setup_code, 4)),
            'until' => $run->expires_at->local()->format('Y-m-d H:i T'), 'cm' => $cm,
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
