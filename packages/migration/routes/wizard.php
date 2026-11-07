<?php

use Illuminate\Support\Facades\Route;
use Rhis\Migration\Http\WizardController as W;

// Per-domain migration wizard.
Route::post('/items/{item}/wizard/test-website', [W::class, 'testWebsite'])->name('wizard.test');
Route::post('/items/{item}/wizard/mail', [W::class, 'mailProvider'])->name('wizard.mail');
Route::post('/items/{item}/wizard/zone', [W::class, 'addZone'])->name('wizard.zone');
Route::post('/items/{item}/wizard/switch', [W::class, 'switch'])->name('wizard.switch');
Route::post('/items/{item}/wizard/rollback/{snapshot}', [W::class, 'rollback'])->name('wizard.rollback');
Route::post('/items/{item}/wizard/{step}/confirm', [W::class, 'confirm'])->name('wizard.confirm');
Route::get('/items/{item}/wizard/{step?}', [W::class, 'show'])->name('wizard.show');
