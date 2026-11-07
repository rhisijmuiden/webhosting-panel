<?php

use Illuminate\Support\Facades\Route;
use Rhis\ObjectCache\Http\ClientObjectCacheController as C;

Route::put('/{website}', [C::class, 'update'])->name('update')->whereNumber('website');
Route::post('/{website}/flush', [C::class, 'flush'])->name('flush')->whereNumber('website');
