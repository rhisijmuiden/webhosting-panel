<?php

use Illuminate\Support\Facades\Route;
use Rhis\DnsMail\Http\Controllers\Admin\MailDomainController;
use Rhis\DnsMail\Http\Controllers\Admin\SettingsController;

Route::get('settings/dns-mail', [SettingsController::class, 'edit'])->name('mail.settings.edit');
Route::put('settings/dns-mail', [SettingsController::class, 'update'])->name('mail.settings.update');
Route::post('settings/dns-mail/test', [SettingsController::class, 'test'])->name('mail.settings.test');

Route::get('domains', [MailDomainController::class, 'index'])->name('mail.domains.index');
Route::get('domains/create', [MailDomainController::class, 'create'])->name('mail.domains.create');
Route::post('domains', [MailDomainController::class, 'store'])->name('mail.domains.store');
Route::delete('domains/{mailDomain}', [MailDomainController::class, 'destroy'])->name('mail.domains.destroy');

$controller = MailDomainController::class;
require __DIR__ . '/shared.php';
