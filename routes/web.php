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

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->get('/dashboard', function () {
    return view('dashboard');   // progress panel and log are Livewire components that refresh themselves
})->name('dashboard');

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/cms', Cms::class)->name('cms');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->get('/cms/{mac}', CmCard::class)->name('cm');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->get('/ports', Ports::class)->name('ports');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/images', Images::class)->name('images');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->post('/addImage', [AddImageController::class, 'store']);
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->post('/addPackage', [AddPackageController::class, 'store'])->name('packages.add');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/projects', Projects::class)->name('projects');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/scripts', Scripts::class)->name('scripts');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/labels', Labels::class)->name('labels');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/firmware', Firmwares::class)->name('firmware');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/settings', Settings::class)->name('settings');
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
