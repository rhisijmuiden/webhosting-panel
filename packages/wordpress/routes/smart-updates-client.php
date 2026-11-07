<?php

use Illuminate\Support\Facades\Route;
use Rhis\WordPress\SmartUpdates\Http\ClientSmartUpdatesController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::post('/bulk', [C::class, 'bulk'])->name('bulk');
Route::get('/sites/{install}', [C::class, 'install'])->name('install')->whereNumber('install');
Route::put('/sites/{install}', [C::class, 'save'])->name('save')->whereNumber('install');
Route::post('/sites/{install}/run', [C::class, 'run'])->name('run')->whereNumber('install');
Route::get('/runs/{run}', [C::class, 'show'])->name('runs.show');
Route::get('/runs/{run}/status', [C::class, 'status'])->name('runs.status');
Route::get('/runs/{run}/images/{name}', [C::class, 'image'])->name('runs.image')->where('name', 'p[0-9]-(desktop|mobile)-(before|after|diff|restored)\.jpg');
