<?php

use Illuminate\Support\Facades\Route;
use Rhis\DnsMail\Http\Controllers\Client\MailDomainController;

Route::get('domains', [MailDomainController::class, 'index'])->name('mail.domains.index');

$controller = MailDomainController::class;
require __DIR__ . '/shared.php';
