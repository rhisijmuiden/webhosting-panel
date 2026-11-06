<?php

use App\Http\Controllers\Admin\QuickController;
use Illuminate\Support\Facades\Route;

// Quick view for a phone: find, check and help a customer (loaded from routes/admin.php).
Route::get('quick', [QuickController::class, 'index'])->name('quick');
Route::get('quick/search', [QuickController::class, 'search'])->name('quick.search');
Route::post('customers/{customer}/suspend', [QuickController::class, 'suspend'])->name('customers.suspend');
Route::post('customers/{customer}/resume', [QuickController::class, 'resume'])->name('customers.resume');
Route::post('customers/{customer}/users/{user}/password', [QuickController::class, 'password'])->name('customers.users.password');
Route::post('customers/{customer}/users/{user}/two-factor', [QuickController::class, 'twoFactor'])->name('customers.users.two-factor');
