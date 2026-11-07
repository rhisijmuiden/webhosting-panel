<?php

// Search & replace and change domain; loaded twice by SearchReplaceServiceProvider (admin.wordpress.sr.*, client.wordpress.sr.*).

use Illuminate\Support\Facades\Route;
use Rhis\WordPress\SearchReplace\SearchReplaceController as C;

Route::get('/{install}/search-replace', [C::class, 'form'])->name('form')->whereNumber('install');
Route::post('/{install}/search-replace', [C::class, 'preview'])->name('preview')->whereNumber('install');
Route::get('/{install}/change-domain', [C::class, 'domainForm'])->name('domain')->whereNumber('install');
Route::post('/{install}/change-domain', [C::class, 'domainPreview'])->name('domain-preview')->whereNumber('install');
Route::get('/search-replace/{run}', [C::class, 'show'])->name('show')->whereNumber('run');
Route::post('/search-replace/{run}/apply', [C::class, 'apply'])->name('apply')->whereNumber('run');
