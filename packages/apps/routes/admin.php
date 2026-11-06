<?php

use Illuminate\Support\Facades\Route;
use Rhis\Apps\Http\AdminAppController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::get('/install/{app}', [C::class, 'create'])->name('create');
Route::post('/install/{app}', [C::class, 'store'])->name('store');
Route::get('/{installation}', [C::class, 'show'])->name('show');
Route::delete('/{installation}', [C::class, 'destroy'])->name('destroy');
