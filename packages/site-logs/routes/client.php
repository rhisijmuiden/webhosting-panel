<?php

use Illuminate\Support\Facades\Route;
use Rhis\SiteLogs\Http\ClientLogController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::get('/{website}', [C::class, 'show'])->name('show');
Route::get('/{website}/files', [C::class, 'files'])->name('files');
Route::get('/{website}/read', [C::class, 'read'])->middleware('throttle:90,1')->name('read');
