<?php

use Illuminate\Support\Facades\Route;
use Rhis\Waf\Http\ClientWafController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::get('/{website}', [C::class, 'show'])->name('show');
Route::put('/{website}', [C::class, 'update'])->name('update');
Route::post('/{website}/exceptions', [C::class, 'addException'])->name('exceptions.store');
Route::delete('/{website}/exceptions/{index}', [C::class, 'removeException'])->whereNumber('index')->name('exceptions.destroy');
Route::get('/{website}/events/{event}', [C::class, 'event'])->name('event');
Route::post('/{website}/events/{event}/allow', [C::class, 'allow'])->name('events.allow');
