<?php

use Illuminate\Support\Facades\Route;
use Rhis\WpIntegrity\Http\ClientIntegrityController as C;

Route::get('wordpress-integrity', [C::class, 'index'])->name('wp-integrity.index');
Route::get('wordpress-integrity/{install}', [C::class, 'show'])->whereNumber('install')->name('wp-integrity.show');
Route::post('wordpress-integrity/{install}/check', [C::class, 'check'])->whereNumber('install')->name('wp-integrity.check');
Route::post('wordpress-integrity/{install}/restore', [C::class, 'restore'])->whereNumber('install')->name('wp-integrity.restore');
Route::post('wordpress-integrity/{install}/accept', [C::class, 'accept'])->whereNumber('install')->name('wp-integrity.accept');
