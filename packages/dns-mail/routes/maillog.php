<?php

use Illuminate\Support\Facades\Route;
use Rhis\DnsMail\Http\Controllers\Admin\MailLogController;

// Admin mail log, loaded inside the admin group by MailLogServiceProvider.
Route::get('mail-log', [MailLogController::class, 'index'])->name('mail.log.index');
Route::get('mail-log/export', [MailLogController::class, 'export'])->name('mail.log.export');
Route::put('mail-log/settings', [MailLogController::class, 'settings'])->name('mail.log.settings');
Route::get('mail-log/{message}', [MailLogController::class, 'show'])->whereNumber('message')->name('mail.log.show');
