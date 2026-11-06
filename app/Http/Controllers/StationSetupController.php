<?php

namespace App\Http\Controllers;

use App\Models\Cm;
use App\Models\StationSetupRun;
use App\Services\StationSetupSsh;
use App\Services\ModuleOperationLock;
use Illuminate\Http\Request;

class StationSetupController extends Controller
{
    public function __invoke(Request $request)
    {
        $serial = $request->input('serial');
        $nonce = $request->input('run');
        $host = $request->input('host_key');
        $address = (string) $request->server('REMOTE_ADDR');
        if (!is_string($serial) || !preg_match('/^[a-f0-9]{16}$/D', $serial) || !is_string($nonce)
            || !preg_match('/^[A-Za-z0-9]{40}$/D', $nonce) || !is_string($host) || !StationSetupSsh::hostKey($host)
            || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !str_starts_with($address, '172.20.')
            || in_array($address, ['172.20.0.0', '172.20.0.1', '172.20.0.2', '172.20.255.255'], true))
            return response('Invalid station setup request.', 403)->header('Cache-Control', 'no-store');
        return ModuleOperationLock::run([$serial], function () use ($serial, $nonce, $host, $address) {
            $cm = Cm::where('serial', $serial)->first();
            $run = $cm ? StationSetupRun::where('cm_id', $cm->id)->first() : null;
            if (!$cm || !$run || $run->expires_at->isPast() || $cm->phase !== 'postinstall'
                || !hash_equals((string) $cm->diagnostics_token, $nonce) || !hash_equals($run->run, $nonce)
                || ($run->host_key !== null && !hash_equals($run->host_key, $host)))
                return response('Station setup is not available for this run.', 409)->header('Cache-Control', 'no-store');
            if ($run->state === 'applied') return response('applied')->header('Cache-Control', 'no-store');
            $run->host_key = $host;
            $run->save();
            try {
                app(StationSetupSsh::class)->deliver($address, $host,
                    ['schema' => 1, 'serial' => $serial, 'cid' => $cm->cid, 'run' => $nonce], $run->settings);
            } catch (\Throwable $e) {
                // Do not report process exceptions: command input may contain credentials.
                return response('Station setup failed; check the board connection and image compatibility.', 502)->header('Cache-Control', 'no-store');
            }
            $run->state = 'applied';
            $run->settings = null;
            $run->save();
            return response('applied')->header('Cache-Control', 'no-store');
        });
    }
}
