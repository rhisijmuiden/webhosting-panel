<?php

use App\Http\Controllers\Auth\PasswordResetController as C;
use Illuminate\Support\Facades\Route;

// "Forgot password?" on the login page and the link in the reset e-mail. No login needed.
Route::middleware('guest')->group(function () {
    Route::get('/forgot-password', [C::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [C::class, 'email'])->middleware('throttle:password-forgot')->name('password.email');
    Route::get('/reset-password/{token}', [C::class, 'show'])->name('password.reset');
    Route::post('/reset-password', [C::class, 'reset'])->middleware('throttle:password-reset')->name('password.update');
});
