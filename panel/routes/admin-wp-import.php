<?php

use App\Http\Controllers\WpImport\AdminWpImportController as C;
use Illuminate\Support\Facades\Route;

// WordPress import wizard (App\Providers\WpImportServiceProvider).
Route::prefix('import-wordpress')->name('wp-import.')->group(function () {
    Route::get('/', [C::class, 'index'])->name('index');
    Route::post('/', [C::class, 'store'])->name('store');
    Route::get('/{wpImport}', [C::class, 'show'])->name('show')->whereNumber('wpImport');
    Route::post('/{wpImport}/files/{kind}', [C::class, 'uploadStart'])->name('upload')->whereNumber('wpImport')->whereIn('kind', ['site', 'database']);
    Route::post('/{wpImport}/files/{kind}/chunk', [C::class, 'uploadChunk'])->name('chunk')->whereNumber('wpImport')->whereIn('kind', ['site', 'database']);
    Route::post('/{wpImport}/check', [C::class, 'check'])->name('check')->whereNumber('wpImport');
    Route::post('/{wpImport}/run', [C::class, 'run'])->name('run')->whereNumber('wpImport');
    Route::post('/{wpImport}/reset', [C::class, 'reset'])->name('reset')->whereNumber('wpImport');
    Route::delete('/{wpImport}', [C::class, 'destroy'])->name('destroy')->whereNumber('wpImport');
});
