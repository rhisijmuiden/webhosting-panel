<?php

use Illuminate\Support\Facades\Route;
use Rhis\Vulnerabilities\Http\AdminController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::get('/findings', [C::class, 'findings'])->name('findings');
Route::post('/scan', [C::class, 'scan'])->name('scan');
Route::post('/posture', [C::class, 'posture'])->name('posture');
Route::post('/sync', [C::class, 'sync'])->name('sync');
Route::get('/settings', [C::class, 'settings'])->name('settings');
Route::put('/settings', [C::class, 'saveSettings'])->name('settings.save');
Route::post('/findings/{finding}/ignore', [C::class, 'ignore'])->name('ignore')->whereNumber('finding');
Route::post('/findings/{finding}/reopen', [C::class, 'reopen'])->name('reopen')->whereNumber('finding');
Route::post('/customers/{customer}/notify', [C::class, 'notify'])->name('notify')->whereNumber('customer');
Route::get('/customers/{customer}/preview', [C::class, 'preview'])->name('preview')->whereNumber('customer');
