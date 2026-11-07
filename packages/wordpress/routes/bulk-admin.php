<?php

use Illuminate\Support\Facades\Route;
use Rhis\WordPress\Bulk\Http\BulkController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::post('/', [C::class, 'store'])->name('store');
Route::get('/runs/{run}', [C::class, 'show'])->name('show')->whereNumber('run');
Route::get('/runs/{run}/status', [C::class, 'status'])->name('status')->whereNumber('run');
Route::post('/runs/{run}/cancel', [C::class, 'cancel'])->name('cancel')->whereNumber('run');
Route::post('/runs/{run}/resume', [C::class, 'resume'])->name('resume')->whereNumber('run');
Route::post('/runs/{run}/retry', [C::class, 'retry'])->name('retry')->whereNumber('run');
