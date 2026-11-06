<?php

use App\Http\Controllers\Client;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', Client\DashboardController::class)->name('dashboard');
Route::get('/websites', [Client\WebsiteController::class, 'index'])->name('websites.index');
Route::get('/websites/{website}', [Client\WebsiteController::class, 'show'])->name('websites.show');
Route::put('/websites/{website}', [Client\WebsiteController::class, 'update'])->name('websites.update');
Route::put('/websites/{website}/php', [Client\WebsiteController::class, 'php'])->name('websites.php');
