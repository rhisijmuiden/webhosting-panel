<?php

use Illuminate\Support\Facades\Route;
use Rhis\Migration\Http\MigrationController as C;
use Rhis\Migration\Http\StatusController as S;

Route::get('/', [C::class, 'index'])->name('index');
Route::get('/status', [S::class, 'index'])->name('status');
Route::get('/status/feed', [S::class, 'feed'])->name('status.feed');
Route::post('/sources', [C::class, 'storeSource'])->name('sources.store');
Route::get('/sources/{source}', [C::class, 'showSource'])->name('sources.show');
Route::post('/sources/{source}/test', [C::class, 'testSource'])->name('sources.test');
Route::post('/sources/{source}/forget-host-key', [C::class, 'forgetHostKey'])->name('sources.forget');
Route::post('/sources/{source}/inventory', [C::class, 'inventory'])->name('sources.inventory');
Route::post('/sources/{source}/migrate', [C::class, 'migrate'])->name('sources.migrate');
Route::post('/sources/{source}/sync', [C::class, 'syncAll'])->name('sources.sync');
Route::delete('/sources/{source}', [C::class, 'destroySource'])->name('sources.destroy');
Route::get('/items/{item}', [C::class, 'showItem'])->name('items.show');
Route::post('/items/{item}/sync', [C::class, 'syncItem'])->name('items.sync');
Route::post('/items/{item}/retry', [C::class, 'retryItem'])->name('items.retry');
Route::post('/items/{item}/cutover', [C::class, 'cutover'])->name('items.cutover');
Route::post('/items/{item}/finish', [C::class, 'finishItem'])->name('items.finish');
Route::post('/items/{item}/wordpress', [C::class, 'fixWordpress'])->name('items.wordpress');
