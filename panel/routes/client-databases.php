<?php

use App\Http\Controllers\Databases\ClientDatabaseController as C;
use Illuminate\Support\Facades\Route;

// Databases, database users and phpMyAdmin sign-on (App\Providers\DatabasesServiceProvider).
Route::prefix('databases')->name('databases.')->group(function () {
    Route::get('/', [C::class, 'index'])->name('index');
    Route::get('/websites/{website}', [C::class, 'show'])->name('show');
    Route::post('/websites/{website}', [C::class, 'store'])->name('store');
    Route::post('/websites/{website}/users', [C::class, 'userStore'])->name('users.store');
    Route::post('/websites/{website}/password', [C::class, 'password'])->name('password');
    Route::delete('/{database}', [C::class, 'destroy'])->name('destroy')->whereNumber('database');
    Route::post('/{database}/phpmyadmin', [C::class, 'phpmyadmin'])->name('phpmyadmin')->whereNumber('database');
    Route::put('/users/{dbuser}', [C::class, 'userUpdate'])->name('users.update')->whereNumber('dbuser');
    Route::delete('/users/{dbuser}', [C::class, 'userDestroy'])->name('users.destroy')->whereNumber('dbuser');
});
