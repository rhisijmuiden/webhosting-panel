<?php

use Illuminate\Support\Facades\Route;
use Rhis\Security\Http\ClientProtectionController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::get('/{website}', [C::class, 'show'])->name('show');
Route::put('/{website}', [C::class, 'update'])->name('update');
Route::post('/{website}/allowlist', [C::class, 'allow'])->name('allowlist.store');
Route::delete('/{website}/allowlist', [C::class, 'disallow'])->name('allowlist.destroy');
