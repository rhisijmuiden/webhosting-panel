<?php

use Illuminate\Support\Facades\Route;
use Rhis\Backups\Http\AdminBackupController as C;

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
