<?php

// Same pages for administrators and customers; $C is set by admin.php / client.php.

use Illuminate\Support\Facades\Route;

/** @var class-string $C */

Route::get('/', [$C, 'index'])->name('index');
Route::get('/websites/{website}', [$C, 'show'])->name('show');
Route::post('/websites/{website}/custom', [$C, 'upload'])->name('custom');
Route::post('/websites/{website}/csr', [$C, 'csr'])->name('csr');
Route::get('/websites/{website}/csr', [$C, 'csrDownload'])->name('csr.download');
Route::delete('/websites/{website}/csr', [$C, 'csrDestroy'])->name('csr.destroy');
Route::post('/websites/{website}/wildcard', [$C, 'wildcard'])->name('wildcard');
Route::post('/websites/{website}/letsencrypt', [$C, 'letsencrypt'])->name('letsencrypt');
Route::post('/websites/{website}/refresh', [$C, 'refresh'])->name('refresh');
