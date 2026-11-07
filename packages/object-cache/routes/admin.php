<?php

use Illuminate\Support\Facades\Route;
use Rhis\ObjectCache\Http\AdminObjectCacheController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::put('/{website}', [C::class, 'update'])->name('update')->whereNumber('website');
Route::post('/{website}/flush', [C::class, 'flush'])->name('flush')->whereNumber('website');
