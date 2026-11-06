<?php

use App\Http\Controllers\Auth\TeamInvitationController;
use Illuminate\Support\Facades\Route;

// The link in the invitation e-mail. Works without a login.
Route::get('/{token}', [TeamInvitationController::class, 'show'])->name('show');
Route::post('/{token}', [TeamInvitationController::class, 'accept'])->name('accept');
