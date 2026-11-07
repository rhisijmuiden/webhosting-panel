<?php

use App\Http\Controllers\FtpAccounts\ClientFtpAccountController as C;
use Illuminate\Support\Facades\Route;

// Extra FTP/SFTP accounts per website (client, loaded by FtpAccountsServiceProvider).
Route::prefix('ftp-accounts')->name('ftp-accounts.')->controller(C::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/websites/{website}', 'store')->name('store');
    Route::put('/{account}', 'update')->name('update');
    Route::put('/{account}/password', 'password')->name('password');
    Route::delete('/{account}', 'destroy')->name('destroy');
});
