<?php

use App\Http\Controllers\WpHardening\ClientHardeningController as C;
use Illuminate\Support\Facades\Route;

// WordPress security hardening (App\Providers\WpHardeningServiceProvider).
Route::prefix('wordpress-hardening')->name('wp-hardening.')->group(function () {
    Route::get('/{install}', [C::class, 'show'])->name('show')->whereNumber('install');
    Route::put('/{install}', [C::class, 'update'])->name('update')->whereNumber('install');
    Route::post('/{install}/all', [C::class, 'all'])->name('all')->whereNumber('install');
    Route::post('/{install}/salts', [C::class, 'salts'])->name('salts')->whereNumber('install');
});
