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
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->get('/cms/{serial}', CmCard::class)->name('cm');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->get('/ports', Ports::class)->name('ports');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/images', Images::class)->name('images');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->post('/addImage', [AddImageController::class, 'store']);
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->post('/addPackage', [AddPackageController::class, 'store'])->name('packages.add');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/projects', Projects::class)->name('projects');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/scripts', Scripts::class)->name('scripts');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/labels', Labels::class)->name('labels');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/firmware', Firmwares::class)->name('firmware');
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->any('/settings', Settings::class)->name('settings');
