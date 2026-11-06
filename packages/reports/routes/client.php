<?php

use Illuminate\Support\Facades\Route;
use Rhis\Reports\Http\ClientController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::get('/view', [C::class, 'view'])->name('view');
Route::put('/delivery', [C::class, 'delivery'])->name('delivery');
