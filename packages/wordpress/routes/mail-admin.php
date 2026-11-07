<?php

use Illuminate\Support\Facades\Route;
use Rhis\WordPress\Mail\Http\AdminWpMailController as C;

Route::get('/{install}/mail', [C::class, 'show'])->name('mail.show')->whereNumber('install');
Route::put('/{install}/mail', [C::class, 'update'])->name('mail.update')->whereNumber('install');
Route::post('/{install}/mail/test', [C::class, 'test'])->name('mail.test')->whereNumber('install');
