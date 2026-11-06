<?php

use Illuminate\Support\Facades\Route;
use Rhis\Monitoring\Http\AdminController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::post('/run', [C::class, 'runNow'])->name('run');
Route::get('/websites/{website}', [C::class, 'website'])->name('website')->whereNumber('website');
Route::get('/incidents', [C::class, 'incidents'])->name('incidents');
Route::get('/alerts', [C::class, 'alerts'])->name('alerts');
Route::post('/alerts', [C::class, 'storeChannel'])->name('channels.store');
Route::put('/alerts/{channel}', [C::class, 'updateChannel'])->name('channels.update')->whereNumber('channel');
Route::post('/alerts/{channel}/test', [C::class, 'testChannel'])->name('channels.test')->whereNumber('channel');
Route::delete('/alerts/{channel}', [C::class, 'destroyChannel'])->name('channels.destroy')->whereNumber('channel');
Route::get('/settings', [C::class, 'settings'])->name('settings');
Route::put('/settings', [C::class, 'saveSettings'])->name('settings.save');
Route::get('/status-page', [C::class, 'statusPage'])->name('status');
Route::post('/status-page/notices', [C::class, 'storeNotice'])->name('notices.store');
Route::post('/status-page/notices/{notice}/resolve', [C::class, 'resolveNotice'])->name('notices.resolve')->whereNumber('notice');
Route::delete('/status-page/notices/{notice}', [C::class, 'destroyNotice'])->name('notices.destroy')->whereNumber('notice');
