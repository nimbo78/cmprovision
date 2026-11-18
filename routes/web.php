<?php

use Illuminate\Support\Facades\Route;
use App\Http\Livewire\Cms;
use App\Http\Livewire\CmCard;
use App\Http\Livewire\Ports;
use App\Http\Livewire\Images;
use App\Http\Livewire\Labels;
use App\Http\Livewire\Projects;
use App\Http\Livewire\Scripts;
use App\Http\Livewire\Firmwares;
use App\Http\Livewire\Settings;
use App\Http\Controllers\AddImageController;
use App\Http\Controllers\AddPackageController;
use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\StationSetupCodeController;
use App\Http\Middleware\RequireSetupCodePassword;

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])
    ->get('/settings/backups/status', [\App\Http\Controllers\StationBackupsController::class, 'status'])->name('settings.backups.status');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'password.confirm'])
    ->get('/settings/backups/confirm', fn () => redirect()->route('settings', ['tab' => 'system', '#backup']))->name('settings.backups.confirm');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'password.confirm'])
    ->get('/settings/backups/{id}/download', [\App\Http\Controllers\StationBackupsController::class, 'download'])
    ->where('id', '[0-9a-f]{32}')->name('settings.backups.download');

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    //return view('welcome');
    return redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/setup', [\App\Http\Controllers\FirstAdminController::class, 'show'])->name('first-admin');
    Route::post('/setup', [\App\Http\Controllers\FirstAdminController::class, 'claim'])->middleware('throttle:first-admin');
});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->get('/dashboard', function () {
    return view('dashboard');   // progress panel and log are Livewire components that refresh themselves
})->name('dashboard');

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/cms', Cms::class)->name('cms');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->get('/cms/{mac}', CmCard::class)->name('cm');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->prefix('cms/{mac}/setup-code')->group(function () {
    Route::get('/', StationSetupCodeController::class)->middleware(RequireSetupCodePassword::class)->name('cm.setup-code');
    Route::view('/confirm-password', 'auth.confirm-password')->name('cm.setup-code.confirm');
    Route::post('/confirm-password', [StationSetupCodeController::class, 'confirmPassword']);
});
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])
    ->get('/cms/{mac}/report/{format}', [\App\Http\Controllers\ModuleReportController::class, 'module'])
    ->whereIn('format', ['html', 'json'])->name('cm.report');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])
    ->get('/diagnostics/{id}/report/{format}', [\App\Http\Controllers\ModuleReportController::class, 'usb'])
    ->whereNumber('id')->whereIn('format', ['html', 'json'])->name('diagnostics.report');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->get('/ports', Ports::class)->name('ports');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->get('/reprovision', \App\Http\Livewire\ReprovisionPage::class)->name('reprovision');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/images', Images::class)->name('images');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->post('/addImage', [AddImageController::class, 'store']);
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->post('/addPackage', [AddPackageController::class, 'store'])->name('packages.add');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/projects', Projects::class)->name('projects');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/scripts', Scripts::class)->name('scripts');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/labels', Labels::class)->name('labels');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/firmware', Firmwares::class)->name('firmware');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/settings', Settings::class)->name('settings');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->get('/settings/setup', \App\Http\Livewire\StationSetupWizard::class)->name('settings.setup');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])
    ->get('/settings/updates/status', \App\Http\Controllers\StationUpdatesStatusController::class)->name('settings.updates.status');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])
    ->get('/settings/power/status', \App\Http\Controllers\StationPowerStatusController::class)->name('settings.power.status');
// the copy of the data holds the application key and every password of the server: the password is asked again
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'password.confirm'])->group(function () {
    Route::get('/settings/updates/{id}/diagnostics', \App\Http\Controllers\StationUpdatesDiagnosticsController::class)
        ->where('id', '[0-9a-f]{32}')->name('settings.updates.diagnostics');
    Route::get('/settings/backup', [BackupDownloadController::class, 'show'])->name('settings.backup');
    Route::post('/settings/backup', BackupDownloadController::class)->name('settings.backup.download');
});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/settings/transfer', \App\Http\Livewire\ConfigurationTransfer::class)->name('settings.transfer');
    Route::post('/settings/transfer/import', [\App\Http\Controllers\ConfigurationTransferController::class, 'store'])->name('settings.transfer.import');
    Route::get('/settings/transfer/{operation}/download', [\App\Http\Controllers\ConfigurationTransferController::class, 'download'])
        ->middleware('password.confirm')->name('settings.transfer.download');
});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->get('/diagnostics/{id?}', \App\Http\Livewire\UsbDiagnostics::class)->whereNumber('id')->name('diagnostics');
