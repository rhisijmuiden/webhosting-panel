<?php

// Shared by the client (/files) and admin (/admin/files) areas; $controller is set by the provider.

use Illuminate\Support\Facades\Route;

Route::get('/', [$controller, 'index'])->name('index');
Route::prefix('{website}')->whereNumber('website')->group(function () use ($controller) {
    Route::get('/', [$controller, 'browse'])->name('browse');
    Route::get('/list', [$controller, 'list'])->name('list');
    Route::get('/search', [$controller, 'search'])->name('search');
    Route::get('/edit', [$controller, 'edit'])->name('edit');
    Route::get('/read', [$controller, 'read'])->name('read');
    Route::put('/write', [$controller, 'write'])->name('write');
    Route::post('/upload', [$controller, 'upload'])->name('upload');
    Route::get('/download', [$controller, 'download'])->name('download');
    Route::post('/mkdir', [$controller, 'mkdir'])->name('mkdir');
    Route::post('/rename', [$controller, 'rename'])->name('rename');
    Route::post('/move', [$controller, 'move'])->name('move');
    Route::post('/copy', [$controller, 'copy'])->name('copy');
    Route::post('/delete', [$controller, 'delete'])->name('delete');
    Route::post('/chmod', [$controller, 'chmod'])->name('chmod');
    Route::post('/compress', [$controller, 'compress'])->name('compress');
    Route::post('/extract', [$controller, 'extract'])->name('extract');
    Route::get('/jobs/{job}', [$controller, 'job'])->name('job')->where('job', '[0-9a-f-]{36}');
    Route::get('/terminal', [$controller, 'terminal'])->name('terminal');
    Route::post('/terminal', [$controller, 'terminalStart'])->name('terminal.start');
    Route::delete('/terminal', [$controller, 'terminalStop'])->name('terminal.stop');
});
