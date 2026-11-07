<?php

use Illuminate\Support\Facades\Route;
use Rhis\Subdomains\Staging\Http\ClientStagingController as C;

Route::get('websites/{website}/staging', [C::class, 'index'])->name('staging.index');
Route::post('websites/{website}/staging', [C::class, 'store'])->name('staging.store');
Route::get('staging/{staging}', [C::class, 'show'])->name('staging.show');
Route::post('staging/{staging}/push', [C::class, 'push'])->name('staging.push');
Route::post('staging/{staging}/refresh', [C::class, 'refresh'])->name('staging.refresh');
Route::post('staging/{staging}/restore', [C::class, 'restore'])->name('staging.restore');
Route::delete('staging/{staging}', [C::class, 'destroy'])->name('staging.destroy');
