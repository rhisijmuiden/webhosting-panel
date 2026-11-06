<?php

use Illuminate\Support\Facades\Route;
use Rhis\CrmApi\Http\Admin\ApiSettingsController as C;

Route::get('/', [C::class, 'index'])->name('index');
Route::post('/tokens', [C::class, 'storeToken'])->name('tokens.store');
Route::delete('/tokens/{token}', [C::class, 'revokeToken'])->name('tokens.revoke');
Route::post('/deliveries/{delivery}/retry', [C::class, 'retry'])->name('deliveries.retry');
