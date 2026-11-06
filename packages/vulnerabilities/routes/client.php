<?php

use Illuminate\Support\Facades\Route;
use Rhis\Vulnerabilities\Http\ClientController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::post('/scan', [C::class, 'scan'])->name('scan')->middleware('throttle:6,60');
Route::put('/notifications', [C::class, 'notifications'])->name('notifications');
