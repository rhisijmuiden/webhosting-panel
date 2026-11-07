<?php

use App\Http\Controllers\Admin\CustomerPasswordController as C;
use Illuminate\Support\Facades\Route;

// Passwords of a customer's logins: choose one, or mail a reset link (loaded from routes/admin.php).
Route::post('customers/{customer}/users/{user}/password/set', [C::class, 'set'])->name('customers.users.password.set');
Route::post('customers/{customer}/users/{user}/password/link', [C::class, 'link'])->name('customers.users.password.link');
