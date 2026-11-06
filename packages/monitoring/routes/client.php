<?php

use Illuminate\Support\Facades\Route;
use Rhis\Monitoring\Http\ClientController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::put('/alerts', [C::class, 'saveAlerts'])->name('alerts');
