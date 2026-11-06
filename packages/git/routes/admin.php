<?php

use Illuminate\Support\Facades\Route;
use Rhis\Git\Http\AdminGitController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::get('/create', [C::class, 'create'])->name('create');
Route::post('/', [C::class, 'store'])->name('store');
Route::get('/{repo}', [C::class, 'show'])->name('show')->whereNumber('repo');
Route::put('/{repo}', [C::class, 'update'])->name('update')->whereNumber('repo');
Route::delete('/{repo}', [C::class, 'destroy'])->name('destroy')->whereNumber('repo');
Route::post('/{repo}/check', [C::class, 'check'])->name('check')->whereNumber('repo');
Route::get('/{repo}/branches', [C::class, 'branches'])->name('branches')->whereNumber('repo');
Route::post('/{repo}/fetch', [C::class, 'fetch'])->name('fetch')->whereNumber('repo');
Route::post('/{repo}/deploy', [C::class, 'deploy'])->name('deploy')->whereNumber('repo');
Route::post('/{repo}/rollback', [C::class, 'rollback'])->name('rollback')->whereNumber('repo');
Route::post('/{repo}/key', [C::class, 'rotateKey'])->name('key')->whereNumber('repo');
Route::post('/{repo}/secret', [C::class, 'rotateSecret'])->name('secret')->whereNumber('repo');
Route::get('/{repo}/deployments/{deployment}', [C::class, 'deployment'])->name('deployments.show')->whereNumber(['repo', 'deployment']);
