<?php

use Illuminate\Support\Facades\Route;
use Rhis\WebApps\Http\ClientWebAppController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::get('/create', [C::class, 'create'])->name('create');
Route::post('/', [C::class, 'store'])->name('store');
Route::get('/{webapp}', [C::class, 'show'])->name('show')->whereNumber('webapp');
Route::put('/{webapp}', [C::class, 'update'])->name('update')->whereNumber('webapp');
Route::delete('/{webapp}', [C::class, 'destroy'])->name('destroy')->whereNumber('webapp');
Route::post('/{webapp}/control', [C::class, 'control'])->name('control')->whereNumber('webapp');
Route::post('/{webapp}/install', [C::class, 'install'])->name('install')->whereNumber('webapp');
Route::get('/{webapp}/poll', [C::class, 'poll'])->name('poll')->whereNumber('webapp');
