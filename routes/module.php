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
