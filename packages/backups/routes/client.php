<?php

use Illuminate\Support\Facades\Route;
use Rhis\Backups\Http\ClientBackupController as C;
use Rhis\Backups\Http\ClientLocationController as L;
use Rhis\Backups\Http\ClientUploadController as U;

Route::get('/', [C::class, 'index'])->name('index');
Route::post('/run', [C::class, 'run'])->name('run');
Route::post('/restore', [C::class, 'restore'])->name('restore');
Route::get('/jobs/{job}', [C::class, 'job'])->name('jobs.show');

Route::get('/locations/create', [L::class, 'create'])->name('locations.create');
Route::post('/locations', [L::class, 'store'])->name('locations.store');
Route::get('/locations/{target}', [L::class, 'show'])->whereNumber('target')->name('locations.show');
Route::get('/locations/{target}/edit', [L::class, 'edit'])->whereNumber('target')->name('locations.edit');
Route::put('/locations/{target}', [L::class, 'update'])->whereNumber('target')->name('locations.update');
Route::delete('/locations/{target}', [L::class, 'destroy'])->whereNumber('target')->name('locations.destroy');
Route::post('/locations/{target}/test', [L::class, 'test'])->whereNumber('target')->name('locations.test');
Route::post('/locations/{target}/restore', [L::class, 'restore'])->whereNumber('target')->name('locations.restore');
Route::delete('/locations/{target}/files', [L::class, 'destroyFile'])->whereNumber('target')->name('locations.files.destroy');

Route::post('/uploads', [U::class, 'start'])->name('uploads.start');
Route::post('/uploads/{upload}/chunk', [U::class, 'chunk'])->name('uploads.chunk');
Route::post('/uploads/{upload}/inspect', [U::class, 'inspect'])->name('uploads.inspect');
Route::post('/uploads/{upload}/restore', [U::class, 'restore'])->name('uploads.restore');
Route::delete('/uploads/{upload}', [U::class, 'destroy'])->name('uploads.destroy');
