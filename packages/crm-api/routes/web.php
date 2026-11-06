<?php

use Illuminate\Support\Facades\Route;
use Rhis\CrmApi\Http\LoginLinkController;

Route::get('/crm/login/{token}', LoginLinkController::class)->middleware('throttle:login')->where('token', '[A-Za-z0-9]{64}')->name('crm.login');
