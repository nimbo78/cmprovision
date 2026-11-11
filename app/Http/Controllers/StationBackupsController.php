<?php

namespace App\Http\Controllers;

use App\Services\StationBackupsControl;

class StationBackupsController extends Controller
{
    public function status(StationBackupsControl $control)
    {
        return response()->json($control->request(['action' => 'status']))->header('Cache-Control', 'no-store');
    }

    public function download(string $id, StationBackupsControl $control)
    {
        try {
            $info = $control->request(['action' => 'download-info', 'id' => $id]);
            abort_unless(preg_match('/\A[0-9]{8}T[0-9]{6}Z-[0-9a-f]{32}\.cmpbackup\z/D', $info['name'] ?? '')
                && is_int($info['bytes'] ?? null) && $info['bytes'] > 0, 409);
            [$iterator, $first] = $control->download($id);
        } catch (\RuntimeException $error) {
            return redirect()->route('settings', ['tab' => 'system', '#backup'])->with('backupError', $error->getMessage());
        }
        return response()->streamDownload(function () use ($iterator, $first) {
            echo $first;
            foreach ($iterator as $chunk) { echo $chunk; if (connection_aborted()) break; }
        }, $info['name'], ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $info['bytes'],
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Accel-Buffering' => 'no']);
    }
}
