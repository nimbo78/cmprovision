<?php

namespace App\Http\Controllers;

use App\Services\StationUpdatesControl;

class StationUpdatesDiagnosticsController extends Controller
{
    public function __invoke(string $id, StationUpdatesControl $control)
    {
        abort_unless(preg_match('/\A[0-9a-f]{32}\z/D', $id), 404);
        try {
            $report = $control->request(['action' => 'diagnostics', 'id' => $id]);
        } catch (\RuntimeException $error) {
            abort(422, $error->getMessage());
        }
        abort_unless(($report['schema'] ?? null) === 1 && ($report['id'] ?? null) === $id
            && is_array($report['failures'] ?? null) && strlen(json_encode($report, JSON_THROW_ON_ERROR)) <= 65536,
            422, 'The updater diagnostic report is invalid or exceeds its size limit.');
        return response()->json($report)->header('Cache-Control', 'no-store')
            ->header('Content-Disposition', 'attachment; filename="cmprovision-update-'.$id.'-diagnostics.json"')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
