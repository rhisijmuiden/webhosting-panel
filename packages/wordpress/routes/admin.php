<?php

use Illuminate\Support\Facades\Route;
use Rhis\WordPress\Http\AdminWordPressController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::post('/scan', [C::class, 'scan'])->name('scan');
Route::post('/update-outdated', [C::class, 'updateOutdated'])->name('update-outdated');
Route::get('/jobs/{job}', [C::class, 'job'])->name('jobs.show');
Route::get('/{install}', [C::class, 'show'])->name('show')->whereNumber('install');
Route::post('/{install}/refresh', [C::class, 'refresh'])->name('refresh')->whereNumber('install');
Route::post('/{install}/update', [C::class, 'update'])->name('update')->whereNumber('install');
Route::put('/{install}/cron', [C::class, 'cron'])->name('cron')->whereNumber('install');
Route::post('/{install}/password', [C::class, 'password'])->name('password')->whereNumber('install');
Route::post('/{install}/login', [C::class, 'login'])->name('login')->whereNumber('install');
Route::post('/{install}/restore', [C::class, 'restore'])->name('restore')->whereNumber('install');
