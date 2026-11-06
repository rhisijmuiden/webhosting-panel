<?php

// Domain page routes, loaded inside the admin and the client group.
// The including file sets $controller to its controller class.

use Illuminate\Support\Facades\Route;

$c = $controller;

Route::get('domains/{mailDomain}', [$c, 'show'])->whereNumber('mailDomain')->name('mail.domains.show');
Route::put('domains/{mailDomain}/mail', [$c, 'updateMail'])->name('mail.domains.mail');
Route::post('domains/{mailDomain}/dkim', [$c, 'dkim'])->name('mail.domains.dkim');
Route::post('domains/{mailDomain}/dns/apply', [$c, 'applyDns'])->name('mail.domains.dns.apply');
Route::post('domains/{mailDomain}/dns/records', [$c, 'storeRecord'])->name('mail.domains.dns.records.store');
Route::delete('domains/{mailDomain}/dns/records/{record}', [$c, 'destroyRecord'])->where('record', '[A-Za-z0-9]{1,64}')->name('mail.domains.dns.records.destroy');
Route::post('domains/{mailDomain}/mailboxes', [$c, 'storeMailbox'])->name('mail.domains.mailboxes.store');
Route::put('domains/{mailDomain}/mailboxes/{mailbox}', [$c, 'updateMailbox'])->name('mail.domains.mailboxes.update');
Route::delete('domains/{mailDomain}/mailboxes/{mailbox}', [$c, 'destroyMailbox'])->name('mail.domains.mailboxes.destroy');
Route::get('domains/{mailDomain}/mailboxes/{mailbox}/profile', [$c, 'mobileconfig'])->name('mail.domains.mailboxes.profile');
Route::post('domains/{mailDomain}/aliases', [$c, 'storeAlias'])->name('mail.domains.aliases.store');
Route::delete('domains/{mailDomain}/aliases/{alias}', [$c, 'destroyAlias'])->name('mail.domains.aliases.destroy');
Route::put('domains/{mailDomain}/spam', [$c, 'updateSpam'])->name('mail.domains.spam');
Route::post('domains/{mailDomain}/spam/rules', [$c, 'storeSenderRule'])->name('mail.domains.spam.rules.store');
Route::delete('domains/{mailDomain}/spam/rules/{rule}', [$c, 'destroySenderRule'])->name('mail.domains.spam.rules.destroy');
Route::post('domains/{mailDomain}/quarantine/release', [$c, 'releaseMessage'])->name('mail.domains.quarantine.release');
Route::post('domains/{mailDomain}/quarantine/delete', [$c, 'deleteMessages'])->name('mail.domains.quarantine.delete');
