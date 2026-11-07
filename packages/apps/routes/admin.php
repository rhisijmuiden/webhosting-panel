<?php

use Illuminate\Support\Facades\Route;
use Rhis\Apps\Http\AdminAppController as C;
use Rhis\Apps\PluginSets\AdminPluginSetController as S;

Route::get('/', [C::class, 'index'])->name('index');
Route::get('/install/{app}', [C::class, 'create'])->name('create');
Route::post('/install/{app}', [C::class, 'store'])->name('store');

// WordPress plugin sets (before /{installation}, which would catch these paths).
Route::get('/plugin-sets', [S::class, 'index'])->name('sets.index');
Route::get('/plugin-sets/new', [S::class, 'create'])->name('sets.create');
Route::post('/plugin-sets', [S::class, 'store'])->name('sets.store');
Route::get('/plugin-sets/{set}', [S::class, 'edit'])->name('sets.edit');
Route::put('/plugin-sets/{set}', [S::class, 'update'])->name('sets.update');
Route::delete('/plugin-sets/{set}', [S::class, 'destroy'])->name('sets.destroy');

Route::get('/{installation}', [C::class, 'show'])->name('show');
Route::delete('/{installation}', [C::class, 'destroy'])->name('destroy');
