<?php

use Illuminate\Support\Facades\Route;
use Rhis\Security\Http\AdminProtectionController as C;

Route::get('/', [C::class, 'edit'])->name('edit');
Route::put('/', [C::class, 'update'])->name('update');
Route::post('/apply', [C::class, 'apply'])->name('apply');
Route::post('/allowlist', [C::class, 'allow'])->name('allowlist.store');
Route::delete('/allowlist', [C::class, 'disallow'])->name('allowlist.destroy');
