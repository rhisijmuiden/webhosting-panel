<?php

use Illuminate\Support\Facades\Route;
use Rhis\WordPress\DbCleanup\AdminDbCleanupController as C;

Route::get('/{install}/database-cleanup', [C::class, 'show'])->name('show')->whereNumber('install');
Route::post('/{install}/database-cleanup', [C::class, 'run'])->name('run')->whereNumber('install');
Route::get('/database-cleanups/{cleanup}', [C::class, 'status'])->name('status')->whereNumber('cleanup');
