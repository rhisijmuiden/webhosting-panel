<?php

use Illuminate\Support\Facades\Route;
use Rhis\Waf\Http\AdminWafController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::get('/sites', [C::class, 'sites'])->name('sites');
Route::get('/sites/{website}', [C::class, 'site'])->name('site');
Route::put('/sites/{website}', [C::class, 'updateSite'])->name('site.update');
Route::post('/sites/{website}/exceptions', [C::class, 'addSiteException'])->name('site.exceptions.store');
Route::delete('/sites/{website}/exceptions/{index}', [C::class, 'removeSiteException'])->whereNumber('index')->name('site.exceptions.destroy');
Route::get('/settings', [C::class, 'settings'])->name('settings');
Route::put('/settings', [C::class, 'updateSettings'])->name('settings.update');
Route::post('/exceptions', [C::class, 'addException'])->name('exceptions.store');
Route::delete('/exceptions/{index}', [C::class, 'removeException'])->whereNumber('index')->name('exceptions.destroy');
Route::get('/events/{event}', [C::class, 'event'])->name('event');
Route::post('/events/{event}/allow', [C::class, 'allow'])->name('events.allow');
Route::post('/apply', [C::class, 'apply'])->name('apply');
Route::post('/collect', [C::class, 'collect'])->name('collect');
