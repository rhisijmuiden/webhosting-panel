<?php

use Illuminate\Support\Facades\Route;
use Rhis\Backups\Http\AdminBackupController as C;
use Rhis\Backups\Http\AdminBrowserController as B;
use Rhis\Backups\Http\AdminRestoreTestController as RT;

Route::get('/', [C::class, 'index'])->name('index');
Route::post('/run', [C::class, 'run'])->name('run');
Route::post('/restore', [C::class, 'restore'])->name('restore');
Route::put('/schedule', [C::class, 'schedule'])->name('schedule');
Route::post('/targets', [C::class, 'storeTarget'])->name('targets.store');
Route::post('/targets/{target}/test', [C::class, 'testTarget'])->name('targets.test');
Route::post('/targets/{target}/check', [C::class, 'checkTarget'])->name('targets.check');
Route::post('/targets/{target}/key', [C::class, 'exportKey'])->name('targets.key');
Route::delete('/targets/{target}', [C::class, 'destroyTarget'])->name('targets.destroy');
Route::get('/jobs/{job}', [C::class, 'job'])->name('jobs.show');

Route::get('/browse', [B::class, 'index'])->name('browse');
Route::post('/browse/restore', [B::class, 'restore'])->name('browse.restore');
Route::get('/customers', [B::class, 'customers'])->name('customers');

// Automatic restore tests
Route::get('/restore-tests', [RT::class, 'index'])->name('restore-tests.index');
Route::post('/restore-tests', [RT::class, 'store'])->name('restore-tests.store');
Route::put('/restore-tests/settings', [RT::class, 'settings'])->name('restore-tests.settings');
Route::get('/restore-tests/{test}', [RT::class, 'show'])->whereNumber('test')->name('restore-tests.show');
