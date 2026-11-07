<?php

use Illuminate\Support\Facades\Route;
use Rhis\Cron\Http\ClientCronController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::get('/create', [C::class, 'create'])->name('create');
Route::post('/', [C::class, 'store'])->name('store');
Route::post('/preview', [C::class, 'preview'])->name('preview');
Route::get('/{cronjob}', [C::class, 'show'])->name('show')->whereNumber('cronjob');
Route::put('/{cronjob}', [C::class, 'update'])->name('update')->whereNumber('cronjob');
Route::delete('/{cronjob}', [C::class, 'destroy'])->name('destroy')->whereNumber('cronjob');
Route::post('/{cronjob}/run', [C::class, 'run'])->name('run')->whereNumber('cronjob');
Route::post('/{cronjob}/stop', [C::class, 'stop'])->name('stop')->whereNumber('cronjob');
Route::post('/{cronjob}/toggle', [C::class, 'toggle'])->name('toggle')->whereNumber('cronjob');
Route::get('/{cronjob}/poll', [C::class, 'poll'])->name('poll')->whereNumber('cronjob');
Route::get('/{cronjob}/runs/{run}', [C::class, 'output'])->name('output')->whereNumber(['cronjob', 'run']);
