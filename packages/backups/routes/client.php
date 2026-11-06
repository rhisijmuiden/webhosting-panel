<?php

use Illuminate\Support\Facades\Route;
use Rhis\Backups\Http\ClientBackupController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::post('/restore', [C::class, 'restore'])->name('restore');
Route::get('/jobs/{job}', [C::class, 'job'])->name('jobs.show');
