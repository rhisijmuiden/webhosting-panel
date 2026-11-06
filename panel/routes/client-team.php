<?php

use App\Http\Controllers\Client\TeamController;
use Illuminate\Support\Facades\Route;

// Owner only (TeamGate keeps team members out of client.team.*).
Route::prefix('team')->name('team.')->controller(TeamController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/activity', 'activity')->name('activity');
    Route::get('/invite', 'invite')->name('invite');
    Route::post('/invitations', 'store')->name('invitations.store');
    Route::post('/invitations/{invitation}/resend', 'resend')->name('invitations.resend');
    Route::delete('/invitations/{invitation}', 'revoke')->name('invitations.destroy');
    Route::get('/members/{member}/edit', 'edit')->name('members.edit');
    Route::put('/members/{member}', 'update')->name('members.update');
    Route::delete('/members/{member}', 'destroy')->name('members.destroy');
    Route::put('/settings', 'settings')->name('settings');
});
