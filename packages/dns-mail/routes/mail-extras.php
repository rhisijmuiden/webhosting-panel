<?php

// Mail extras on the domain page (autoresponder, catch-all, IMAP import),
// loaded inside the admin and the client group by MailExtrasServiceProvider.
// The including code sets $controller to its controller class.

use Illuminate\Support\Facades\Route;

$c = $controller;

Route::put('domains/{mailDomain}/catch-all', [$c, 'catchAll'])->name('mail.domains.catchall');
Route::put('domains/{mailDomain}/mailboxes/{mailbox}/autoreply', [$c, 'autoreply'])->whereNumber('mailbox')->name('mail.domains.autoreply');
Route::post('domains/{mailDomain}/imports', [$c, 'storeImport'])->middleware('throttle:10,1')->name('mail.domains.imports.store');
Route::post('domains/{mailDomain}/imports/test', [$c, 'testImport'])->middleware('throttle:10,1')->name('mail.domains.imports.test');
Route::get('domains/{mailDomain}/imports/{import}', [$c, 'showImport'])->whereNumber('import')->name('mail.domains.imports.show');
Route::post('domains/{mailDomain}/imports/{import}/stop', [$c, 'stopImport'])->whereNumber('import')->name('mail.domains.imports.stop');
Route::post('domains/{mailDomain}/imports/{import}/retry', [$c, 'retryImport'])->whereNumber('import')->middleware('throttle:10,1')->name('mail.domains.imports.retry');
Route::delete('domains/{mailDomain}/imports/{import}', [$c, 'destroyImport'])->whereNumber('import')->name('mail.domains.imports.destroy');
