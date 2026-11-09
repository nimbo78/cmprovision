<?php

use App\Http\Controllers\ScriptExecuteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Routes
|--------------------------------------------------------------------------
|
| What the compute modules call: curl in the utility OS on the stand network. Loaded by the RouteServiceProvider with
| the "stand" middleware (App\Http\Middleware\StandNetworkOnly) instead of the "web" group: a module has no session,
| cookies or CSRF token.
|
*/

Route::any('/scriptexecute', ScriptExecuteController::class);
Route::post('/station-setup', \App\Http\Controllers\StationSetupController::class);

// Loopback-only FPM proof without the session-dependent web middleware group.
Route::get('/updates/health', function (\Illuminate\Http\Request $request) {
    abort_unless(in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true), 403);
    return response()->json(['ok' => true, 'protocol' => 1, 'php' => PHP_VERSION])->header('Cache-Control', 'no-store');
});
