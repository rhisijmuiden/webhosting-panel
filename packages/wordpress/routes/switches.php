<?php

// Quick switches and the debug log; loaded for admin.wordpress.* and client.wordpress.* (same paths).

use Illuminate\Support\Facades\Route;
use Rhis\WordPress\Switches\Http\SwitchesController as C;

Route::prefix('{install}')->where(['install' => '[0-9]+'])->name('switches.')->group(function () {
    Route::put('/switches/maintenance', [C::class, 'maintenance'])->name('maintenance');
    Route::put('/switches/debug', [C::class, 'debug'])->name('debug');
    Route::put('/switches/indexing', [C::class, 'indexing'])->name('indexing');
    Route::post('/switches/cache', [C::class, 'cache'])->name('cache');
    Route::post('/switches/refresh', [C::class, 'refresh'])->name('refresh');
    Route::get('/debug-log', [C::class, 'logPage'])->name('log');
    Route::get('/debug-log/entries', [C::class, 'entries'])->name('log.entries');
    Route::post('/debug-log/clear', [C::class, 'clearLog'])->name('log.clear');
});
