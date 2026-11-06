<?php

use Illuminate\Support\Facades\Route;
use Rhis\Reports\Http\AdminController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::put('/settings', [C::class, 'saveSettings'])->name('settings.save');
Route::get('/customers/{customer}/preview', [C::class, 'preview'])->name('preview')->whereNumber('customer');
Route::post('/customers/{customer}/send', [C::class, 'send'])->name('send')->whereNumber('customer');
Route::put('/customers/{customer}/delivery', [C::class, 'delivery'])->name('delivery')->whereNumber('customer');
Route::post('/send-all', [C::class, 'sendAll'])->name('send-all');
