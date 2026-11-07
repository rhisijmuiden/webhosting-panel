<?php

use App\Http\Controllers\FtpAccounts\AdminFtpAccountController as C;
use Illuminate\Support\Facades\Route;

// Extra FTP/SFTP accounts per website (admin, joins routes/admin.php).
Route::prefix('ftp-accounts')->name('ftp-accounts.')->controller(C::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/websites/{website}', 'store')->name('store');
    Route::put('/{account}', 'update')->name('update');
    Route::put('/{account}/password', 'password')->name('password');
    Route::delete('/{account}', 'destroy')->name('destroy');
});
