<?php

use Illuminate\Support\Facades\Route;
use Rhis\WebRules\Http\AdminWebRulesController as C;

Route::get('/{website}', [C::class, 'show'])->name('show');
Route::post('/{website}/redirects', [C::class, 'storeRedirect'])->name('redirects.store');
Route::delete('/{website}/redirects/{id}', [C::class, 'destroyRedirect'])->name('redirects.destroy');
Route::post('/{website}/folders', [C::class, 'storeFolder'])->name('folders.store');
Route::delete('/{website}/folders/{id}', [C::class, 'destroyFolder'])->name('folders.destroy');
Route::post('/{website}/folders/{id}/users', [C::class, 'storeUser'])->name('folders.users.store');
Route::delete('/{website}/folders/{id}/users', [C::class, 'destroyUser'])->name('folders.users.destroy');
Route::put('/{website}/error-pages', [C::class, 'updateErrorPages'])->name('error-pages.update');
Route::put('/{website}/https', [C::class, 'updateHttps'])->name('https.update');
Route::put('/{website}/access', [C::class, 'updateAccess'])->name('access.update');
