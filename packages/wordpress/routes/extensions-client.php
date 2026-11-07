<?php

use Illuminate\Support\Facades\Route;
use Rhis\WordPress\Extensions\Http\ClientExtensionsController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::post('/apply', [C::class, 'apply'])->name('apply');
Route::post('/install', [C::class, 'install'])->name('install');
