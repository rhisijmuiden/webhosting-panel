<?php

use Illuminate\Support\Facades\Route;
use Rhis\DomainAliases\Http\ClientAliasController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::post('/websites/{website}', [C::class, 'store'])->name('store');
Route::put('/{alias}', [C::class, 'update'])->name('update');
Route::post('/{alias}/ssl', [C::class, 'ssl'])->name('ssl');
Route::delete('/{alias}', [C::class, 'destroy'])->name('destroy');
Route::get('/{alias}/convert', [C::class, 'convertForm'])->name('convert');
Route::post('/{alias}/convert', [C::class, 'convert'])->name('convert.store');
Route::post('/{alias}/upgrade', [C::class, 'upgrade'])->name('upgrade');
