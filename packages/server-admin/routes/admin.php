<?php

use Illuminate\Support\Facades\Route;
use Rhis\ServerAdmin\Http\AdminController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::post('/updates/refresh', [C::class, 'refresh'])->name('refresh');
Route::post('/updates/install', [C::class, 'upgrade'])->name('upgrade');
Route::post('/reboot', [C::class, 'reboot'])->name('reboot');
Route::get('/runs/{run}', [C::class, 'run'])->name('run')->whereNumber('run');
Route::get('/runs/{run}/status', [C::class, 'runJson'])->name('run.status')->whereNumber('run');
Route::post('/runs/{run}/cancel', [C::class, 'cancel'])->name('cancel')->whereNumber('run');
Route::get('/maintenance', [C::class, 'maintenancePage'])->name('maintenance');
Route::put('/maintenance', [C::class, 'saveMaintenance'])->name('maintenance.save');
Route::get('/tasks', [C::class, 'tasks'])->name('tasks');
Route::put('/tasks/{key}', [C::class, 'saveTask'])->name('tasks.save')->where('key', '[a-z_]+');
Route::post('/tasks/{key}/run', [C::class, 'runTask'])->name('tasks.run')->where('key', '[a-z_]+');
Route::get('/php', [C::class, 'php'])->name('php');
Route::post('/php/install', [C::class, 'phpInstall'])->name('php.install');
Route::post('/php/remove', [C::class, 'phpRemove'])->name('php.remove');
Route::post('/php/migrate', [C::class, 'phpMigrate'])->name('php.migrate');
Route::post('/php/retry', [C::class, 'phpRetry'])->name('php.retry');
